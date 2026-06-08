<?php

declare(strict_types=1);

namespace Klausurplan\Api;

use Klausurplan\Auth\MoodleApi;
use Klausurplan\Models\Database;
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
        $db = Database::getInstance();

        $exists = $db->prepare('SELECT 1 FROM benutzer WHERE id = ?');
        $exists->execute([$benutzerId]);
        if ($exists->fetchColumn() === false) {
            http_response_code(404);
            throw new RuntimeException("Benutzer*in mit ID $benutzerId nicht gefunden.");
        }

        $erlaubteRollen = ['admin', 'stufenleitung', 'lehrkraft', 'schueler'];
        $rollen = array_values(array_unique(
            array_filter($rollen, static fn ($r) => in_array($r, $erlaubteRollen, true))
        ));

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

    public static function deleteFach(string $kuerzel): array
    {
        $db = Database::getInstance();
        $db->prepare('DELETE FROM fach_bezeichnungen WHERE kuerzel = ?')->execute([strtoupper($kuerzel)]);
        return ['ok' => true];
    }

    // ------------------------------------------------------------------
    // Stufen & Stufenleitungen
    // ------------------------------------------------------------------

    /** Alle Stufen (für Stufenleitungs-Zuweisung). */
    public static function getStufen(): array
    {
        $db = Database::getInstance();
        return $db->query(
            'SELECT id, name, schuljahr FROM stufen ORDER BY schuljahr DESC, name'
        )->fetchAll();
    }

    /** Stufen-IDs, für die ein Nutzer Stufenleitung ist. */
    public static function getStufenleitungen(int $benutzerId): array
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare('SELECT stufe_id FROM stufenleitungen WHERE benutzer_id = ?');
        $stmt->execute([$benutzerId]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Setzt die Stufen-Zuordnungen einer Stufenleitung (vollständig ersetzen).
     * Body: { stufen_ids: [1, 2, ...] }
     */
    public static function setStufenleitungen(int $benutzerId, array $stufenIds): array
    {
        $db = Database::getInstance();

        $exists = $db->prepare('SELECT 1 FROM benutzer WHERE id = ?');
        $exists->execute([$benutzerId]);
        if ($exists->fetchColumn() === false) {
            http_response_code(404);
            throw new RuntimeException("Benutzer*in $benutzerId nicht gefunden.");
        }

        $db->prepare('DELETE FROM stufenleitungen WHERE benutzer_id = ?')->execute([$benutzerId]);

        $stufenIds = array_values(array_unique(array_map('intval', $stufenIds)));
        if (!empty($stufenIds)) {
            $platzhalter = implode(',', array_fill(0, count($stufenIds), '(?,?)'));
            $params = [];
            foreach ($stufenIds as $sid) {
                $params[] = $benutzerId;
                $params[] = $sid;
            }
            $db->prepare("INSERT INTO stufenleitungen (benutzer_id, stufe_id) VALUES $platzhalter")
               ->execute($params);
        }

        return ['ok' => true, 'stufen' => $stufenIds];
    }
}
