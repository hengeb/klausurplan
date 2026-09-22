<?php

declare(strict_types=1);

namespace Klausurplan\Import;

use Klausurplan\Models\Database;
use Klausurplan\Models\Zuordnung;
use RuntimeException;

class GomsthImporter
{
    private \PDO $db;
    private int  $importiertVon;

    /** GOMSTH-Kursarten, die importiert werden */
    private const KLAUSUR_KURSARTEN = ['GKS', 'LK1', 'LK2', 'AB3', 'AB4'];

    /** Mappt GOMSTH-Rohwert auf vereinfachte Kursart ('LK' oder 'GK') */
    private static function vereinfacheKursart(string $gomsthKursart): string
    {
        return str_starts_with($gomsthKursart, 'LK') ? 'LK' : 'GK';
    }

    public function __construct(int $importiertVon)
    {
        $this->db            = Database::getInstance();
        $this->importiertVon = $importiertVon;
    }

    /**
     * Importiert eine GOMSTH-.dat-Datei (pipe-getrennt, UTF-8 mit BOM, CRLF).
     *
     * @return array{kurse: int, schueler: int, entfernt: int, halbjahre: int, stufen_ids: list<int>}
     */
    public function importiere(string $dateiInhalt): array
    {
        $zeilen = $this->parseZeilen($dateiInhalt);

        if (count($zeilen) < 2) {
            throw new RuntimeException('Datei enthält keine Daten.');
        }

        $header  = array_shift($zeilen);
        $spalten = $this->mapSpalten($header);

        // kurs_id → [name_roh => true] – zum Erkennen veralteter Schüler*innen
        $verarbeitet = [];
        $halbjahrIds = [];
        $stufenIds   = [];

        $schuelerAnzahl = 0;

        foreach ($zeilen as $zeile) {
            if (count($zeile) <= max($spalten)) {
                continue;
            }

            $kursart = trim($zeile[$spalten['Kursart']] ?? '');
            if (!in_array($kursart, self::KLAUSUR_KURSARTEN, true)) {
                continue;
            }

            $jahrgang      = trim($zeile[$spalten['Jahrgang']]   ?? '');
            $abschnitt     = (int) ($zeile[$spalten['Abschnitt']] ?? 1);
            $jahr          = (int) ($zeile[$spalten['Jahr']]      ?? (int) date('Y'));
            $kursKuerzel   = trim($zeile[$spalten['Kurs']]        ?? '');
            $fachKuerzel   = trim($zeile[$spalten['Fach']]        ?? '');
            $lehrerKuerzel = trim($zeile[$spalten['Fachlehrer']]  ?? '') ?: null;
            $nachname      = trim($zeile[$spalten['Nachname']]    ?? '');
            $vorname       = trim($zeile[$spalten['Vorname']]     ?? '');

            if ($kursKuerzel === '' || $jahrgang === '') {
                continue;
            }

            $schuljahr  = $jahr . '/' . ($jahr + 1);
            $stufeId    = $this->findeOderLegeAnStufe($jahrgang, $schuljahr);
            $halbjahrId = $this->findeOderLegeAnHalbjahr($stufeId, $abschnitt);
            $halbjahrIds[$halbjahrId] = true;
            $stufenIds[$stufeId]      = true;

            $kursId = $this->findeOderLegeAnKurs(
                $halbjahrId, $kursKuerzel, $fachKuerzel,
                self::vereinfacheKursart($kursart), $lehrerKuerzel,
            );

            if ($nachname !== '' || $vorname !== '') {
                $nameRoh = $nachname . '|' . $vorname;
                if (!isset($verarbeitet[$kursId][$nameRoh])) {
                    $this->findeOderLegeAnKursSchueler($kursId, $nameRoh, $kursart);
                    $verarbeitet[$kursId][$nameRoh] = true;
                    $schuelerAnzahl++;
                }
            }
        }

        $kursIds  = array_keys($verarbeitet);
        $entfernt = $this->entferneVeralteteSchueler($verarbeitet);

        // Gespeicherte Zuordnungen anwenden, Rest automatisch matchen
        Zuordnung::ordneSchuelerZu($this->db, $kursIds);
        Zuordnung::ordneLehrerZu($this->db, $kursIds);

        return [
            'kurse'      => count($kursIds),
            'schueler'   => $schuelerAnzahl,
            'entfernt'   => $entfernt,
            'halbjahre'  => count($halbjahrIds),
            'stufen_ids' => array_keys($stufenIds),
        ];
    }

    // ------------------------------------------------------------------
    // Parsing
    // ------------------------------------------------------------------

    /** Bereinigt BOM + CRLF und gibt ein Array von Zeilen-Arrays zurück. */
    private function parseZeilen(string $inhalt): array
    {
        // UTF-8 BOM entfernen
        $inhalt = ltrim($inhalt, "\xEF\xBB\xBF");
        // CRLF und einzelne CR normalisieren
        $inhalt = str_replace(["\r\n", "\r"], "\n", $inhalt);

        $zeilen = [];
        foreach (explode("\n", $inhalt) as $zeile) {
            $zeile = trim($zeile);
            if ($zeile === '') {
                continue;
            }
            $zeilen[] = explode('|', $zeile);
        }

        return $zeilen;
    }

    /** Baut eine Map Spaltenname → Index aus der Header-Zeile. */
    private function mapSpalten(array $header): array
    {
        $map = [];
        foreach ($header as $i => $name) {
            $map[trim($name)] = $i;
        }

        $pflicht = ['Nachname', 'Vorname', 'Fach', 'Fachlehrer', 'Kursart', 'Kurs', 'Jahrgang', 'Abschnitt', 'Jahr'];
        foreach ($pflicht as $feld) {
            if (!array_key_exists($feld, $map)) {
                throw new RuntimeException("Pflichtfeld '$feld' fehlt in der GOMSTH-Datei.");
            }
        }

        return $map;
    }

    // ------------------------------------------------------------------
    // Datenbankoperationen
    // ------------------------------------------------------------------

    private function findeOderLegeAnStufe(string $name, string $schuljahr): int
    {
        $stmt = $this->db->prepare('SELECT id FROM stufen WHERE name = ? AND schuljahr = ?');
        $stmt->execute([$name, $schuljahr]);
        $id = $stmt->fetchColumn();

        if ($id !== false) {
            return (int) $id;
        }

        $this->db->prepare('INSERT INTO stufen (name, schuljahr) VALUES (?, ?)')->execute([$name, $schuljahr]);
        $neueId = (int) $this->db->lastInsertId();
        $this->autoForwardStufenleitung($neueId, $name, $schuljahr);
        return $neueId;
    }

    /**
     * Überträgt Stufenleitungen automatisch auf eine neue Stufe.
     * Reihenfolge: EF → Q1 → Q2 → EF → …
     * Vorgänger ist immer die vorherige Stufe im vorherigen Schuljahr.
     */
    private function autoForwardStufenleitung(int $neueStufeId, string $name, string $schuljahr): void
    {
        static $vorgaengerMap = ['EF' => 'Q2', 'Q1' => 'EF', 'Q2' => 'Q1'];
        $vorgaengerName = $vorgaengerMap[$name] ?? null;
        if ($vorgaengerName === null) {
            return;
        }

        [$startJahr, $endJahr] = explode('/', $schuljahr, 2);
        $vorgaengerSchuljahr = ((int) $startJahr - 1) . '/' . ((int) $endJahr - 1);

        $stmt = $this->db->prepare('SELECT id FROM stufen WHERE name = ? AND schuljahr = ?');
        $stmt->execute([$vorgaengerName, $vorgaengerSchuljahr]);
        $vorgaengerStufeId = $stmt->fetchColumn();
        if ($vorgaengerStufeId === false) {
            return;
        }

        // Nur Personen übernehmen, die noch die Stufenleitung-Rolle haben
        $stmt = $this->db->prepare(
            'SELECT sl.benutzer_id
             FROM stufenleitungen sl
             JOIN rollen r ON r.benutzer_id = sl.benutzer_id AND r.rolle = \'stufenleitung\'
             WHERE sl.stufe_id = ?'
        );
        $stmt->execute([(int) $vorgaengerStufeId]);

        $ins = $this->db->prepare(
            'INSERT IGNORE INTO stufenleitungen (benutzer_id, stufe_id) VALUES (?, ?)'
        );
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $benutzerId) {
            $ins->execute([$benutzerId, $neueStufeId]);
        }
    }

    private function findeOderLegeAnHalbjahr(int $stufeId, int $abschnitt): int
    {
        $stmt = $this->db->prepare('SELECT id FROM halbjahre WHERE stufe_id = ? AND abschnitt = ?');
        $stmt->execute([$stufeId, $abschnitt]);
        $id = $stmt->fetchColumn();

        if ($id !== false) {
            $this->db->prepare(
                'UPDATE halbjahre SET importiert_am = NOW(), importiert_von = ? WHERE id = ?'
            )->execute([$this->importiertVon, (int) $id]);
            return (int) $id;
        }

        $this->db->prepare(
            'INSERT INTO halbjahre (stufe_id, abschnitt, importiert_von) VALUES (?, ?, ?)'
        )->execute([$stufeId, $abschnitt, $this->importiertVon]);
        return (int) $this->db->lastInsertId();
    }

    private function findeOderLegeAnKurs(
        int     $halbjahrId,
        string  $kursKuerzel,
        string  $fachKuerzel,
        string  $kursart,
        ?string $lehrerKuerzel,
    ): int {
        $stmt = $this->db->prepare('SELECT id FROM kurse WHERE halbjahr_id = ? AND kurs_kuerzel = ?');
        $stmt->execute([$halbjahrId, $kursKuerzel]);
        $id = $stmt->fetchColumn();

        $anzeigename = self::generiereAnzeigename($kursKuerzel, $fachKuerzel, $kursart, $this->db);

        if ($id !== false) {
            $this->db->prepare(
                'UPDATE kurse SET fach_kuerzel = ?, kursart = ?, lehrer_kuerzel = ?, anzeigename = ? WHERE id = ?'
            )->execute([$fachKuerzel, $kursart, $lehrerKuerzel, $anzeigename, (int) $id]);
            return (int) $id;
        }

        $this->db->prepare(
            'INSERT INTO kurse (halbjahr_id, kurs_kuerzel, fach_kuerzel, kursart, lehrer_kuerzel, anzeigename)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$halbjahrId, $kursKuerzel, $fachKuerzel, $kursart, $lehrerKuerzel, $anzeigename]);
        return (int) $this->db->lastInsertId();
    }

    private function findeOderLegeAnKursSchueler(int $kursId, string $nameRoh, string $kursart): void
    {
        $stmt = $this->db->prepare('SELECT id FROM kurs_schueler WHERE kurs_id = ? AND name_roh = ?');
        $stmt->execute([$kursId, $nameRoh]);
        $id = $stmt->fetchColumn();

        if ($id === false) {
            $this->db->prepare(
                'INSERT INTO kurs_schueler (kurs_id, name_roh, kursart) VALUES (?, ?, ?)'
            )->execute([$kursId, $nameRoh, $kursart]);
        } else {
            // Kursart bei erneutem Import aktualisieren
            $this->db->prepare('UPDATE kurs_schueler SET kursart = ? WHERE id = ?')
                ->execute([$kursart, $id]);
        }
    }

    /**
     * Entfernt Schüler*innen, die nicht mehr in der Datei stehen,
     * aber nur wenn noch keine Anwesenheitsdaten existieren.
     */
    private function entferneVeralteteSchueler(array $verarbeitet): int
    {
        $entfernt = 0;

        foreach ($verarbeitet as $kursId => $nameRohSet) {
            $stmt = $this->db->prepare('SELECT id, name_roh FROM kurs_schueler WHERE kurs_id = ?');
            $stmt->execute([$kursId]);

            foreach ($stmt->fetchAll() as $ks) {
                if (isset($nameRohSet[$ks['name_roh']])) {
                    continue; // Noch in der Datei
                }

                // Nur entfernen wenn keine Anwesenheitsdaten existieren
                $hatAnwesenheit = $this->db->prepare(
                    'SELECT 1 FROM anwesenheiten WHERE kurs_schueler_id = ? LIMIT 1'
                );
                $hatAnwesenheit->execute([$ks['id']]);

                if ($hatAnwesenheit->fetchColumn() === false) {
                    $this->db->prepare('DELETE FROM kurs_schueler WHERE id = ?')->execute([$ks['id']]);
                    $entfernt++;
                }
            }
        }

        return $entfernt;
    }

    // ------------------------------------------------------------------
    // Anzeigenamen-Generierung (auch öffentlich nutzbar)
    // ------------------------------------------------------------------

    /**
     * Generiert den Anzeigenamen aus dem kurs_kuerzel.
     * Beispiel: "SP_Q2_GK1_SZ" mit kursart "GKS" → "Q2 Sport GK 1 SZ"
     */
    public static function generiereAnzeigename(
        string $kursKuerzel,
        string $fachKuerzel,
        string $kursart,
        \PDO   $db,
    ): string {
        $teile         = explode('_', $kursKuerzel);
        $stufe         = $teile[1] ?? '';
        $kursartNrTeil = $teile[2] ?? '';
        $kuerzel       = $teile[3] ?? '';

        // Fachname aus DB (Fallback: Kürzel selbst)
        $stmt = $db->prepare('SELECT bezeichnung FROM fach_bezeichnungen WHERE kuerzel = ?');
        $stmt->execute([strtoupper($fachKuerzel)]);
        $fachname = $stmt->fetchColumn() ?: $fachKuerzel;

        // kursart ist bereits vereinfacht ('LK' oder 'GK')
        $kursartTyp = $kursart;

        // Nummer aus dem dritten Teil extrahieren: "GK1" → "1", "LK2" → "2"
        $nummer = preg_replace('/\D/', '', $kursartNrTeil);

        $teileAnzeige = array_filter([
            $stufe,
            $fachname,
            $kursartTyp . ($nummer !== '' ? ' ' . $nummer : ''),
            $kuerzel,
        ], static fn ($s) => $s !== '');

        return implode(' ', $teileAnzeige);
    }
}
