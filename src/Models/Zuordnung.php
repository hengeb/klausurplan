<?php

declare(strict_types=1);

namespace Klausurplan\Models;

use PDO;

/**
 * Zuordnung von GOMSTH-Daten zu Benutzer*innen.
 *
 * Manuelle Zuordnungen werden personenbezogen in `schueler_zuordnungen` (GOMSTH-Name)
 * bzw. `lehrer_zuordnungen` (Lehrerkürzel) gespeichert – nicht an Kursen. Sie überleben
 * damit das Löschen von Kursen/Halbjahren und werden beim nächsten Import wieder angewendet.
 * Ein Eintrag mit benutzer_id NULL bedeutet „bewusst nicht zugeordnet“: Dann findet
 * auch kein automatisches Matching mehr statt.
 */
final class Zuordnung
{
    // ------------------------------------------------------------------
    // Schüler*innen
    // ------------------------------------------------------------------

    /**
     * Ermittelt das Moodle-Konto zu einem GOMSTH-Namen: erst die gespeicherte
     * Zuordnung, sonst automatisches Namensmatching.
     */
    public static function ermittleSchuelerId(PDO $db, string $nameRoh): ?int
    {
        $stmt = $db->prepare('SELECT benutzer_id FROM schueler_zuordnungen WHERE name_roh = ?');
        $stmt->execute([$nameRoh]);
        $zeile = $stmt->fetch(PDO::FETCH_NUM);

        if ($zeile !== false) {
            return $zeile[0] !== null ? (int) $zeile[0] : null;
        }

        return self::automatischesNamensmatching($db, $nameRoh);
    }

    /**
     * Ordnet alle noch nicht zugeordneten kurs_schueler-Einträge der Kurse zu
     * (gespeicherte Zuordnung vor automatischem Matching).
     *
     * @param list<int> $kursIds
     */
    public static function ordneSchuelerZu(PDO $db, array $kursIds): void
    {
        if (empty($kursIds)) {
            return;
        }

        $platzhalter = implode(',', array_fill(0, count($kursIds), '?'));
        $stmt = $db->prepare(
            "SELECT id, name_roh FROM kurs_schueler
             WHERE kurs_id IN ($platzhalter) AND schueler_id IS NULL"
        );
        $stmt->execute($kursIds);

        // Pro Name nur einmal nachschlagen
        $idsNachName = [];
        foreach ($stmt->fetchAll() as $ks) {
            $idsNachName[$ks['name_roh']][] = (int) $ks['id'];
        }

        foreach ($idsNachName as $nameRoh => $ksIds) {
            $benutzerId = self::ermittleSchuelerId($db, (string) $nameRoh);
            if ($benutzerId === null) {
                continue;
            }
            $ph = implode(',', array_fill(0, count($ksIds), '?'));
            $db->prepare("UPDATE kurs_schueler SET schueler_id = ? WHERE id IN ($ph)")
               ->execute([$benutzerId, ...$ksIds]);
        }
    }

    /**
     * Speichert eine manuelle Zuordnung dauerhaft und wendet sie auf alle
     * vorhandenen kurs_schueler-Einträge mit diesem Namen an.
     * $benutzerId = null hebt die Zuordnung auf (und sperrt das automatische Matching).
     *
     * @return int Anzahl aktualisierter kurs_schueler-Einträge
     */
    public static function speichereSchuelerZuordnung(PDO $db, string $nameRoh, ?int $benutzerId, ?int $vonBenutzerId): int
    {
        $db->prepare(
            'INSERT INTO schueler_zuordnungen (name_roh, benutzer_id, geaendert_von)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE benutzer_id = VALUES(benutzer_id), geaendert_von = VALUES(geaendert_von)'
        )->execute([$nameRoh, $benutzerId, $vonBenutzerId]);

        $stmt = $db->prepare('UPDATE kurs_schueler SET schueler_id = ? WHERE name_roh = ?');
        $stmt->execute([$benutzerId, $nameRoh]);
        return $stmt->rowCount();
    }

    /**
     * Parst einen Namen in [nachname, vorname].
     * Formate: "Nachname|Vorname" (GOMSTH), "Nachname, Vorname", "Vorname Nachname".
     *
     * @return array{0: string, 1: string}
     */
    public static function parseName(string $name): array
    {
        if (str_contains($name, '|')) {
            [$n, $v] = array_pad(explode('|', $name, 2), 2, '');
            return [trim($n), trim($v)];
        }
        if (str_contains($name, ',')) {
            $i = strpos($name, ',');
            return [trim(substr($name, 0, $i)), trim(substr($name, $i + 1))];
        }
        $name = trim($name);
        $j    = strpos($name, ' ');
        if ($j !== false) {
            return [trim(substr($name, $j + 1)), trim(substr($name, 0, $j))];
        }
        return [$name, ''];
    }

    /**
     * Sucht ein Konto anhand des Namens: Nachname exakt, Vorname exakt oder
     * nur erster Vorname (case-insensitive). Exakte Treffer werden bevorzugt.
     */
    public static function automatischesNamensmatching(PDO $db, string $nameRoh): ?int
    {
        [$nachname, $vorname] = self::parseName($nameRoh);
        if ($nachname === '' && $vorname === '') {
            return null;
        }

        $erstVorname = strtok($vorname, ' ') ?: $vorname;

        $stmt = $db->prepare(
            'SELECT id FROM benutzer
             WHERE LOWER(TRIM(nachname)) = LOWER(?)
               AND (LOWER(TRIM(vorname)) = LOWER(?)
                    OR LOWER(TRIM(SUBSTRING_INDEX(vorname, \' \', 1))) = LOWER(?))
             ORDER BY CASE WHEN LOWER(TRIM(vorname)) = LOWER(?) THEN 0 ELSE 1 END
             LIMIT 1'
        );
        $stmt->execute([$nachname, $vorname, $erstVorname, $vorname]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    // ------------------------------------------------------------------
    // Lehrkräfte
    // ------------------------------------------------------------------

    /**
     * Ermittelt die Lehrkraft zu einem Kürzel: erst die gespeicherte Zuordnung,
     * sonst benutzer.kuerzel (Moodle-Konten vor externen Lehrkräften).
     */
    public static function ermittleLehrerId(PDO $db, string $kuerzel): ?int
    {
        $stmt = $db->prepare('SELECT benutzer_id FROM lehrer_zuordnungen WHERE lehrer_kuerzel = ?');
        $stmt->execute([$kuerzel]);
        $zeile = $stmt->fetch(PDO::FETCH_NUM);

        if ($zeile !== false) {
            return $zeile[0] !== null ? (int) $zeile[0] : null;
        }

        $stmt = $db->prepare(
            'SELECT id FROM benutzer WHERE UPPER(kuerzel) = UPPER(?) ORDER BY extern, id LIMIT 1'
        );
        $stmt->execute([$kuerzel]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    /**
     * Ordnet Kurse ohne Lehrkraft anhand ihres Kürzels zu.
     *
     * @param list<int> $kursIds
     */
    public static function ordneLehrerZu(PDO $db, array $kursIds): void
    {
        if (empty($kursIds)) {
            return;
        }

        $platzhalter = implode(',', array_fill(0, count($kursIds), '?'));
        $stmt = $db->prepare(
            "SELECT id, lehrer_kuerzel FROM kurse
             WHERE id IN ($platzhalter) AND lehrer_kuerzel IS NOT NULL AND lehrer_id IS NULL"
        );
        $stmt->execute($kursIds);

        $idsNachKuerzel = [];
        foreach ($stmt->fetchAll() as $kurs) {
            $idsNachKuerzel[$kurs['lehrer_kuerzel']][] = (int) $kurs['id'];
        }

        foreach ($idsNachKuerzel as $kuerzel => $ids) {
            $lehrerId = self::ermittleLehrerId($db, (string) $kuerzel);
            if ($lehrerId === null) {
                continue;
            }
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $db->prepare("UPDATE kurse SET lehrer_id = ? WHERE id IN ($ph)")
               ->execute([$lehrerId, ...$ids]);
        }
    }

    /**
     * Speichert eine manuelle Kürzel-Zuordnung dauerhaft und wendet sie auf alle
     * Kurse mit diesem Kürzel an. $benutzerId = null hebt sie auf.
     *
     * @return int Anzahl aktualisierter Kurse
     */
    public static function speichereLehrerZuordnung(PDO $db, string $kuerzel, ?int $benutzerId, ?int $vonBenutzerId): int
    {
        $db->prepare(
            'INSERT INTO lehrer_zuordnungen (lehrer_kuerzel, benutzer_id, geaendert_von)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE benutzer_id = VALUES(benutzer_id), geaendert_von = VALUES(geaendert_von)'
        )->execute([$kuerzel, $benutzerId, $vonBenutzerId]);

        $stmt = $db->prepare('UPDATE kurse SET lehrer_id = ? WHERE lehrer_kuerzel = ?');
        $stmt->execute([$benutzerId, $kuerzel]);
        return $stmt->rowCount();
    }
}
