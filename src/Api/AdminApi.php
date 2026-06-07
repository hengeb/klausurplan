<?php

declare(strict_types=1);

namespace Klausurplan\Api;

use Klausurplan\Auth\MoodleApi;
use Klausurplan\Auth\Session;
use Klausurplan\Models\Database;
use PDO;
use RuntimeException;

class AdminApi
{
    // ------------------------------------------------------------------
    // Benutzer*innen
    // ------------------------------------------------------------------

    public static function getBenutzer(): array
    {
        $db   = Database::getInstance();
        $stmt = $db->query(
            'SELECT b.id, b.moodle_id, b.vorname, b.nachname, b.email,
                    b.kuerzel, b.zuletzt_gesehen,
                    GROUP_CONCAT(r.rolle ORDER BY r.rolle SEPARATOR \',\') AS rollen_csv
             FROM benutzer b
             LEFT JOIN rollen r ON r.benutzer_id = b.id
             GROUP BY b.id
             ORDER BY b.nachname, b.vorname'
        );

        return array_map(static function (array $row): array {
            $row['rollen'] = $row['rollen_csv'] ? explode(',', $row['rollen_csv']) : [];
            unset($row['rollen_csv']);
            return $row;
        }, $stmt->fetchAll());
    }

    public static function setRollen(int $benutzerId, array $rollen): array
    {
        $erlaubteRollen = ['admin', 'stufenleitung', 'lehrkraft', 'schueler'];
        $rollen = array_values(array_unique(
            array_filter($rollen, static fn ($r) => in_array($r, $erlaubteRollen, true))
        ));

        $db = Database::getInstance();
        $db->prepare('DELETE FROM rollen WHERE benutzer_id = ?')->execute([$benutzerId]);

        if (!empty($rollen)) {
            $platzhalter = implode(',', array_fill(0, count($rollen), '(?,?)'));
            $params = [];
            foreach ($rollen as $rolle) {
                $params[] = $benutzerId;
                $params[] = $rolle;
            }
            $db->prepare("INSERT INTO rollen (benutzer_id, rolle) VALUES $platzhalter")->execute($params);
        }

        return ['id' => $benutzerId, 'rollen' => $rollen];
    }

    // ------------------------------------------------------------------
    // Moodle-Sync
    // ------------------------------------------------------------------

    public static function moodleSync(): array
    {
        $api = new MoodleApi();
        return $api->sync();
    }

    // ------------------------------------------------------------------
    // Fächerbezeichnungen
    // ------------------------------------------------------------------

    public static function getFaecher(): array
    {
        $db = Database::getInstance();
        return $db->query(
            'SELECT kuerzel, bezeichnung FROM fach_bezeichnungen ORDER BY kuerzel'
        )->fetchAll();
    }

    public static function updateFach(string $kuerzel, string $bezeichnung): array
    {
        if (empty($kuerzel) || strlen($kuerzel) > 10) {
            throw new RuntimeException('Ungültiges Kürzel.');
        }
        if (empty($bezeichnung)) {
            throw new RuntimeException('Bezeichnung darf nicht leer sein.');
        }

        $db = Database::getInstance();
        $db->prepare(
            'INSERT INTO fach_bezeichnungen (kuerzel, bezeichnung)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE bezeichnung = VALUES(bezeichnung)'
        )->execute([strtoupper($kuerzel), $bezeichnung]);

        return ['kuerzel' => strtoupper($kuerzel), 'bezeichnung' => $bezeichnung];
    }
}
