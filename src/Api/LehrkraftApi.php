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
        $db       = Database::getInstance();
        $benutzer = Session::getBenutzer();
        $rollen   = $benutzer['rollen'] ?? [];
        $meineId  = $benutzer['id'];

        $istAdmin = in_array('admin', $rollen, true);
        $istSL    = in_array('stufenleitung', $rollen, true);

        $halbjahrId = isset($_GET['halbjahr_id']) ? (int) $_GET['halbjahr_id'] : null;

        // ist_eigene_sl: ob der aktuelle Nutzer Stufenleitung für die Stufe dieser Klausur ist
        $params = [$meineId]; // erster Param für ist_eigene_sl-Subquery
        $where  = [];

        if ($halbjahrId !== null) {
            $where[]  = 'kurs.halbjahr_id = ?';
            $params[] = $halbjahrId;
        }

        if ($istAdmin) {
            // kein Zugriffsfilter
        } elseif ($istSL) {
            // Eigene Stufen + eigene Kurse als Lehrkraft
            $where[]  = '(EXISTS (SELECT 1 FROM stufenleitungen sl_f WHERE sl_f.stufe_id = s.id AND sl_f.benutzer_id = ?) OR kurs.lehrer_id = ?)';
            $params[] = $meineId;
            $params[] = $meineId;
        } else {
            // Reine Lehrkraft
            $where[]  = 'kurs.lehrer_id = ?';
            $params[] = $meineId;
        }

        $bedingung = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $stmt = $db->prepare(
            "SELECT k.id,
                    k.klausur_nr,
                    k.termin_datum,
                    k.termin_uhrzeit,
                    k.dauer_minuten,
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
                    (SELECT COUNT(*) FROM kurs_schueler ks WHERE ks.kurs_id = kurs.id) AS schueler_anzahl,
                    (SELECT COUNT(*) FROM anwesenheiten a
                     WHERE a.klausur_id = k.id AND a.status != 'ausstehend')          AS anwesenheit_erfasst,
                    EXISTS (SELECT 1 FROM stufenleitungen sl_e
                            WHERE sl_e.stufe_id = s.id AND sl_e.benutzer_id = ?)      AS ist_eigene_sl
             FROM klausuren k
             JOIN kurse kurs  ON kurs.id = k.kurs_id
             JOIN halbjahre h ON h.id    = kurs.halbjahr_id
             JOIN stufen s    ON s.id    = h.stufe_id
             LEFT JOIN benutzer lb ON lb.id = kurs.lehrer_id
             $bedingung
             ORDER BY k.termin_datum IS NULL, k.termin_datum, kurs.anzeigename, k.klausur_nr"
        );
        $stmt->execute($params);
        $klausuren = $stmt->fetchAll();

        if ($istAdmin) {
            foreach ($klausuren as &$k) {
                $k['ist_eigene_sl'] = 1;
            }
            unset($k);
        }

        // Optionaler Parameter: fehlende Schüler*innen je Klausur anhängen
        if (isset($_GET['nachschreiber']) && !empty($klausuren)) {
            $klausurIds  = array_column($klausuren, 'id');
            $platzhalter = implode(',', array_fill(0, count($klausurIds), '?'));

            $nsStmt = $db->prepare(
                "SELECT a.klausur_id,
                        ks.id        AS kurs_schueler_id,
                        ks.name_roh,
                        b.id         AS benutzer_id,
                        b.vorname,
                        b.nachname,
                        a.entschuldigt
                 FROM anwesenheiten a
                 JOIN kurs_schueler ks ON ks.id = a.kurs_schueler_id
                 LEFT JOIN benutzer b  ON b.id  = ks.schueler_id
                 WHERE a.klausur_id IN ($platzhalter) AND a.status = 'fehlend'
                   AND (a.entschuldigt IS NULL OR a.entschuldigt = 1)
                 ORDER BY COALESCE(b.nachname, ks.name_roh), b.vorname"
            );
            $nsStmt->execute($klausurIds);

            $nsMap = [];
            foreach ($nsStmt->fetchAll() as $ns) {
                $nsMap[$ns['klausur_id']][] = $ns;
            }

            foreach ($klausuren as &$k) {
                $k['nachschreiber'] = $nsMap[$k['id']] ?? [];
            }
            unset($k);
        }

        return $klausuren;
    }

    // ------------------------------------------------------------------
    // Klausuren – Anlegen (einzeln)
    // ------------------------------------------------------------------

    /**
     * Legt eine einzelne Klausur an.
     *
     * Body: { kurs_id, klausur_nr?, termin_datum?, termin_uhrzeit?, dauer_minuten? }
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

        $datum   = self::validierteDatum($body['termin_datum']    ?? null);
        $uhrzeit = self::validierteUhrzeit($body['termin_uhrzeit'] ?? null);
        $dauer   = isset($body['dauer_minuten']) && (int) $body['dauer_minuten'] > 0
            ? (int) $body['dauer_minuten'] : null;

        $benutzer = Session::getBenutzer();

        $db->prepare(
            'INSERT INTO klausuren (kurs_id, klausur_nr, termin_datum, termin_uhrzeit, dauer_minuten, erstellt_von)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$kursId, $klausurNr, $datum, $uhrzeit, $dauer, $benutzer['id']]);

        return ['id' => (int) $db->lastInsertId(), 'klausur_nr' => $klausurNr];
    }

    // ------------------------------------------------------------------
    // Klausuren – Bearbeiten
    // ------------------------------------------------------------------

    /**
     * Aktualisiert eine Klausur.
     *
     * Body: { termin_datum?, termin_uhrzeit?, dauer_minuten? }
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

        $db->prepare(
            'UPDATE klausuren
             SET termin_datum = ?, termin_uhrzeit = ?, dauer_minuten = ?
             WHERE id = ?'
        )->execute([$datum, $uhrzeit, $dauer, $id]);

        return ['ok' => true];
    }

    // ------------------------------------------------------------------
    // Klausuren – Excel-Paste-Import
    // ------------------------------------------------------------------

    /**
     * Verarbeitet geparste Excel-Paste-Daten.
     *
     * Matching-Priorität je Kurs:
     * 1. Gleicher Kurs, gleiches Datum → Uhrzeit/Dauer aktualisieren
     * 2. Gleicher Kurs, kein Datum → Datum + alle Felder setzen
     * 3. Kein Treffer → neu anlegen
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

        $benutzer  = Session::getBenutzer();
        $halbjahrIds = self::aktuelleHalbjahrIds($db);

        foreach ($ergebnis['zeilen'] as $i => $z) {
            // Kurs aus dem aktuellen Halbjahr suchen
            $kursId = self::kursIdFuerKuerzel($db, $z['kurs_kuerzel'], $halbjahrIds);

            if ($kursId === null) {
                $fehler[] = ['zeile' => $i + 1, 'meldung' => 'Kurs "' . $z['kurs_kuerzel'] . '" nicht gefunden.'];
                continue;
            }

            // Priorität 1: gleicher Kurs, gleiches Datum
            if ($z['termin_datum'] !== null) {
                $stmt = $db->prepare('SELECT id FROM klausuren WHERE kurs_id = ? AND termin_datum = ?');
                $stmt->execute([$kursId, $z['termin_datum']]);
                $vorhandeneId = $stmt->fetchColumn();

                if ($vorhandeneId !== false) {
                    $db->prepare(
                        'UPDATE klausuren SET termin_uhrzeit = ?, dauer_minuten = ? WHERE id = ?'
                    )->execute([$z['termin_uhrzeit'], $z['dauer_minuten'], $vorhandeneId]);
                    $aktualisiert++;
                    continue;
                }
            }

            // Priorität 2: gleicher Kurs, kein Datum
            $stmt = $db->prepare(
                'SELECT id FROM klausuren WHERE kurs_id = ? AND termin_datum IS NULL ORDER BY klausur_nr LIMIT 1'
            );
            $stmt->execute([$kursId]);
            $vorhandeneId = $stmt->fetchColumn();

            if ($vorhandeneId !== false) {
                $db->prepare(
                    'UPDATE klausuren
                     SET termin_datum = ?, termin_uhrzeit = ?, dauer_minuten = ?
                     WHERE id = ?'
                )->execute([$z['termin_datum'], $z['termin_uhrzeit'], $z['dauer_minuten'], $vorhandeneId]);
                $aktualisiert++;
                continue;
            }

            // Priorität 3: neu anlegen
            $klausurNr = self::naechsteKlausurNr($db, $kursId);
            $db->prepare(
                'INSERT INTO klausuren (kurs_id, klausur_nr, termin_datum, termin_uhrzeit, dauer_minuten, erstellt_von)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$kursId, $klausurNr, $z['termin_datum'], $z['termin_uhrzeit'], $z['dauer_minuten'], $benutzer['id']]);
            $erstellt++;
        }

        return ['erstellt' => $erstellt, 'aktualisiert' => $aktualisiert, 'fehler' => $fehler];
    }

    // ------------------------------------------------------------------
    // Kursliste (für Dropdown bei Direktanlage) + Vorlage-Download
    // ------------------------------------------------------------------

    /**
     * Kurse für das Direktanlage-Formular.
     * Admin: aktuelles (neuestes) Halbjahr.
     * Stufenleitung: alle Kurse aus eigenen Stufen, neueste zuerst.
     */
    public static function getKurse(): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db       = Database::getInstance();
        $benutzer = Session::getBenutzer();

        // Kurse aus eigenen Stufen (SL-Filter), neueste zuerst
        $stmt = $db->prepare(
            "SELECT k.id, k.kurs_kuerzel, k.anzeigename, k.kursart,
                    s.name AS stufe, s.schuljahr, h.abschnitt, h.id AS halbjahr_id
             FROM kurse k
             JOIN halbjahre h ON h.id = k.halbjahr_id
             JOIN stufen s    ON s.id = h.stufe_id
             JOIN stufenleitungen sl ON sl.stufe_id = s.id AND sl.benutzer_id = ?
             ORDER BY s.schuljahr DESC, h.abschnitt DESC, s.name, k.anzeigename"
        );
        $stmt->execute([$benutzer['id']]);
        $kurse = $stmt->fetchAll();

        // Admin ohne SL-Zuordnung: Fallback auf aktuelles Halbjahr
        if (empty($kurse) && in_array('admin', $benutzer['rollen'] ?? [], true)) {
            $halbjahrIds = self::aktuelleHalbjahrIds($db);
            if (empty($halbjahrIds)) {
                return [];
            }
            $platzhalter = implode(',', array_fill(0, count($halbjahrIds), '?'));
            $stmt = $db->prepare(
                "SELECT k.id, k.kurs_kuerzel, k.anzeigename, k.kursart,
                        s.name AS stufe, s.schuljahr, h.abschnitt, h.id AS halbjahr_id
                 FROM kurse k
                 JOIN halbjahre h ON h.id = k.halbjahr_id
                 JOIN stufen s    ON s.id = h.stufe_id
                 WHERE k.halbjahr_id IN ($platzhalter)
                 ORDER BY s.schuljahr DESC, h.abschnitt DESC, s.name, k.anzeigename"
            );
            $stmt->execute($halbjahrIds);
            return $stmt->fetchAll();
        }

        return $kurse;
    }

    /**
     * Generiert eine CSV-Vorlage mit allen Kursen des aktuellen Halbjahres
     * und sendet sie als Datei-Download. Endet mit exit().
     */
    public static function downloadVorlage(): never
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db          = Database::getInstance();
        $halbjahrIds = self::aktuelleHalbjahrIds($db);

        $kurse = [];
        if (!empty($halbjahrIds)) {
            $platzhalter = implode(',', array_fill(0, count($halbjahrIds), '?'));
            $stmt = $db->prepare(
                "SELECT k.kurs_kuerzel, k.anzeigename,
                        (SELECT COUNT(*) FROM kurs_schueler ks WHERE ks.kurs_id = k.id) AS anzahl
                 FROM kurse k
                 WHERE k.halbjahr_id IN ($platzhalter)
                 ORDER BY k.anzeigename"
            );
            $stmt->execute($halbjahrIds);
            $kurse = $stmt->fetchAll();
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="klausur-vorlage.csv"');
        header('Cache-Control: no-cache, no-store');
        header('Content-Security-Policy: default-src \'none\'');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM für Excel

        fputcsv($out, ['Kurs', 'Anzeigename', 'TN', 'Datum', 'Uhrzeit', 'Dauer'], ';');

        foreach ($kurse as $k) {
            fputcsv($out, [$k['kurs_kuerzel'], $k['anzeigename'], $k['anzahl'], '', '', ''], ';');
        }

        fclose($out);
        exit();
    }

    // ------------------------------------------------------------------
    // Nachschreibtermine
    // ------------------------------------------------------------------

    /** Alle Nachschreibtermine inkl. verknüpfter Klausuren und deren Nachschreiber*innen. */
    public static function getNachschreibtermine(): array
    {
        Session::requireRolle('admin', 'stufenleitung', 'lehrkraft');
        $db = Database::getInstance();

        $termine = $db->query(
            'SELECT n.id, n.termin_datum, n.termin_uhrzeit, n.bemerkung, n.erstellt_am
             FROM nachschreibtermine n
             ORDER BY n.termin_datum IS NULL, n.termin_datum, n.termin_uhrzeit'
        )->fetchAll();

        if (empty($termine)) {
            return $termine;
        }

        // Verknüpfte Klausuren + Nachschreiber*innen in einer einzigen Abfrage
        $alleZuordnungen = $db->query(
            'SELECT nz.nachschreibtermin_id,
                    k.id              AS klausur_id,
                    k.klausur_nr,
                    kurs.anzeigename  AS kurs_anzeigename,
                    ks.id             AS kurs_schueler_id,
                    ks.name_roh,
                    b.id              AS benutzer_id,
                    b.vorname,
                    b.nachname,
                    a.entschuldigt
             FROM nachschreib_zuordnungen nz
             JOIN klausuren k   ON k.id   = nz.klausur_id
             JOIN kurse kurs    ON kurs.id = k.kurs_id
             LEFT JOIN anwesenheiten a
                    ON a.klausur_id = k.id AND a.status = \'fehlend\'
                    AND (a.entschuldigt IS NULL OR a.entschuldigt = 1)
             LEFT JOIN kurs_schueler ks ON ks.id = a.kurs_schueler_id
             LEFT JOIN benutzer b       ON b.id  = ks.schueler_id
             ORDER BY nz.nachschreibtermin_id,
                      kurs.anzeigename,
                      COALESCE(b.nachname, ks.name_roh),
                      b.vorname'
        )->fetchAll();

        // Reshape: terminId → { klausurId → { ...info, nachschreiber[] } }
        $klausurenMap = [];
        foreach ($alleZuordnungen as $row) {
            $tid = (int) $row['nachschreibtermin_id'];
            $kid = (int) $row['klausur_id'];

            if (!isset($klausurenMap[$tid][$kid])) {
                $klausurenMap[$tid][$kid] = [
                    'id'               => $kid,
                    'klausur_nr'       => (int) $row['klausur_nr'],
                    'kurs_anzeigename' => $row['kurs_anzeigename'],
                    'nachschreiber'    => [],
                ];
            }

            if ($row['kurs_schueler_id'] !== null) {
                $klausurenMap[$tid][$kid]['nachschreiber'][] = [
                    'kurs_schueler_id' => $row['kurs_schueler_id'],
                    'name_roh'         => $row['name_roh'],
                    'benutzer_id'      => $row['benutzer_id'],
                    'vorname'          => $row['vorname'],
                    'nachname'         => $row['nachname'],
                    'entschuldigt'     => $row['entschuldigt'],
                ];
            }
        }

        foreach ($termine as &$t) {
            $t['klausuren'] = array_values($klausurenMap[(int) $t['id']] ?? []);
        }
        unset($t);

        return $termine;
    }

    /** Legt einen neuen Nachschreibtermin an. */
    public static function postNachschreibtermin(array $body): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db = Database::getInstance();

        $datum     = self::validierteDatum($body['termin_datum']    ?? null);
        $uhrzeit   = self::validierteUhrzeit($body['termin_uhrzeit'] ?? null);
        $bemerkung = ($body['bemerkung'] ?? '') !== '' ? trim($body['bemerkung']) : null;

        $benutzer = Session::getBenutzer();

        $db->prepare(
            'INSERT INTO nachschreibtermine (termin_datum, termin_uhrzeit, bemerkung, erstellt_von)
             VALUES (?, ?, ?, ?)'
        )->execute([$datum, $uhrzeit, $bemerkung, $benutzer['id']]);

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

        $datum     = self::validierteDatum($body['termin_datum']    ?? null);
        $uhrzeit   = self::validierteUhrzeit($body['termin_uhrzeit'] ?? null);
        $bemerkung = ($body['bemerkung'] ?? '') !== '' ? trim($body['bemerkung']) : null;

        $db->prepare(
            'UPDATE nachschreibtermine
             SET termin_datum = ?, termin_uhrzeit = ?, bemerkung = ?
             WHERE id = ?'
        )->execute([$datum, $uhrzeit, $bemerkung, $id]);

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
    // Nachschreibtermine für Lehrkraft (eigene Kurse)
    // ------------------------------------------------------------------

    /**
     * Gibt alle Nachschreibtermine zurück, bei denen mindestens ein Schüler
     * aus einer der eigenen Klausuren zugeordnet ist.
     *
     * @return list<array{id: int, termin_datum: ?string, termin_uhrzeit: ?string,
     *                     bemerkung: ?string, kurs_anzeigename: string,
     *                     klausur_nr: int, nachschreiber_anzahl: int}>
     */
    public static function meineNachschreibtermine(): array
    {
        Session::requireRolle('admin', 'stufenleitung', 'lehrkraft');
        $db       = Database::getInstance();
        $benutzer = Session::getBenutzer();

        $stmt = $db->prepare(
            "SELECT nt.id,
                    nt.termin_datum,
                    nt.termin_uhrzeit,
                    nt.bemerkung,
                    k.anzeigename AS kurs_anzeigename,
                    kl.klausur_nr,
                    (SELECT COUNT(*)
                     FROM anwesenheiten a2
                     JOIN kurs_schueler ks2 ON ks2.id = a2.kurs_schueler_id AND ks2.kurs_id = k.id
                     WHERE a2.klausur_id = kl.id
                       AND a2.status = 'fehlend'
                       AND (a2.entschuldigt IS NULL OR a2.entschuldigt = 1)) AS nachschreiber_anzahl
             FROM nachschreib_zuordnungen nz
             JOIN nachschreibtermine nt ON nt.id = nz.nachschreibtermin_id
             JOIN klausuren kl          ON kl.id = nz.klausur_id
             JOIN kurse k               ON k.id  = kl.kurs_id
             WHERE k.lehrer_id = ?
             ORDER BY
                 CASE WHEN nt.termin_datum IS NULL THEN 1 ELSE 0 END,
                 nt.termin_datum,
                 nt.termin_uhrzeit,
                 k.anzeigename,
                 kl.klausur_nr"
        );
        $stmt->execute([$benutzer['id']]);

        return $stmt->fetchAll();
    }

    // ------------------------------------------------------------------
    // Löschen
    // ------------------------------------------------------------------

    public static function deleteKlausur(int $id): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db = Database::getInstance();

        $stmt = $db->prepare('SELECT id FROM klausuren WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() === false) {
            http_response_code(404);
            throw new RuntimeException("Klausur $id nicht gefunden.");
        }

        $db->prepare('DELETE FROM klausuren WHERE id = ?')->execute([$id]);
        return ['ok' => true];
    }

    public static function deleteNachschreibtermin(int $id): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db = Database::getInstance();

        $stmt = $db->prepare('SELECT id FROM nachschreibtermine WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() === false) {
            http_response_code(404);
            throw new RuntimeException("Nachschreibtermin $id nicht gefunden.");
        }

        $db->prepare('DELETE FROM nachschreibtermine WHERE id = ?')->execute([$id]);
        return ['ok' => true];
    }

    // ------------------------------------------------------------------
    // Hilfsmethoden
    // ------------------------------------------------------------------

    /**
     * Gibt die IDs aller Halbjahre des neuesten Schuljahres und Abschnitts zurück.
     * "Neuestes" = höchstes Schuljahr (lexikografisch), darin höchster Abschnitt.
     */
    private static function aktuelleHalbjahrIds(\PDO $db): array
    {
        $row = $db->query(
            "SELECT s.schuljahr, MAX(h.abschnitt) AS abschnitt
             FROM halbjahre h
             JOIN stufen s ON s.id = h.stufe_id
             WHERE s.schuljahr = (SELECT MAX(schuljahr) FROM stufen)
             GROUP BY s.schuljahr"
        )->fetch();

        if ($row === false) {
            return [];
        }

        $stmt = $db->prepare(
            "SELECT h.id FROM halbjahre h
             JOIN stufen s ON s.id = h.stufe_id
             WHERE s.schuljahr = ? AND h.abschnitt = ?"
        );
        $stmt->execute([$row['schuljahr'], $row['abschnitt']]);
        return array_column($stmt->fetchAll(), 'id');
    }

    /**
     * Sucht die Kurs-ID anhand des Kürzels, bevorzugt aus den angegebenen Halbjahren.
     * Fallback auf neuestes verfügbares Halbjahr.
     */
    private static function kursIdFuerKuerzel(\PDO $db, string $kuerzel, array $halbjahrIds): ?int
    {
        if (!empty($halbjahrIds)) {
            $platzhalter = implode(',', array_fill(0, count($halbjahrIds), '?'));
            $stmt = $db->prepare(
                "SELECT id FROM kurse WHERE kurs_kuerzel = ? AND halbjahr_id IN ($platzhalter) LIMIT 1"
            );
            $stmt->execute([$kuerzel, ...$halbjahrIds]);
            $id = $stmt->fetchColumn();
            if ($id !== false) {
                return (int) $id;
            }
        }

        // Fallback: neuestes Halbjahr generell
        $stmt = $db->prepare(
            'SELECT k.id FROM kurse k
             JOIN halbjahre h ON h.id = k.halbjahr_id
             JOIN stufen s    ON s.id = h.stufe_id
             WHERE k.kurs_kuerzel = ?
             ORDER BY s.schuljahr DESC, h.abschnitt DESC
             LIMIT 1'
        );
        $stmt->execute([$kuerzel]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

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
