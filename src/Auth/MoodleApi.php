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
     * @return array{neu: int, aktualisiert: int, gesamt: int}
     */
    public function sync(): array
    {
        $moodleNutzer = $this->alleNutzer();
        $db  = Database::getInstance();
        $neu = $aktualisiert = 0;

        foreach ($moodleNutzer as $mn) {
            $moodleId = (string) $mn['id'];
            $vorname  = trim($mn['firstname'] ?? '');
            $nachname = trim($mn['lastname']  ?? '');
            $kuerzel  = self::extraktKuerzel($nachname);

            // E-Mail nur für Lehrkräfte importieren; leerer String = nicht öffentlich = null
            $emailRaw = $mn['email'] ?? '';
            $email    = ($kuerzel !== null && !empty($emailRaw)) ? $emailRaw : null;

            if (empty($vorname) || empty($nachname)) {
                continue;
            }

            $stmt = $db->prepare(
                'SELECT vorname, nachname, email, kuerzel FROM benutzer WHERE moodle_id = ?'
            );
            $stmt->execute([$moodleId]);
            $vorhandener = $stmt->fetch();

            if ($vorhandener === false) {
                $db->prepare(
                    'INSERT INTO benutzer (moodle_id, vorname, nachname, email, kuerzel)
                     VALUES (?, ?, ?, ?, ?)'
                )->execute([$moodleId, $vorname, $nachname, $email, $kuerzel]);
                $neu++;
            } else {
                $geandert = $vorhandener['vorname'] !== $vorname
                    || $vorhandener['nachname'] !== $nachname
                    || $vorhandener['email'] !== $email
                    || ($kuerzel !== null && $vorhandener['kuerzel'] !== $kuerzel);

                if ($geandert) {
                    $db->prepare(
                        'UPDATE benutzer
                         SET vorname  = ?,
                             nachname = ?,
                             email    = ?,
                             kuerzel  = CASE WHEN ? IS NOT NULL THEN ? ELSE kuerzel END
                         WHERE moodle_id = ?'
                    )->execute([$vorname, $nachname, $email, $kuerzel, $kuerzel, $moodleId]);
                    $aktualisiert++;
                }
            }
        }

        $this->bereinigeDoppelteKuerzel($db);

        return ['neu' => $neu, 'aktualisiert' => $aktualisiert, 'gesamt' => count($moodleNutzer)];
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
     * Für Lehrkräfte (erkennbar am Kürzel) werden E-Mails per core_user_get_users_by_field
     * nachgeladen, da core_user_get_users die E-Mail-Privatsphäre der Nutzer*innen respektiert.
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

        // E-Mails für Lehrkräfte (mit Kürzel) gezielt nachladen
        $lehrkraftIds = array_keys(array_filter(
            $nutzer,
            fn($u) => self::extraktKuerzel(trim($u['lastname'] ?? '')) !== null
        ));

        if (!empty($lehrkraftIds)) {
            foreach ($this->emailsNachladen($lehrkraftIds) as $id => $email) {
                if (isset($nutzer[$id])) {
                    $nutzer[$id]['email'] = $email;
                }
            }
        }

        return array_values($nutzer);
    }

    /**
     * Lädt E-Mails für die angegebenen Moodle-User-IDs via core_user_get_users_by_field.
     * Diese Funktion respektiert die E-Mail-Privatsphäre-Einstellung nicht.
     *
     * @param  int[]  $ids
     * @return array<int, string>  Moodle-ID → E-Mail
     */
    private function emailsNachladen(array $ids): array
    {
        $emails  = [];

        foreach (array_chunk($ids, 100) as $batch) {
            $params = [
                'wstoken'            => $this->token,
                'wsfunction'         => 'core_user_get_users_by_field',
                'moodlewsrestformat' => 'json',
                'field'              => 'id',
            ];
            foreach ($batch as $i => $id) {
                $params["values[$i]"] = $id;
            }

            $url  = $this->baseUrl . '/webservice/rest/server.php?' . http_build_query($params);
            $data = $this->get($url);

            // Fehler beim Nachladen ignorieren – E-Mail bleibt dann leer
            if (isset($data['exception'])) {
                break;
            }

            foreach ($data as $user) {
                if (!empty($user['email'])) {
                    $emails[(int) $user['id']] = $user['email'];
                }
            }
        }

        return $emails;
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
            return strtoupper($treffer[1]);
        }

        return null;
    }
}
