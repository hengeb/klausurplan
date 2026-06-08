<?php

declare(strict_types=1);

namespace Klausurplan\Api;

use Klausurplan\Auth\Session;
use Klausurplan\Import\GomstImporter;
use Klausurplan\Mail\EmailTemplates;
use Klausurplan\Mail\Mailer;
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

        // GoMST-Einträge ohne Moodle-Konto – eine Zeile pro Person (name_roh)
        $schuelerGomst = $db->query(
            "SELECT ks.name_roh,
                    COUNT(DISTINCT ks.id)                                         AS anzahl_kurse,
                    GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ', ')  AS stufen
             FROM kurs_schueler ks
             JOIN kurse k     ON k.id = ks.kurs_id
             JOIN halbjahre h ON h.id = k.halbjahr_id
             JOIN stufen s    ON s.id = h.stufe_id
             WHERE ks.schueler_id IS NULL
             GROUP BY ks.name_roh
             ORDER BY ks.name_roh"
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

        // Personen mit unbekanntem Kürzel – eine Zeile pro Kürzel
        $lehrkraefteKurse = $db->query(
            "SELECT k.lehrer_kuerzel,
                    COUNT(DISTINCT k.id) AS anzahl_kurse
             FROM kurse k
             WHERE k.lehrer_kuerzel IS NOT NULL
               AND k.lehrer_id IS NULL
             GROUP BY k.lehrer_kuerzel
             ORDER BY k.lehrer_kuerzel"
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
     * Speichert eine manuelle Zuordnung – immer personenbezogen, nicht kursbezogen.
     *
     * Body für Schüler*innen:
     *   { "typ": "schueler", "name_roh": "Mustermann|Max", "benutzer_id": 17 }
     *   Aktualisiert ALLE kurs_schueler-Einträge mit diesem name_roh.
     *
     * Body für Lehrkräfte:
     *   { "typ": "lehrkraft", "lehrer_kuerzel": "SZ", "benutzer_id": 23 }
     *   Aktualisiert ALLE Kurse mit diesem lehrer_kuerzel.
     */
    public static function postZuordnung(array $body): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db  = Database::getInstance();
        $typ = $body['typ'] ?? '';

        if ($typ === 'schueler') {
            $nameRoh    = $body['name_roh'] ?? '';
            $benutzerId = isset($body['benutzer_id']) && $body['benutzer_id'] !== null
                ? (int) $body['benutzer_id']
                : null;

            if ($nameRoh === '') {
                http_response_code(400);
                throw new RuntimeException('name_roh fehlt.');
            }

            $stmt = $db->prepare('UPDATE kurs_schueler SET schueler_id = ? WHERE name_roh = ?');
            $stmt->execute([$benutzerId, $nameRoh]);

            return ['ok' => true, 'aktualisiert' => $stmt->rowCount()];
        }

        if ($typ === 'lehrkraft') {
            $lehrerKuerzel = $body['lehrer_kuerzel'] ?? '';
            $benutzerId    = isset($body['benutzer_id']) && $body['benutzer_id'] !== null
                ? (int) $body['benutzer_id']
                : null;

            if ($lehrerKuerzel === '') {
                http_response_code(400);
                throw new RuntimeException('lehrer_kuerzel fehlt.');
            }

            $stmt = $db->prepare('UPDATE kurse SET lehrer_id = ? WHERE lehrer_kuerzel = ?');
            $stmt->execute([$benutzerId, $lehrerKuerzel]);

            return ['ok' => true, 'aktualisiert' => $stmt->rowCount()];
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

    // ------------------------------------------------------------------
    // E-Mail manuell auslösen
    // ------------------------------------------------------------------

    /**
     * Sendet eine Anwesenheits-E-Mail für eine Klausur manuell.
     * Erzeugt immer einen neuen Token (unabhängig von bereits gesendeten Mails).
     *
     * @return array{gesendet: bool, empfaenger: string}
     */
    public static function emailAusloesen(int $klausurId): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db = Database::getInstance();

        $stmt = $db->prepare(
            "SELECT kl.id, kl.termin_datum, k.anzeigename AS kurs_anzeigename,
                    b.id AS lehrer_id, b.email, b.vorname, b.nachname
             FROM klausuren kl
             JOIN kurse k    ON k.id = kl.kurs_id
             JOIN benutzer b ON b.id = k.lehrer_id
             WHERE kl.id = ?"
        );
        $stmt->execute([$klausurId]);
        $kl = $stmt->fetch();

        if ($kl === false) {
            http_response_code(404);
            throw new RuntimeException("Klausur {$klausurId} nicht gefunden oder keine Lehrkraft zugeordnet.");
        }

        if (empty($kl['email'])) {
            http_response_code(422);
            throw new RuntimeException('Die zugeordnete Lehrkraft hat keine E-Mail-Adresse hinterlegt.');
        }

        $token = bin2hex(random_bytes(32));

        $klausurDaten = [
            'kurs_anzeigename' => $kl['kurs_anzeigename'],
            'termin_datum'     => $kl['termin_datum'],
        ];

        $datumStr = $kl['termin_datum']
            ? date('d.m.Y', strtotime($kl['termin_datum']))
            : '–';
        $betreff  = "Anwesenheit Klausur {$kl['kurs_anzeigename']} am {$datumStr}";

        $db->prepare(
            "INSERT INTO email_benachrichtigungen
             (klausur_id, empfaenger_id, typ, token, gesendet_am)
             VALUES (?, ?, 'erstmeldung', ?, NOW())"
        )->execute([$klausurId, $kl['lehrer_id'], $token]);

        Mailer::send(
            $kl['email'],
            trim($kl['vorname'] . ' ' . $kl['nachname']),
            $betreff,
            EmailTemplates::erstmeldung($klausurDaten, $token),
        );

        return [
            'gesendet'   => true,
            'empfaenger' => $kl['email'],
        ];
    }

    // ------------------------------------------------------------------

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

    // ------------------------------------------------------------------
    // Daten löschen
    // ------------------------------------------------------------------

    /**
     * Löscht ein Halbjahr samt aller abhängigen Daten (CASCADE).
     *
     * @return array{ok: bool}
     */
    public static function deleteHalbjahr(int $halbjahrId): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db = Database::getInstance();

        $stmt = $db->prepare('SELECT id FROM halbjahre WHERE id = ?');
        $stmt->execute([$halbjahrId]);
        if ($stmt->fetchColumn() === false) {
            http_response_code(404);
            throw new RuntimeException("Halbjahr {$halbjahrId} nicht gefunden.");
        }

        $db->prepare('DELETE FROM halbjahre WHERE id = ?')->execute([$halbjahrId]);

        return ['ok' => true];
    }
}
