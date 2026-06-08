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

            // E-Mail nur für Lehrkräfte importieren (erkennbar am Kürzel im Nachnamen)
            $email = $kuerzel !== null ? ($mn['email'] ?? null) : null;

            if (empty($vorname) || empty($nachname)) {
                continue;
            }

            $stmt = $db->prepare('SELECT id FROM benutzer WHERE moodle_id = ?');
            $stmt->execute([$moodleId]);
            $vorhandeneId = $stmt->fetchColumn();

            if ($vorhandeneId === false) {
                $db->prepare(
                    'INSERT INTO benutzer (moodle_id, vorname, nachname, email, kuerzel)
                     VALUES (?, ?, ?, ?, ?)'
                )->execute([$moodleId, $vorname, $nachname, $email, $kuerzel]);
                $neu++;
            } else {
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

        $this->bereinigeDoppelteKuerzel($db);

        return ['neu' => $neu, 'aktualisiert' => $aktualisiert, 'gesamt' => count($moodleNutzer)];
    }

    /**
     * Kommt ein Kürzel mehrfach vor, behält die Person mit der kleinsten Moodle-ID
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
            // Alle Einträge mit diesem Kürzel, ältester zuerst (kleinste numerische Moodle-ID)
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

            // Ersten (kleinste Moodle-ID) behalten, Rest auf NULL
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
            return strtoupper($treffer[1]);
        }

        return null;
    }
}
