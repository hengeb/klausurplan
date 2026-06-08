<?php

declare(strict_types=1);

namespace Klausurplan\Auth;

use Klausurplan\Models\Database;
use RuntimeException;

class MoodleApi
{
    private string $baseUrl;
    private string $token;

    public function __construct()
    {
        $this->baseUrl = rtrim($_ENV['MOODLE_URL'] ?? '', '/');
        $this->token   = $_ENV['MOODLE_API_TOKEN'] ?? '';

        if (empty($this->baseUrl) || empty($this->token)) {
            throw new RuntimeException('MOODLE_URL oder MOODLE_API_TOKEN nicht konfiguriert.');
        }
    }

    /**
     * Holt alle aktiven Nutzer*innen aus Moodle (ldap + manual Auth)
     * und aktualisiert die lokale benutzer-Tabelle.
     *
     * @return array{neu: int, aktualisiert: int, geloescht: int, gesamt: int}
     */
    public function sync(): array
    {
        $moodleNutzer = $this->alleNutzer();
        $db  = Database::getInstance();
        $neu = $aktualisiert = 0;

        $geseheneMoodleIds = [];

        foreach ($moodleNutzer as $mn) {
            $moodleId     = (string) $mn['id'];
            $vorname      = trim($mn['firstname'] ?? '');
            $nachname     = trim($mn['lastname']  ?? '');
            $istLehrkraft = self::istLehrkraft($mn);

            // Kürzel nur für Lehrkräfte extrahieren (ein "(AB)" im Schülernamen ist kein Kürzel)
            $kuerzel = $istLehrkraft ? self::extraktKuerzel($nachname) : null;

            // Stufe nur für Schüler*innen (alphanumerisches Präfix aus dem klasse-Feld)
            $stufe = !$istLehrkraft ? self::extraktStufe($mn) : null;

            // E-Mail nur für Lehrkräfte importieren
            $emailRaw = $mn['email'] ?? '';
            $email    = ($istLehrkraft && !empty($emailRaw)) ? $emailRaw : null;

            if (empty($vorname) || empty($nachname)) {
                continue;
            }

            $geseheneMoodleIds[] = $moodleId;

            $stmt = $db->prepare(
                'SELECT id, vorname, nachname, email, kuerzel, stufe FROM benutzer WHERE moodle_id = ?'
            );
            $stmt->execute([$moodleId]);
            $vorhandener = $stmt->fetch();

            if ($vorhandener === false) {
                $db->prepare(
                    'INSERT INTO benutzer (moodle_id, vorname, nachname, email, kuerzel, stufe)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([$moodleId, $vorname, $nachname, $email, $kuerzel, $stufe]);
                $benutzerId = (int) $db->lastInsertId();
                $neu++;

                // Basis-Rolle automatisch setzen
                $basisRolle = $istLehrkraft ? 'lehrkraft' : 'schueler';
                $db->prepare('INSERT IGNORE INTO rollen (benutzer_id, rolle) VALUES (?, ?)')
                   ->execute([$benutzerId, $basisRolle]);
            } else {
                $benutzerId = (int) $vorhandener['id'];
                $geandert   = $vorhandener['vorname'] !== $vorname
                    || $vorhandener['nachname'] !== $nachname
                    || $vorhandener['email'] !== $email
                    || ($kuerzel !== null && $vorhandener['kuerzel'] !== $kuerzel)
                    || $vorhandener['stufe'] !== $stufe;

                if ($geandert) {
                    $db->prepare(
                        'UPDATE benutzer
                         SET vorname  = ?,
                             nachname = ?,
                             email    = ?,
                             kuerzel  = CASE WHEN ? IS NOT NULL THEN ? ELSE kuerzel END,
                             stufe    = ?
                         WHERE moodle_id = ?'
                    )->execute([$vorname, $nachname, $email, $kuerzel, $kuerzel, $stufe, $moodleId]);
                    $aktualisiert++;
                }

                // Lehrkräfte korrigieren: falls durch LTI fälschlich als schueler angelegt,
                // wird die Rolle auf lehrkraft geändert. Schüler*innen werden nicht angefasst.
                if ($istLehrkraft) {
                    $db->prepare('INSERT IGNORE INTO rollen (benutzer_id, rolle) VALUES (?, ?)')->execute([$benutzerId, 'lehrkraft']);
                    $db->prepare('DELETE FROM rollen WHERE benutzer_id = ? AND rolle = ?')->execute([$benutzerId, 'schueler']);
                }
            }
        }

        $this->bereinigeDoppelteKuerzel($db);

        // Nicht mehr in Moodle vorhandene und nicht referenzierte Nutzer*innen löschen
        $geloescht = $this->loescheVeraltet($db, $geseheneMoodleIds);

        return ['neu' => $neu, 'aktualisiert' => $aktualisiert, 'geloescht' => $geloescht, 'gesamt' => count($moodleNutzer)];
    }

    /**
     * Löscht lokale Benutzer*innen, die nicht mehr in Moodle vorhanden sind
     * und an keiner Klausur mehr beteiligt sind (kurs_schueler.schueler_id, kurse.lehrer_id).
     */
    private function loescheVeraltet(\PDO $db, array $geseheneMoodleIds): int
    {
        if (empty($geseheneMoodleIds)) {
            return 0;
        }

        $platzhalter = implode(',', array_fill(0, count($geseheneMoodleIds), '?'));

        $stmt = $db->prepare(
            "DELETE FROM benutzer
             WHERE moodle_id NOT IN ($platzhalter)
               AND id NOT IN (SELECT schueler_id FROM kurs_schueler WHERE schueler_id IS NOT NULL)
               AND id NOT IN (SELECT lehrer_id   FROM kurse           WHERE lehrer_id   IS NOT NULL)"
        );
        $stmt->execute($geseheneMoodleIds);

        return $stmt->rowCount();
    }

    /**
     * Kommt ein Kürzel mehrfach vor, behält die Person mit der größten Moodle-ID
     * das Kürzel; bei allen anderen wird es auf NULL gesetzt.
     */
    private function bereinigeDoppelteKuerzel(\PDO $db): void
    {
        $stmt = $db->query(
            'SELECT kuerzel FROM benutzer
             WHERE kuerzel IS NOT NULL
             GROUP BY kuerzel HAVING COUNT(*) > 1'
        );

        while (($kuerzel = $stmt->fetchColumn()) !== false) {
            // Alle Einträge mit diesem Kürzel, neuester zuerst (größte numerische Moodle-ID)
            $dup = $db->prepare(
                'SELECT id FROM benutzer
                 WHERE kuerzel = ?
                 ORDER BY CAST(moodle_id AS UNSIGNED) DESC'
            );
            $dup->execute([$kuerzel]);

            $ids = [];
            while (($id = $dup->fetchColumn()) !== false) {
                $ids[] = $id;
            }

            // Ersten (größte Moodle-ID) behalten, Rest auf NULL
            array_shift($ids);
            if (!empty($ids)) {
                $platzhalter = implode(',', array_fill(0, count($ids), '?'));
                $db->prepare("UPDATE benutzer SET kuerzel = NULL WHERE id IN ($platzhalter)")
                   ->execute($ids);
            }
        }
    }

    /**
     * Gibt alle Nutzer*innen aus Moodle zurück (ldap + manual, dedupliziert nach ID).
     * E-Mails, die Nutzer*innen in Moodle als privat markiert haben, werden nicht
     * zurückgegeben – sie werden stattdessen beim LTI-Login aus dem JWT-Claim befüllt.
     */
    private function alleNutzer(): array
    {
        $nutzer = [];

        foreach (['ldap', 'manual'] as $auth) {
            $params = [
                'wstoken'              => $this->token,
                'wsfunction'           => 'core_user_get_users',
                'moodlewsrestformat'   => 'json',
                'criteria[0][key]'     => 'auth',
                'criteria[0][value]'   => $auth,
            ];

            $url  = $this->baseUrl . '/webservice/rest/server.php?' . http_build_query($params);
            $data = $this->get($url);

            if (!empty($data['exception'])) {
                throw new RuntimeException('Moodle API: ' . ($data['message'] ?? $data['exception']));
            }

            foreach ($data['users'] ?? [] as $u) {
                $nutzer[$u['id']] = $u;
            }
        }

        return array_values($nutzer);
    }

    private function get(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);

        if ($body === false) {
            throw new RuntimeException('Moodle API nicht erreichbar: ' . $error);
        }
        if ($status !== 200) {
            throw new RuntimeException("Moodle API HTTP-Fehler: $status");
        }

        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    public static function extraktKuerzel(string $nachname): ?string
    {
        if (preg_match('/\(([A-ZÄÖÜa-zäöü]{1,10})\)$/', trim($nachname), $treffer)) {
            return $treffer[1];
        }

        return null;
    }

    /**
     * Prüft anhand des Moodle-Customfields "klasse", ob es sich um eine Lehrkraft handelt.
     */
    private static function istLehrkraft(array $moodleUser): bool
    {
        foreach ($moodleUser['customfields'] ?? [] as $field) {
            if (($field['shortname'] ?? '') === 'klasse') {
                return ($field['value'] ?? '') === 'Lehrkraft';
            }
        }

        return false;
    }

    /**
     * Extrahiert die Stufe aus dem Moodle-Customfield "klasse" (z.B. "EF", "Q1", "Q2").
     * Nur das führende alphanumerische Präfix wird verwendet ("Q1 Kurs 3" → "Q1").
     */
    private static function extraktStufe(array $moodleUser): ?string
    {
        foreach ($moodleUser['customfields'] ?? [] as $field) {
            if (($field['shortname'] ?? '') === 'klasse') {
                $value = trim($field['value'] ?? '');
                if ($value !== '' && preg_match('/^[A-Za-z0-9]+/', $value, $treffer)) {
                    return $treffer[0];
                }
            }
        }

        return null;
    }
}
