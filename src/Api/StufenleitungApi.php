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

        // Alle nicht zugeordneten Prüflinge – GoMST-Einträge (mit '|') und Zusatzschüler
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

        // Moodle-Nutzer*innen ohne Schüler*innen-Zuordnung, die keine Lehrkraft-Rolle haben
        $schuelerMoodle = $db->query(
            "SELECT b.id, b.vorname, b.nachname, b.stufe
             FROM benutzer b
             WHERE NOT EXISTS (
                     SELECT 1 FROM kurs_schueler ks WHERE ks.schueler_id = b.id
                   )
               AND NOT EXISTS (
                     SELECT 1 FROM rollen r WHERE r.benutzer_id = b.id AND r.rolle = 'lehrkraft'
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

        // Moodle-Lehrkräfte ohne Kurszuordnung (Rolle 'lehrkraft', egal ob Kürzel vorhanden)
        $lehrkraefteMoodle = $db->query(
            "SELECT b.id, b.vorname, b.nachname, b.kuerzel
             FROM benutzer b
             WHERE EXISTS (
                     SELECT 1 FROM rollen r WHERE r.benutzer_id = b.id AND r.rolle = 'lehrkraft'
                   )
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

    /**
     * Berechnet den Vorschlag für das nächste anzulegende Halbjahr.
     *
     * Logik:
     * – Wenn das neueste Halbjahr noch nicht alle bekannten Stufen enthält:
     *   → gleiches Schuljahr/Abschnitt, fehlende_stufen gefüllt
     * – Sonst: Abschnitt 1 → Abschnitt 2 (gleiches Schuljahr)
     *          Abschnitt 2 → Abschnitt 1 (nächstes Schuljahr)
     *
     * @return array{schuljahr: string, abschnitt: int, fehlende_stufen: list<string>}
     */
    public static function getHalbjahrVorschlag(): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db = Database::getInstance();

        // Alle bekannten Stufennamen (Referenzmenge)
        $alleStufenNamen = $db->query(
            "SELECT DISTINCT name FROM stufen ORDER BY name"
        )->fetchAll(\PDO::FETCH_COLUMN);

        // Neuestes Halbjahr bestimmen
        $neuestes = $db->query(
            "SELECT s.schuljahr, h.abschnitt
             FROM halbjahre h
             JOIN stufen s ON s.id = h.stufe_id
             ORDER BY s.schuljahr DESC, h.abschnitt DESC
             LIMIT 1"
        )->fetch();

        if ($neuestes === false) {
            $year = (int) date('Y');
            $month = (int) date('n');
            $schuljahr = $month >= 8 ? "{$year}/" . ($year + 1) : ($year - 1) . "/{$year}";
            return ['schuljahr' => $schuljahr, 'abschnitt' => 1, 'fehlende_stufen' => []];
        }

        $schuljahr = $neuestes['schuljahr'];
        $abschnitt = (int) $neuestes['abschnitt'];

        // Welche Stufen sind für dieses Schuljahr+Abschnitt bereits vorhanden?
        $stmt = $db->prepare(
            "SELECT s.name FROM halbjahre h
             JOIN stufen s ON s.id = h.stufe_id
             WHERE s.schuljahr = ? AND h.abschnitt = ?"
        );
        $stmt->execute([$schuljahr, $abschnitt]);
        $vorhandene = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $fehlende = array_values(array_diff($alleStufenNamen, $vorhandene));
        sort($fehlende);

        if (!empty($fehlende)) {
            return ['schuljahr' => $schuljahr, 'abschnitt' => $abschnitt, 'fehlende_stufen' => $fehlende];
        }

        // Alle vollständig → nächstes Halbjahr vorschlagen, alle bekannten Stufen voranstellen
        $alleStufen = array_values($alleStufenNamen);
        sort($alleStufen);
        if ($abschnitt === 1) {
            return ['schuljahr' => $schuljahr, 'abschnitt' => 2, 'fehlende_stufen' => $alleStufen];
        }
        [$start, $end] = explode('/', $schuljahr, 2);
        $naechstesSchuljahr = ((int) $start + 1) . '/' . ((int) $end + 1);
        return ['schuljahr' => $naechstesSchuljahr, 'abschnitt' => 1, 'fehlende_stufen' => $alleStufen];
    }

    /**
     * Legt ein neues Halbjahr an. stufe_name darf eine kommagetrennte Liste sein,
     * dann werden mehrere Halbjahre auf einmal angelegt.
     *
     * Body: { stufe_name: string, schuljahr: string, abschnitt: 1|2 }
     * Rückgabe (Einzel): { id, stufe, schuljahr, abschnitt, kurs_anzahl }
     * Rückgabe (Mehrfach): { erstellt: [...], fehler: [...] }
     */
    public static function addHalbjahr(array $body): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db = Database::getInstance();

        $stufeNamen = array_values(array_unique(array_filter(
            array_map(fn(string $s) => strtoupper(trim($s)), explode(',', trim($body['stufe_name'] ?? ''))),
            fn(string $s) => $s !== ''
        )));
        $schuljahr = trim($body['schuljahr'] ?? '');
        $abschnitt = (int) ($body['abschnitt'] ?? 0);

        if (empty($stufeNamen)) {
            http_response_code(400);
            throw new RuntimeException('Stufe darf nicht leer sein.');
        }
        if (!preg_match('/^\d{4}\/\d{4}$/', $schuljahr)) {
            http_response_code(400);
            throw new RuntimeException('Schuljahr muss im Format JJJJ/JJJJ angegeben werden (z.B. 2024/2025).');
        }
        if (!in_array($abschnitt, [1, 2], true)) {
            http_response_code(400);
            throw new RuntimeException('Abschnitt muss 1 oder 2 sein.');
        }

        $benutzerId = Session::getBenutzer()['id'];

        if (count($stufeNamen) === 1) {
            try {
                return self::erstelleHalbjahr($stufeNamen[0], $schuljahr, $abschnitt, $db, $benutzerId);
            } catch (\DomainException $e) {
                http_response_code(409);
                throw new RuntimeException($e->getMessage());
            }
        }

        // Mehrere Stufen: alle verarbeiten, Teilerfolge erlaubt
        $erstellt = [];
        $fehler   = [];
        foreach ($stufeNamen as $stufeName) {
            try {
                $erstellt[] = self::erstelleHalbjahr($stufeName, $schuljahr, $abschnitt, $db, $benutzerId);
            } catch (\DomainException $e) {
                $fehler[] = ['stufe' => $stufeName, 'meldung' => $e->getMessage()];
            }
        }
        http_response_code(200);
        return ['erstellt' => $erstellt, 'fehler' => $fehler];
    }

    /** Legt genau eine Stufe+Halbjahr-Kombination an. Wirft \DomainException bei Duplikat. */
    private static function erstelleHalbjahr(
        string $stufeName,
        string $schuljahr,
        int    $abschnitt,
        \PDO   $db,
        int    $benutzerId
    ): array {
        $stmt = $db->prepare('SELECT id FROM stufen WHERE name = ? AND schuljahr = ?');
        $stmt->execute([$stufeName, $schuljahr]);
        $stufeId   = $stmt->fetchColumn();
        $neueStufe = $stufeId === false;

        if (!$neueStufe) {
            // Prüfe Duplikat bevor wir irgendetwas schreiben
            $stmt = $db->prepare('SELECT id FROM halbjahre WHERE stufe_id = ? AND abschnitt = ?');
            $stmt->execute([$stufeId, $abschnitt]);
            if ($stmt->fetchColumn() !== false) {
                throw new \DomainException("{$stufeName} – {$abschnitt}. Halbjahr {$schuljahr} existiert bereits.");
            }
        }

        if ($neueStufe) {
            $db->prepare('INSERT INTO stufen (name, schuljahr) VALUES (?, ?)')->execute([$stufeName, $schuljahr]);
            $stufeId = (int) $db->lastInsertId();
        }

        $db->prepare(
            'INSERT INTO halbjahre (stufe_id, abschnitt, importiert_von) VALUES (?, ?, ?)'
        )->execute([$stufeId, $abschnitt, $benutzerId]);
        $halbjahrId = (int) $db->lastInsertId();

        if ($neueStufe) {
            self::autoForwardStufenleitung($stufeId, $stufeName, $schuljahr, $db);
        }

        return [
            'id'          => $halbjahrId,
            'stufe'       => $stufeName,
            'schuljahr'   => $schuljahr,
            'abschnitt'   => $abschnitt,
            'kurs_anzahl' => 0,
        ];
    }

    /** Überträgt Stufenleitungen auf eine neue Stufe (EF→Q2, Q1→EF, Q2→Q1 im Vorjahr). */
    private static function autoForwardStufenleitung(int $neueStufeId, string $name, string $schuljahr, \PDO $db): void
    {
        static $vorgaengerMap = ['EF' => 'Q2', 'Q1' => 'EF', 'Q2' => 'Q1'];
        $vorgaengerName = $vorgaengerMap[$name] ?? null;
        if ($vorgaengerName === null) {
            return;
        }

        [$start, $end] = explode('/', $schuljahr, 2);
        $vorgaengerSchuljahr = ((int) $start - 1) . '/' . ((int) $end - 1);

        $stmt = $db->prepare('SELECT id FROM stufen WHERE name = ? AND schuljahr = ?');
        $stmt->execute([$vorgaengerName, $vorgaengerSchuljahr]);
        $vorgaengerStufeId = $stmt->fetchColumn();
        if ($vorgaengerStufeId === false) {
            return;
        }

        $stmt = $db->prepare(
            'SELECT sl.benutzer_id FROM stufenleitungen sl
             JOIN rollen r ON r.benutzer_id = sl.benutzer_id AND r.rolle = \'stufenleitung\'
             WHERE sl.stufe_id = ?'
        );
        $stmt->execute([(int) $vorgaengerStufeId]);

        $ins = $db->prepare('INSERT IGNORE INTO stufenleitungen (benutzer_id, stufe_id) VALUES (?, ?)');
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $benutzerId) {
            $ins->execute([$benutzerId, $neueStufeId]);
        }
    }

    /** Alle Lehrkräfte (für Dropdowns). */
    public static function getLehrkraefte(): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db = Database::getInstance();

        return $db->query(
            "SELECT b.id, b.vorname, b.nachname, b.kuerzel
             FROM benutzer b
             JOIN rollen r ON r.benutzer_id = b.id AND r.rolle = 'lehrkraft'
             ORDER BY b.nachname, b.vorname"
        )->fetchAll();
    }

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
    // Prüflinge eines Kurses (GoMST + manuell hinzugefügte)
    // ------------------------------------------------------------------

    /**
     * Alle Prüflinge eines Kurses – aus GoMST und manuell hinzugefügte.
     * Liefert aufgelöste Namen wenn schueler_id gesetzt ist.
     *
     * @return array<array{id: int, name_roh: string, kursart: string|null, schueler_id: int|null, vorname: string|null, nachname: string|null, ist_zusatz: int}>
     */
    public static function getKursSchueler(int $kursId): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db = Database::getInstance();

        $stmt = $db->prepare('SELECT id FROM kurse WHERE id = ?');
        $stmt->execute([$kursId]);
        if ($stmt->fetchColumn() === false) {
            http_response_code(404);
            throw new RuntimeException("Kurs $kursId nicht gefunden.");
        }

        $stmt = $db->prepare(
            "SELECT ks.id,
                    ks.name_roh,
                    ks.kursart,
                    ks.schueler_id,
                    b.vorname,
                    b.nachname,
                    (ks.name_roh NOT LIKE '%|%') AS ist_zusatz
             FROM kurs_schueler ks
             LEFT JOIN benutzer b ON b.id = ks.schueler_id
             WHERE ks.kurs_id = ?
             ORDER BY ks.name_roh"
        );
        $stmt->execute([$kursId]);
        return $stmt->fetchAll();
    }

    /**
     * Fügt eine Person manuell einem Kurs hinzu.
     *
     * Body: { name: string }
     * @return array{kurs_schueler_id: int, name_roh: string}
     */
    public static function addZusatzSchuelerZuKurs(int $kursId, array $body): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db = Database::getInstance();

        $name = trim($body['name'] ?? '');
        if ($name === '') {
            http_response_code(400);
            throw new RuntimeException('Name darf nicht leer sein.');
        }
        if (strlen($name) > 200) {
            http_response_code(400);
            throw new RuntimeException('Name zu lang (max. 200 Zeichen).');
        }

        $stmt = $db->prepare('SELECT id FROM kurse WHERE id = ?');
        $stmt->execute([$kursId]);
        if ($stmt->fetchColumn() === false) {
            http_response_code(404);
            throw new RuntimeException("Kurs $kursId nicht gefunden.");
        }

        $dup = $db->prepare('SELECT id FROM kurs_schueler WHERE kurs_id = ? AND name_roh = ?');
        $dup->execute([$kursId, $name]);
        if ($dup->fetchColumn() !== false) {
            http_response_code(409);
            throw new RuntimeException('Eine Person mit diesem Namen ist bereits in diesem Kurs eingetragen.');
        }

        $db->prepare(
            'INSERT INTO kurs_schueler (kurs_id, name_roh) VALUES (?, ?)'
        )->execute([$kursId, $name]);

        $ksId = (int) $db->lastInsertId();

        // Automatisches Namensmatching (wie GoMST-Import)
        $schuelerId = self::versucheNamensmatching($db, $name);
        if ($schuelerId !== null) {
            $db->prepare('UPDATE kurs_schueler SET schueler_id = ? WHERE id = ?')
               ->execute([$schuelerId, $ksId]);
        }

        return ['kurs_schueler_id' => $ksId, 'name_roh' => $name, 'schueler_id' => $schuelerId];
    }

    /**
     * Parst einen Anzeigenamen in (nachname, vorname).
     * Formate: "Nachname|Vorname", "Nachname, Vorname", "Vorname Nachname"
     *
     * @return array{0: string, 1: string}
     */
    private static function parseNameZusatz(string $name): array
    {
        if (str_contains($name, '|')) {
            [$n, $v] = array_pad(explode('|', $name, 2), 2, '');
            return [trim($n), trim($v)];
        }
        if (str_contains($name, ',')) {
            $i = strpos($name, ',');
            return [trim(substr($name, 0, $i)), trim(substr($name, $i + 1))];
        }
        $j = strpos(trim($name), ' ');
        if ($j !== false) {
            $parts = trim($name);
            return [trim(substr($parts, $j + 1)), trim(substr($parts, 0, $j))];
        }
        return [trim($name), ''];
    }

    /**
     * Sucht einen passenden Benutzer anhand des Namens.
     * Unterstützt GoMST-Format (Nachname|Vorname), Komma- und Leerzeichen-Trennung.
     */
    private static function versucheNamensmatching(\PDO $db, string $nameRoh): ?int
    {
        [$nachname, $vorname] = self::parseNameZusatz($nameRoh);
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

    /**
     * Löscht einen manuell hinzugefügten Prüfling aus einem Kurs.
     * Schlägt fehl wenn der Eintrag GoMST-Format hat (enthält '|') oder nicht zum Kurs gehört.
     */
    public static function deleteZusatzSchuelerAusKurs(int $kursId, int $ksId): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db = Database::getInstance();

        $stmt = $db->prepare(
            "SELECT id FROM kurs_schueler
             WHERE id = ? AND kurs_id = ? AND name_roh NOT LIKE '%|%'"
        );
        $stmt->execute([$ksId, $kursId]);
        if ($stmt->fetchColumn() === false) {
            http_response_code(404);
            throw new RuntimeException('Zusatzschüler*in nicht gefunden oder gehört nicht zu diesem Kurs.');
        }

        $db->prepare('DELETE FROM kurs_schueler WHERE id = ?')->execute([$ksId]);
        return ['ok' => true];
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
    // Manuelle Kursverwaltung
    // ------------------------------------------------------------------

    /**
     * Legt einen Kurs manuell für ein Halbjahr an.
     *
     * Body: { bezeichnung: string, kursart: 'LK'|'GK', fach_kuerzel?: string, lehrer_kuerzel?: string }
     */
    public static function addKurs(int $halbjahrId, array $body): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db = Database::getInstance();

        $bezeichnung   = trim($body['bezeichnung'] ?? '');
        $kursart       = $body['kursart'] ?? 'GK';
        $fachKuerzel   = strtoupper(trim($body['fach_kuerzel'] ?? ''));
        $lehrerKuerzel = strtoupper(trim($body['lehrer_kuerzel'] ?? '')) ?: null;
        $lehrerIdDirekt = isset($body['lehrer_id']) && (int) $body['lehrer_id'] > 0
            ? (int) $body['lehrer_id'] : null;

        if ($bezeichnung === '') {
            http_response_code(400);
            throw new RuntimeException('Bezeichnung darf nicht leer sein.');
        }
        if (strlen($bezeichnung) > 50) {
            http_response_code(400);
            throw new RuntimeException('Bezeichnung zu lang (max. 50 Zeichen).');
        }
        if (!in_array($kursart, ['LK', 'GK'], true)) {
            http_response_code(400);
            throw new RuntimeException("Ungültige Kursart '$kursart'.");
        }
        if ($fachKuerzel === '') {
            $fachKuerzel = strtoupper(preg_replace('/[\s_].*/', '', $bezeichnung));
            $fachKuerzel = substr($fachKuerzel, 0, 10) ?: '–';
        }

        $stmt = $db->prepare('SELECT id FROM halbjahre WHERE id = ?');
        $stmt->execute([$halbjahrId]);
        if ($stmt->fetchColumn() === false) {
            http_response_code(404);
            throw new RuntimeException("Halbjahr $halbjahrId nicht gefunden.");
        }

        $dup = $db->prepare('SELECT id FROM kurse WHERE halbjahr_id = ? AND kurs_kuerzel = ?');
        $dup->execute([$halbjahrId, $bezeichnung]);
        if ($dup->fetchColumn() !== false) {
            http_response_code(409);
            throw new RuntimeException('Ein Kurs mit dieser Bezeichnung existiert bereits in diesem Halbjahr.');
        }

        // Lehrkraft auflösen: direkte ID hat Vorrang vor Kürzel
        $lehrerId       = null;
        $lehrerVorname  = null;
        $lehrerNachname = null;
        if ($lehrerIdDirekt !== null) {
            $ls = $db->prepare('SELECT id, vorname, nachname, kuerzel FROM benutzer WHERE id = ?');
            $ls->execute([$lehrerIdDirekt]);
            $lehrer = $ls->fetch();
            if ($lehrer !== false) {
                $lehrerId       = (int) $lehrer['id'];
                $lehrerVorname  = $lehrer['vorname'];
                $lehrerNachname = $lehrer['nachname'];
                $lehrerKuerzel  = $lehrer['kuerzel'] ?? $lehrerKuerzel;
            }
        } elseif ($lehrerKuerzel !== null) {
            $ls = $db->prepare(
                'SELECT b.id, b.vorname, b.nachname
                 FROM benutzer b
                 JOIN rollen r ON r.benutzer_id = b.id AND r.rolle = \'lehrkraft\'
                 WHERE UPPER(b.kuerzel) = ?
                 LIMIT 1'
            );
            $ls->execute([$lehrerKuerzel]);
            $lehrer = $ls->fetch();
            if ($lehrer !== false) {
                $lehrerId       = (int) $lehrer['id'];
                $lehrerVorname  = $lehrer['vorname'];
                $lehrerNachname = $lehrer['nachname'];
            }
        }

        $db->prepare(
            'INSERT INTO kurse (halbjahr_id, kurs_kuerzel, fach_kuerzel, kursart, anzeigename, lehrer_kuerzel, lehrer_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$halbjahrId, $bezeichnung, $fachKuerzel, $kursart, $bezeichnung, $lehrerKuerzel, $lehrerId]);

        return [
            'id'                  => (int) $db->lastInsertId(),
            'kurs_kuerzel'        => $bezeichnung,
            'anzeigename'         => $bezeichnung,
            'fach_kuerzel'        => $fachKuerzel,
            'kursart'             => $kursart,
            'lehrer_kuerzel'      => $lehrerKuerzel,
            'lehrer_id'           => $lehrerId,
            'lehrer_vorname'      => $lehrerVorname,
            'lehrer_nachname'     => $lehrerNachname,
            'schueler_gesamt'     => 0,
            'schueler_zugeordnet' => 0,
        ];
    }

    /**
     * Löscht einen Kurs samt aller abhängigen Daten (CASCADE).
     */
    public static function deleteKurs(int $kursId): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db = Database::getInstance();

        $stmt = $db->prepare('SELECT id FROM kurse WHERE id = ?');
        $stmt->execute([$kursId]);
        if ($stmt->fetchColumn() === false) {
            http_response_code(404);
            throw new RuntimeException("Kurs $kursId nicht gefunden.");
        }

        $db->prepare('DELETE FROM kurse WHERE id = ?')->execute([$kursId]);
        return ['ok' => true];
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
