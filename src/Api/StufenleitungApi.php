<?php

declare(strict_types=1);

namespace Klausurplan\Api;

use Klausurplan\Auth\Session;
use Klausurplan\Import\GomstImporter;
use Klausurplan\Models\Database;
use RuntimeException;

class StufenleitungApi
{
    // ------------------------------------------------------------------
    // GoMST-Import
    // ------------------------------------------------------------------

    /**
     * Verarbeitet einen GoMST-Datei-Upload (multipart/form-data, Feld "datei").
     *
     * @return array{kurse: int, schueler: int, entfernt: int, halbjahre: int}
     */
    public static function gomstImport(): array
    {
        Session::requireRolle('admin', 'stufenleitung');

        if (!isset($_FILES['datei']) || $_FILES['datei']['error'] !== UPLOAD_ERR_OK) {
            $fehlerCode = $_FILES['datei']['error'] ?? -1;
            http_response_code(400);
            throw new RuntimeException("Keine gültige Datei hochgeladen (Fehlercode: $fehlerCode).");
        }

        $inhalt = file_get_contents($_FILES['datei']['tmp_name']);
        if ($inhalt === false || $inhalt === '') {
            throw new RuntimeException('Datei konnte nicht gelesen werden oder ist leer.');
        }

        $benutzer = Session::getBenutzer();
        $importer = new GomstImporter((int) $benutzer['id']);
        return $importer->importiere($inhalt);
    }

    // ------------------------------------------------------------------
    // Zuordnungen abrufen
    // ------------------------------------------------------------------

    /**
     * Liefert alle nicht zugeordneten Einträge für die manuelle Zuordnungs-UI:
     * - Schüler*innen aus GoMST ohne Moodle-Konto-Match
     * - Moodle-Nutzer*innen ohne Kurszuordnung
     * - Kurse ohne Lehrkraft-Zuordnung
     * - Lehrkräfte ohne Kurszuordnung
     */
    public static function getZuordnungen(): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db = Database::getInstance();

        // GoMST-Einträge ohne Moodle-Konto
        $schuelerGomst = $db->query(
            "SELECT ks.id,
                    ks.name_roh,
                    k.id          AS kurs_id,
                    k.anzeigename AS kurs,
                    s.name        AS stufe,
                    s.schuljahr,
                    h.abschnitt
             FROM kurs_schueler ks
             JOIN kurse k          ON k.id = ks.kurs_id
             JOIN halbjahre h      ON h.id = k.halbjahr_id
             JOIN stufen s         ON s.id = h.stufe_id
             WHERE ks.schueler_id IS NULL
             ORDER BY s.schuljahr DESC, s.name, h.abschnitt, ks.name_roh"
        )->fetchAll();

        // Moodle-Nutzer*innen ohne Schüler*innen-Zuordnung (kein Kürzel → kein Lehrer)
        $schuelerMoodle = $db->query(
            "SELECT b.id, b.vorname, b.nachname, b.stufe
             FROM benutzer b
             WHERE b.kuerzel IS NULL
               AND NOT EXISTS (
                   SELECT 1 FROM kurs_schueler ks WHERE ks.schueler_id = b.id
               )
             ORDER BY b.nachname, b.vorname"
        )->fetchAll();

        // Kurse ohne Lehrkraft-Zuordnung (aber mit Kürzel aus GoMST)
        $lehrkraefteKurse = $db->query(
            "SELECT DISTINCT
                    k.id,
                    k.lehrer_kuerzel,
                    k.anzeigename,
                    s.name        AS stufe,
                    s.schuljahr,
                    h.abschnitt
             FROM kurse k
             JOIN halbjahre h ON h.id = k.halbjahr_id
             JOIN stufen s    ON s.id = h.stufe_id
             WHERE k.lehrer_kuerzel IS NOT NULL
               AND k.lehrer_id IS NULL
             ORDER BY k.lehrer_kuerzel, k.anzeigename"
        )->fetchAll();

        // Moodle-Lehrkräfte ohne Kurszuordnung
        $lehrkraefteMoodle = $db->query(
            "SELECT b.id, b.vorname, b.nachname, b.kuerzel
             FROM benutzer b
             WHERE b.kuerzel IS NOT NULL
               AND NOT EXISTS (
                   SELECT 1 FROM kurse k WHERE k.lehrer_id = b.id
               )
             ORDER BY b.nachname, b.vorname"
        )->fetchAll();

        return [
            'schueler_gomst'     => $schuelerGomst,
            'schueler_moodle'    => $schuelerMoodle,
            'lehrkraefte_kurse'  => $lehrkraefteKurse,
            'lehrkraefte_moodle' => $lehrkraefteMoodle,
        ];
    }

    // ------------------------------------------------------------------
    // Manuelle Zuordnung speichern
    // ------------------------------------------------------------------

    /**
     * Speichert eine manuelle Zuordnung.
     *
     * Body für Schüler*innen:
     *   { "typ": "schueler", "kurs_schueler_id": 42, "benutzer_id": 17 }
     *
     * Body für Lehrkräfte:
     *   { "typ": "lehrkraft", "kurs_id": 5, "benutzer_id": 23 }
     *
     * Zuordnung entfernen (schueler_id auf NULL):
     *   { "typ": "schueler", "kurs_schueler_id": 42, "benutzer_id": null }
     */
    public static function postZuordnung(array $body): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db  = Database::getInstance();
        $typ = $body['typ'] ?? '';

        if ($typ === 'schueler') {
            $kursSchuelerId = (int) ($body['kurs_schueler_id'] ?? 0);
            $benutzerId     = isset($body['benutzer_id']) && $body['benutzer_id'] !== null
                ? (int) $body['benutzer_id']
                : null;

            if ($kursSchuelerId === 0) {
                http_response_code(400);
                throw new RuntimeException('kurs_schueler_id fehlt.');
            }

            $db->prepare('UPDATE kurs_schueler SET schueler_id = ? WHERE id = ?')
               ->execute([$benutzerId, $kursSchuelerId]);

            return ['ok' => true];
        }

        if ($typ === 'lehrkraft') {
            $kursId     = (int) ($body['kurs_id'] ?? 0);
            $benutzerId = isset($body['benutzer_id']) && $body['benutzer_id'] !== null
                ? (int) $body['benutzer_id']
                : null;

            if ($kursId === 0) {
                http_response_code(400);
                throw new RuntimeException('kurs_id fehlt.');
            }

            $db->prepare('UPDATE kurse SET lehrer_id = ? WHERE id = ?')
               ->execute([$benutzerId, $kursId]);

            return ['ok' => true];
        }

        http_response_code(400);
        throw new RuntimeException("Unbekannter Typ '$typ'. Erwartet: 'schueler' oder 'lehrkraft'.");
    }

    // ------------------------------------------------------------------
    // Halbjahre und Kurse für die Übersicht
    // ------------------------------------------------------------------

    /** Alle Halbjahre mit Stufeninformationen (für Dropdown/Navigation). */
    public static function getHalbjahre(): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db = Database::getInstance();

        return $db->query(
            "SELECT h.id,
                    h.abschnitt,
                    h.importiert_am,
                    s.name        AS stufe,
                    s.schuljahr,
                    COUNT(k.id)   AS kurs_anzahl
             FROM halbjahre h
             JOIN stufen s  ON s.id  = h.stufe_id
             LEFT JOIN kurse k ON k.halbjahr_id = h.id
             GROUP BY h.id
             ORDER BY s.schuljahr DESC, s.name, h.abschnitt"
        )->fetchAll();
    }

    /** Kursliste für ein Halbjahr inkl. Schüler*innen-Anzahl und Zuordnungsstatus. */
    public static function getKurse(int $halbjahrId): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db = Database::getInstance();

        $kurse = $db->prepare(
            "SELECT k.id,
                    k.kurs_kuerzel,
                    k.anzeigename,
                    k.fach_kuerzel,
                    k.kursart,
                    k.lehrer_kuerzel,
                    k.lehrer_id,
                    b.vorname      AS lehrer_vorname,
                    b.nachname     AS lehrer_nachname,
                    COUNT(ks.id)   AS schueler_gesamt,
                    SUM(CASE WHEN ks.schueler_id IS NOT NULL THEN 1 ELSE 0 END) AS schueler_zugeordnet
             FROM kurse k
             LEFT JOIN benutzer b   ON b.id  = k.lehrer_id
             LEFT JOIN kurs_schueler ks ON ks.kurs_id = k.id
             WHERE k.halbjahr_id = ?
             GROUP BY k.id
             ORDER BY k.anzeigename"
        );
        $kurse->execute([$halbjahrId]);

        return $kurse->fetchAll();
    }
}
