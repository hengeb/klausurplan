<?php

declare(strict_types=1);

namespace Klausurplan\Api;

use Klausurplan\Auth\Session;
use Klausurplan\Import\KlausurPasteParser;
use Klausurplan\Models\Database;
use RuntimeException;

class LehrkraftApi
{
    // ------------------------------------------------------------------
    // Klausuren – Lesen
    // ------------------------------------------------------------------

    /**
     * Gibt Klausuren zurück.
     * Admin/Stufenleitung: alle. Lehrkraft: nur eigene Kurse.
     * Optionaler Query-Parameter: ?halbjahr_id=X
     */
    public static function getKlausuren(): array
    {
        Session::requireRolle('admin', 'stufenleitung', 'lehrkraft');
        $db      = Database::getInstance();
        $benutzer = Session::getBenutzer();
        $rollen  = $benutzer['rollen'] ?? [];

        $nurEigene = !in_array('admin', $rollen, true)
                  && !in_array('stufenleitung', $rollen, true);

        $halbjahrId = isset($_GET['halbjahr_id']) ? (int) $_GET['halbjahr_id'] : null;

        $where  = [];
        $params = [];

        if ($nurEigene) {
            $where[]  = 'kurs.lehrer_id = ?';
            $params[] = $benutzer['id'];
        }

        if ($halbjahrId !== null) {
            $where[]  = 'kurs.halbjahr_id = ?';
            $params[] = $halbjahrId;
        }

        $bedingung = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $stmt = $db->prepare(
            "SELECT k.id,
                    k.klausur_nr,
                    k.termin_datum,
                    k.termin_uhrzeit,
                    k.dauer_minuten,
                    k.raum,
                    k.erstellt_am,
                    kurs.id           AS kurs_id,
                    kurs.kurs_kuerzel,
                    kurs.anzeigename  AS kurs_anzeigename,
                    kurs.kursart,
                    s.name            AS stufe,
                    s.schuljahr,
                    h.id              AS halbjahr_id,
                    h.abschnitt,
                    lb.id             AS lehrer_id,
                    lb.vorname        AS lehrer_vorname,
                    lb.nachname       AS lehrer_nachname,
                    lb.kuerzel        AS lehrer_kuerzel,
                    (SELECT COUNT(*) FROM kurs_schueler ks WHERE ks.kurs_id = kurs.id) AS schueler_anzahl
             FROM klausuren k
             JOIN kurse kurs  ON kurs.id = k.kurs_id
             JOIN halbjahre h ON h.id    = kurs.halbjahr_id
             JOIN stufen s    ON s.id    = h.stufe_id
             LEFT JOIN benutzer lb ON lb.id = kurs.lehrer_id
             $bedingung
             ORDER BY k.termin_datum IS NULL, k.termin_datum, k.termin_uhrzeit, kurs.anzeigename"
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    // ------------------------------------------------------------------
    // Klausuren – Anlegen (einzeln)
    // ------------------------------------------------------------------

    /**
     * Legt eine einzelne Klausur an.
     *
     * Body: { kurs_id, klausur_nr?, termin_datum?, termin_uhrzeit?, dauer_minuten?, raum? }
     */
    public static function postKlausur(array $body): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db = Database::getInstance();

        $kursId = (int) ($body['kurs_id'] ?? 0);
        if ($kursId === 0) {
            http_response_code(400);
            throw new RuntimeException('kurs_id fehlt.');
        }

        // Kurs existiert?
        $kursStmt = $db->prepare('SELECT id FROM kurse WHERE id = ?');
        $kursStmt->execute([$kursId]);
        if ($kursStmt->fetchColumn() === false) {
            http_response_code(404);
            throw new RuntimeException("Kurs $kursId nicht gefunden.");
        }

        // klausur_nr: nächste freie Nummer für diesen Kurs
        $klausurNr = isset($body['klausur_nr']) && (int) $body['klausur_nr'] > 0
            ? (int) $body['klausur_nr']
            : self::naechsteKlausurNr($db, $kursId);

        $datum    = self::validierteDatum($body['termin_datum']    ?? null);
        $uhrzeit  = self::validierteUhrzeit($body['termin_uhrzeit'] ?? null);
        $dauer    = isset($body['dauer_minuten']) && (int) $body['dauer_minuten'] > 0
            ? (int) $body['dauer_minuten'] : null;
        $raum     = ($body['raum'] ?? '') !== '' ? trim($body['raum']) : null;

        $benutzer = Session::getBenutzer();

        $db->prepare(
            'INSERT INTO klausuren (kurs_id, klausur_nr, termin_datum, termin_uhrzeit, dauer_minuten, raum, erstellt_von)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$kursId, $klausurNr, $datum, $uhrzeit, $dauer, $raum, $benutzer['id']]);

        return ['id' => (int) $db->lastInsertId(), 'klausur_nr' => $klausurNr];
    }

    // ------------------------------------------------------------------
    // Klausuren – Bearbeiten
    // ------------------------------------------------------------------

    /**
     * Aktualisiert eine Klausur.
     *
     * Body: { termin_datum?, termin_uhrzeit?, dauer_minuten?, raum? }
     */
    public static function putKlausur(int $id, array $body): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db = Database::getInstance();

        $stmt = $db->prepare('SELECT id FROM klausuren WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() === false) {
            http_response_code(404);
            throw new RuntimeException("Klausur $id nicht gefunden.");
        }

        $datum   = self::validierteDatum($body['termin_datum']    ?? null);
        $uhrzeit = self::validierteUhrzeit($body['termin_uhrzeit'] ?? null);
        $dauer   = isset($body['dauer_minuten']) && (int) $body['dauer_minuten'] > 0
            ? (int) $body['dauer_minuten'] : null;
        $raum    = ($body['raum'] ?? '') !== '' ? trim($body['raum']) : null;

        $db->prepare(
            'UPDATE klausuren
             SET termin_datum = ?, termin_uhrzeit = ?, dauer_minuten = ?, raum = ?
             WHERE id = ?'
        )->execute([$datum, $uhrzeit, $dauer, $raum, $id]);

        return ['ok' => true];
    }

    // ------------------------------------------------------------------
    // Klausuren – Excel-Paste-Import
    // ------------------------------------------------------------------

    /**
     * Verarbeitet geparste Excel-Paste-Daten.
     *
     * Body: Array von { kurs: string, datum?: string, uhrzeit?: string, dauer?: string, raum?: string }
     * Legt je Kurs Klausur Nr. 1 an (Upsert).
     *
     * @return array{ erstellt: int, aktualisiert: int, fehler: array[] }
     */
    public static function postPasteImport(array $zeilen): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db = Database::getInstance();

        $ergebnis = KlausurPasteParser::parse($zeilen);
        $fehler   = $ergebnis['fehler'];
        $erstellt = $aktualisiert = 0;

        $benutzer = Session::getBenutzer();

        foreach ($ergebnis['zeilen'] as $i => $z) {
            // Kurs anhand des Kürzels suchen (neuestes Halbjahr bei Mehrdeutigkeit)
            $kursStmt = $db->prepare(
                'SELECT k.id FROM kurse k
                 JOIN halbjahre h ON h.id = k.halbjahr_id
                 JOIN stufen s    ON s.id = h.stufe_id
                 WHERE k.kurs_kuerzel = ?
                 ORDER BY s.schuljahr DESC, h.abschnitt DESC
                 LIMIT 1'
            );
            $kursStmt->execute([$z['kurs_kuerzel']]);
            $kursId = $kursStmt->fetchColumn();

            if ($kursId === false) {
                $fehler[] = ['zeile' => $i + 1, 'meldung' => 'Kurs "' . $z['kurs_kuerzel'] . '" nicht gefunden.'];
                continue;
            }
            $kursId = (int) $kursId;

            // Upsert: Klausur Nr. 1 für diesen Kurs
            $vorhandeneStmt = $db->prepare(
                'SELECT id FROM klausuren WHERE kurs_id = ? AND klausur_nr = 1'
            );
            $vorhandeneStmt->execute([$kursId]);
            $vorhandeneId = $vorhandeneStmt->fetchColumn();

            if ($vorhandeneId !== false) {
                $db->prepare(
                    'UPDATE klausuren
                     SET termin_datum = ?, termin_uhrzeit = ?, dauer_minuten = ?, raum = ?
                     WHERE id = ?'
                )->execute([$z['termin_datum'], $z['termin_uhrzeit'], $z['dauer_minuten'], $z['raum'], $vorhandeneId]);
                $aktualisiert++;
            } else {
                $db->prepare(
                    'INSERT INTO klausuren (kurs_id, klausur_nr, termin_datum, termin_uhrzeit, dauer_minuten, raum, erstellt_von)
                     VALUES (?, 1, ?, ?, ?, ?, ?)'
                )->execute([$kursId, $z['termin_datum'], $z['termin_uhrzeit'], $z['dauer_minuten'], $z['raum'], $benutzer['id']]);
                $erstellt++;
            }
        }

        return ['erstellt' => $erstellt, 'aktualisiert' => $aktualisiert, 'fehler' => $fehler];
    }

    // ------------------------------------------------------------------
    // Kursliste (für Dropdown bei Direktanlage)
    // ------------------------------------------------------------------

    /** Alle Kurse mit Halbjahr-Info für das Direktanlage-Formular. */
    public static function getKurse(): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db = Database::getInstance();

        return $db->query(
            "SELECT k.id, k.kurs_kuerzel, k.anzeigename, k.kursart,
                    s.name AS stufe, s.schuljahr, h.abschnitt, h.id AS halbjahr_id
             FROM kurse k
             JOIN halbjahre h ON h.id = k.halbjahr_id
             JOIN stufen s    ON s.id = h.stufe_id
             ORDER BY s.schuljahr DESC, h.abschnitt DESC, k.anzeigename"
        )->fetchAll();
    }

    // ------------------------------------------------------------------
    // Nachschreibtermine
    // ------------------------------------------------------------------

    /** Alle Nachschreibtermine inkl. verknüpfter Klausuren. */
    public static function getNachschreibtermine(): array
    {
        Session::requireRolle('admin', 'stufenleitung', 'lehrkraft');
        $db = Database::getInstance();

        $termine = $db->query(
            'SELECT n.id, n.termin_datum, n.termin_uhrzeit, n.raum, n.bemerkung, n.erstellt_am
             FROM nachschreibtermine n
             ORDER BY n.termin_datum IS NULL, n.termin_datum, n.termin_uhrzeit'
        )->fetchAll();

        // Verknüpfte Klausuren nachladen
        foreach ($termine as &$t) {
            $stmt = $db->prepare(
                'SELECT k.id, k.klausur_nr, kurs.anzeigename AS kurs_anzeigename
                 FROM nachschreib_zuordnungen nz
                 JOIN klausuren k   ON k.id   = nz.klausur_id
                 JOIN kurse kurs    ON kurs.id = k.kurs_id
                 WHERE nz.nachschreibtermin_id = ?
                 ORDER BY kurs.anzeigename'
            );
            $stmt->execute([$t['id']]);
            $t['klausuren'] = $stmt->fetchAll();
        }
        unset($t);

        return $termine;
    }

    /** Legt einen neuen Nachschreibtermin an. */
    public static function postNachschreibtermin(array $body): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db = Database::getInstance();

        $datum    = self::validierteDatum($body['termin_datum']    ?? null);
        $uhrzeit  = self::validierteUhrzeit($body['termin_uhrzeit'] ?? null);
        $raum     = ($body['raum'] ?? '') !== '' ? trim($body['raum']) : null;
        $bemerkung = ($body['bemerkung'] ?? '') !== '' ? trim($body['bemerkung']) : null;

        $benutzer = Session::getBenutzer();

        $db->prepare(
            'INSERT INTO nachschreibtermine (termin_datum, termin_uhrzeit, raum, bemerkung, erstellt_von)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$datum, $uhrzeit, $raum, $bemerkung, $benutzer['id']]);

        return ['id' => (int) $db->lastInsertId()];
    }

    /** Aktualisiert einen Nachschreibtermin. */
    public static function putNachschreibtermin(int $id, array $body): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db = Database::getInstance();

        $stmt = $db->prepare('SELECT id FROM nachschreibtermine WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() === false) {
            http_response_code(404);
            throw new RuntimeException("Nachschreibtermin $id nicht gefunden.");
        }

        $datum    = self::validierteDatum($body['termin_datum']    ?? null);
        $uhrzeit  = self::validierteUhrzeit($body['termin_uhrzeit'] ?? null);
        $raum     = ($body['raum'] ?? '') !== '' ? trim($body['raum']) : null;
        $bemerkung = ($body['bemerkung'] ?? '') !== '' ? trim($body['bemerkung']) : null;

        $db->prepare(
            'UPDATE nachschreibtermine
             SET termin_datum = ?, termin_uhrzeit = ?, raum = ?, bemerkung = ?
             WHERE id = ?'
        )->execute([$datum, $uhrzeit, $raum, $bemerkung, $id]);

        return ['ok' => true];
    }

    /**
     * Setzt die Klausuren, die zu einem Nachschreibtermin gehören.
     * Ersetzt die bestehende Verknüpfung vollständig.
     *
     * Body: { klausur_ids: [1, 2, 3] }
     */
    public static function postNachschreibterminKlausuren(int $id, array $body): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db = Database::getInstance();

        $stmt = $db->prepare('SELECT id FROM nachschreibtermine WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() === false) {
            http_response_code(404);
            throw new RuntimeException("Nachschreibtermin $id nicht gefunden.");
        }

        $klausurIds = array_map('intval', (array) ($body['klausur_ids'] ?? []));

        // Bestehende Verknüpfungen ersetzen
        $db->prepare('DELETE FROM nachschreib_zuordnungen WHERE nachschreibtermin_id = ?')->execute([$id]);

        if (!empty($klausurIds)) {
            $platzhalter = implode(',', array_fill(0, count($klausurIds), '(?,?)'));
            $params = [];
            foreach ($klausurIds as $kid) {
                $params[] = $kid;
                $params[] = $id;
            }
            $db->prepare(
                "INSERT INTO nachschreib_zuordnungen (klausur_id, nachschreibtermin_id) VALUES $platzhalter"
            )->execute($params);
        }

        return ['ok' => true, 'verknuepft' => count($klausurIds)];
    }

    // ------------------------------------------------------------------
    // Hilfsmethoden
    // ------------------------------------------------------------------

    private static function naechsteKlausurNr(\PDO $db, int $kursId): int
    {
        $stmt = $db->prepare('SELECT COALESCE(MAX(klausur_nr), 0) + 1 FROM klausuren WHERE kurs_id = ?');
        $stmt->execute([$kursId]);
        return (int) $stmt->fetchColumn();
    }

    private static function validierteDatum(mixed $wert): ?string
    {
        if ($wert === null || $wert === '') {
            return null;
        }
        $str = trim((string) $wert);

        // ISO-Format (aus eigenem Formular: YYYY-MM-DD)
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $str)) {
            [$y, $m, $d] = explode('-', $str);
            if (checkdate((int) $m, (int) $d, (int) $y)) {
                return $str;
            }
        }

        // Deutsches Format (aus Paste: TT.MM.JJJJ)
        return KlausurPasteParser::parseDatum($str);
    }

    private static function validierteUhrzeit(mixed $wert): ?string
    {
        if ($wert === null || $wert === '') {
            return null;
        }
        return KlausurPasteParser::parseUhrzeit(trim((string) $wert));
    }
}
