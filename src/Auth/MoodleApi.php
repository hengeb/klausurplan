<?php

declare(strict_types=1);

namespace Klausurplan\Auth;

use Klausurplan\Models\Database;
use PDO;
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
            $email    = $mn['email']           ?? null;
            $kuerzel  = self::extraktKuerzel($nachname);

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
                // E-Mail und Kürzel nur überschreiben wenn in Moodle vorhanden
                $db->prepare(
                    'UPDATE benutzer
                     SET vorname  = ?,
                         nachname = ?,
                         email    = CASE WHEN ? IS NOT NULL THEN ? ELSE email    END,
                         kuerzel  = CASE WHEN ? IS NOT NULL THEN ? ELSE kuerzel  END
                     WHERE moodle_id = ?'
                )->execute([$vorname, $nachname, $email, $email, $kuerzel, $kuerzel, $moodleId]);
                $aktualisiert++;
            }
        }

        return ['neu' => $neu, 'aktualisiert' => $aktualisiert, 'gesamt' => count($moodleNutzer)];
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
        curl_close($ch);

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
