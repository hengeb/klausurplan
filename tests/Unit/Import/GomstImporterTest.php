<?php

declare(strict_types=1);

namespace Klausurplan\Tests\Unit\Import;

use Klausurplan\Import\GomstImporter;
use Klausurplan\Tests\Support\FakeResult;
use Klausurplan\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class GomstImporterTest extends TestCase
{
    private const KOPF = 'Nachname|Vorname|Fach|Fachlehrer|Kursart|Kurs|Jahrgang|Abschnitt|Jahr';

    /** @param list<string> $zeilen */
    private function datei(array $zeilen, string $kopf = self::KOPF): string
    {
        return "\xEF\xBB\xBF" . implode("\r\n", [$kopf, ...$zeilen]) . "\r\n";
    }

    private function importer(): GomstImporter
    {
        return new GomstImporter(99);
    }

    /**
     * Datenbank, in der anfangs nichts existiert. Stufen, Halbjahre und Kurse werden beim
     * Einfügen „gemerkt“, sodass spätere Suchen sie wiederfinden (wie in der echten Datenbank).
     */
    private function leereDatenbank(): void
    {
        $ids  = [];
        $next = 100;
        $schluessel = static function (string $sql, array $p): string {
            preg_match('/(?:INTO|FROM) (\w+)/', $sql, $m);
            return $m[1] . '|' . json_encode(array_slice($p, 0, 2));
        };

        $this->db->on('/^INSERT INTO (stufen|halbjahre|kurse) /', function (string $sql, array $p) use (&$ids, &$next, $schluessel): FakeResult {
            return FakeResult::insert($ids[$schluessel($sql, $p)] = ++$next);
        });
        $this->db->on('/^SELECT id FROM (stufen|halbjahre|kurse) WHERE/', function (string $sql, array $p) use (&$ids, $schluessel): FakeResult {
            $id = $ids[$schluessel($sql, $p)] ?? null;
            return $id === null ? FakeResult::leer() : FakeResult::scalar($id);
        });
    }

    // ------------------------------------------------------------------ Eingabe prüfen

    public function testDateiOhneDatenzeilenWirdAbgelehnt(): void
    {
        $this->erwarteFehler(fn () => $this->importer()->importiere($this->datei([])), 'keine Daten');
        $this->erwarteFehler(fn () => $this->importer()->importiere(''), 'keine Daten');
    }

    public function testFehlendePflichtspalteWirdBenannt(): void
    {
        $kopf = 'Nachname|Vorname|Fach|Fachlehrer|Kursart|Jahrgang|Abschnitt|Jahr'; // "Kurs" fehlt

        $this->erwarteFehler(
            fn () => $this->importer()->importiere($this->datei(['a|b|c|d|e|f|g|h'], $kopf)),
            "Pflichtfeld 'Kurs' fehlt",
        );
    }

    // ------------------------------------------------------------------ Import

    public function testNurKlausurrelevanteKursartenWerdenImportiert(): void
    {
        $this->leereDatenbank();

        $ergebnis = $this->importer()->importiere($this->datei([
            'Mustermann|Max|SPA|SZ|GKS|SPA_Q2_GK1_SZ|Q2|1|2025',
            'Mustermann|Max|SPA|SZ|GKM|SPA_Q2_GM1_SZ|Q2|1|2025',   // mündlich: übersprungen
            'Mustermann|Max|ZG|XX|ZK|ZG_Q2_ZK1_XX|Q2|1|2025',      // Zusatzkurs: übersprungen
            'Mustermann|Max|M|MA|LK1|M_Q2_LK1_MA|Q2|1|2025',
            'Mustermann|Max|E|EN|AB3|E_Q2_AB3_EN|Q2|1|2025',
        ]));

        $this->assertSame(2 + 1, $ergebnis['kurse']);
        $kurse = $this->db->aufrufe('/^INSERT INTO kurse/');
        $this->assertSame(['SPA_Q2_GK1_SZ', 'M_Q2_LK1_MA', 'E_Q2_AB3_EN'], array_column(array_column($kurse, 'params'), 1));
    }

    public function testKursartWirdVereinfachtUndKursschuelerBehaeltDieGomstKursart(): void
    {
        $this->leereDatenbank();

        $this->importer()->importiere($this->datei([
            'A|Anna|M|MA|LK2|M_Q1_LK2_MA|Q1|1|2025',
            'B|Bert|E|EN|AB4|E_Q1_AB4_EN|Q1|1|2025',
            'C|Cleo|D|DE|GKS|D_Q1_GK1_DE|Q1|1|2025',
        ]));

        $kursarten = array_column(array_column($this->db->aufrufe('/^INSERT INTO kurse/'), 'params'), 3);
        $this->assertSame(['LK', 'GK', 'GK'], $kursarten);
        $schuelerArten = array_column(array_column($this->db->aufrufe('/^INSERT INTO kurs_schueler/'), 'params'), 2);
        $this->assertSame(['LK2', 'AB4', 'GKS'], $schuelerArten);
    }

    public function testStufeSchuljahrUndAbschnittStammenAusDerDatei(): void
    {
        $this->leereDatenbank();

        $ergebnis = $this->importer()->importiere($this->datei(['A|Anna|M|MA|LK1|M_Q2_LK1_MA|Q2|2|2024']));

        $this->assertSame(['Q2', '2024/2025'], $this->db->aufrufe('/^INSERT INTO stufen/')[0]['params']);
        $halbjahr = $this->db->aufrufe('/^INSERT INTO halbjahre/')[0]['params'];
        $this->assertSame([2, 99], [$halbjahr[1], $halbjahr[2]], 'Abschnitt und importiert_von');
        $this->assertSame(1, $ergebnis['halbjahre']);
        $this->assertCount(1, $ergebnis['stufen_ids']);
    }

    public function testDerAnzeigenameWirdErzeugt(): void
    {
        $this->leereDatenbank();
        $this->db->onScalar('/FROM fach_bezeichnungen/', 'Sport');

        $this->importer()->importiere($this->datei(['A|Anna|SPA|SZ|GKS|SPA_Q2_GK1_SZ|Q2|1|2025']));

        $kurs = $this->db->aufrufe('/^INSERT INTO kurse/')[0]['params'];
        $this->assertSame('Q2 Sport GK 1 SZ', $kurs[5]);
        $this->assertSame('SZ', $kurs[4], 'lehrer_kuerzel');
    }

    public function testGleicherNameImSelbenKursWirdNurEinmalGezaehlt(): void
    {
        $this->leereDatenbank();

        $ergebnis = $this->importer()->importiere($this->datei([
            'A|Anna|M|MA|LK1|M_Q2_LK1_MA|Q2|1|2025',
            'A|Anna|M|MA|LK1|M_Q2_LK1_MA|Q2|1|2025',
            'A|Anna|E|EN|GKS|E_Q2_GK1_EN|Q2|1|2025',   // gleicher Name, anderer Kurs → zählt
        ]));

        $this->assertSame(2, $ergebnis['schueler']);
        $this->assertSame(2, $ergebnis['kurse']);
    }

    public function testFehlerhafteZeilenWerdenUebersprungen(): void
    {
        $this->leereDatenbank();

        $ergebnis = $this->importer()->importiere($this->datei([
            'zu|wenig|Spalten',
            'A|Anna|M|MA|LK1||Q2|1|2025',            // Kurs leer
            'A|Anna|M|MA|LK1|M_X_LK1_MA||1|2025',    // Jahrgang leer
            'A|Anna|M|MA|LK1|M_Q2_LK1_MA|Q2|1|2025', // gültig
            '',
        ]));

        $this->assertSame(1, $ergebnis['kurse']);
        $this->assertSame(1, $ergebnis['schueler']);
    }

    public function testZeilenOhneNamenLegenKeineSchuelerAnAberDenKurs(): void
    {
        $this->leereDatenbank();

        $ergebnis = $this->importer()->importiere($this->datei(['||M|MA|LK1|M_Q2_LK1_MA|Q2|1|2025']));

        $this->assertSame(0, $ergebnis['schueler']);
        $this->assertTrue($this->db->wurdeAusgefuehrt('/^INSERT INTO kurse/'));
        $this->assertFalse($this->db->wurdeAusgefuehrt('/^INSERT INTO kurs_schueler/'));
    }

    public function testNameWirdAlsNachnamePipeVornameGespeichert(): void
    {
        $this->leereDatenbank();

        $this->importer()->importiere($this->datei(['Müller|Anna Lena|M|MA|LK1|M_Q2_LK1_MA|Q2|1|2025']));

        $this->assertSame('Müller|Anna Lena', $this->db->aufrufe('/^INSERT INTO kurs_schueler/')[0]['params'][1]);
    }

    public function testBestehendeDatensaetzeWerdenAktualisiertStattDoppeltAngelegt(): void
    {
        $this->db->onScalar('/SELECT id FROM stufen WHERE name/', 4);
        $this->db->onScalar('/SELECT id FROM halbjahre WHERE/', 5);
        $this->db->onScalar('/SELECT id FROM kurse WHERE/', 6);
        $this->db->onScalar('/SELECT id FROM kurs_schueler WHERE kurs_id = \? AND name_roh/', 7);

        $this->importer()->importiere($this->datei(['A|Anna|M|MA|LK1|M_Q2_LK1_MA|Q2|1|2025']));

        foreach (['stufen', 'halbjahre', 'kurse', 'kurs_schueler'] as $tabelle) {
            $this->assertFalse($this->db->wurdeAusgefuehrt("/^INSERT INTO $tabelle/"), "$tabelle nicht neu anlegen");
        }
        $this->assertSame([99, 5], $this->db->aufrufe('/^UPDATE halbjahre SET importiert_am/')[0]['params']);
        $this->assertSame(['M', 'LK', 'MA', 'Q2 M LK 1 MA', 6], $this->db->aufrufe('/^UPDATE kurse SET/')[0]['params']);
        $this->assertSame(['LK1', 7], $this->db->aufrufe('/^UPDATE kurs_schueler SET kursart/')[0]['params']);
    }

    // ------------------------------------------------------------------ Veraltete Prüflinge

    public function testNichtMehrVorhandenePruefllingeWerdenEntferntAberNurOhneAnwesenheitsdaten(): void
    {
        $this->db->onScalar('/SELECT id FROM kurse WHERE/', 6);
        $this->db->onRows('/SELECT id, name_roh FROM kurs_schueler WHERE kurs_id = \?/', [
            ['id' => 1, 'name_roh' => 'A|Anna'],       // noch in der Datei
            ['id' => 2, 'name_roh' => 'Weg|Willi'],    // weg, keine Anwesenheit → löschen
            ['id' => 3, 'name_roh' => 'Weg|Wilma'],    // weg, aber Anwesenheit erfasst → behalten
        ]);
        $this->db->on('/FROM anwesenheiten WHERE kurs_schueler_id/', fn (string $s, array $p) => $p[0] === 3
            ? FakeResult::scalar(1) : FakeResult::leer());
        $this->db->on('/^INSERT INTO (stufen|halbjahre)/', fn () => FakeResult::insert(1));

        $ergebnis = $this->importer()->importiere($this->datei(['A|Anna|M|MA|LK1|M_Q2_LK1_MA|Q2|1|2025']));

        $this->assertSame(1, $ergebnis['entfernt']);
        $loeschungen = $this->db->aufrufe('/^DELETE FROM kurs_schueler/');
        $this->assertCount(1, $loeschungen);
        $this->assertSame([2], $loeschungen[0]['params']);
    }

    // ------------------------------------------------------------------ Zuordnung nach dem Import

    public function testNachDemImportWirdZugeordnet(): void
    {
        $this->leereDatenbank();
        $this->db->onRows('/SELECT id, name_roh FROM kurs_schueler\s+WHERE kurs_id IN/', [['id' => 1, 'name_roh' => 'A|Anna']]);
        $this->db->onRows('/SELECT id, lehrer_kuerzel FROM kurse\s+WHERE id IN/', [['id' => 3, 'lehrer_kuerzel' => 'MA']]);
        $this->db->on('/FROM schueler_zuordnungen/', FakeResult::rows([['benutzer_id' => 12]]));
        $this->db->on('/FROM lehrer_zuordnungen/', FakeResult::rows([['benutzer_id' => 13]]));

        $this->importer()->importiere($this->datei(['A|Anna|M|MA|LK1|M_Q2_LK1_MA|Q2|1|2025']));

        $this->assertSame([12, 1], $this->db->aufrufe('/^UPDATE kurs_schueler SET schueler_id/')[0]['params']);
        $this->assertSame([13, 3], $this->db->aufrufe('/^UPDATE kurse SET lehrer_id/')[0]['params']);
    }

    // ------------------------------------------------------------------ Stufenleitung übernehmen

    /** @return array<string, array{string, string, ?string, ?string}> */
    public static function vorgaenger(): array
    {
        return [
            'Q2 folgt auf Q1 des Vorjahres' => ['Q2', '2025', 'Q1', '2024/2025'],
            'Q1 folgt auf EF des Vorjahres' => ['Q1', '2025', 'EF', '2024/2025'],
            'EF folgt auf Q2 des Vorjahres' => ['EF', '2025', 'Q2', '2024/2025'],
            'unbekannte Stufe ohne Vorgänger' => ['9a', '2025', null, null],
        ];
    }

    #[DataProvider('vorgaenger')]
    public function testNeueStufeErbtDieStufenleitungDerVorgaengerstufe(string $stufe, string $jahr, ?string $vorName, ?string $vorSchuljahr): void
    {
        $neu = false;
        $this->db->on('/SELECT id FROM stufen WHERE name = \? AND schuljahr = \?/', function (string $s, array $p) use (&$neu, $stufe, $vorName): FakeResult {
            if ($p[0] === $stufe) {
                return $neu ? FakeResult::scalar(50) : FakeResult::leer();
            }
            return $p[0] === $vorName ? FakeResult::scalar(40) : FakeResult::leer();
        });
        $this->db->on('/^INSERT INTO stufen/', function () use (&$neu): FakeResult {
            $neu = true;
            return FakeResult::insert(50);
        });
        $this->db->on('/^INSERT INTO halbjahre/', FakeResult::insert(60));
        $this->db->on('/^INSERT INTO kurse/', FakeResult::insert(70));
        $this->db->onRows('/SELECT sl.benutzer_id/', [['benutzer_id' => 3], ['benutzer_id' => 4]]);

        $this->importer()->importiere($this->datei(["A|Anna|M|MA|LK1|M_{$stufe}_LK1_MA|$stufe|1|$jahr"]));

        $erben = $this->db->aufrufe('/^INSERT IGNORE INTO stufenleitungen/');
        if ($vorName === null) {
            $this->assertSame([], $erben);
            return;
        }
        $this->assertSame([[3, 50], [4, 50]], array_column($erben, 'params'));
        $vorgaengerSuche = array_filter($this->db->aufrufe('/SELECT id FROM stufen WHERE name/'), fn ($e) => $e['params'][0] === $vorName);
        $this->assertSame([$vorName, $vorSchuljahr], array_values($vorgaengerSuche)[0]['params']);
    }

    public function testKeineUebernahmeWennDieVorgaengerstufeFehlt(): void
    {
        $this->leereDatenbank();

        $this->importer()->importiere($this->datei(['A|Anna|M|MA|LK1|M_Q2_LK1_MA|Q2|1|2025']));

        $this->assertFalse($this->db->wurdeAusgefuehrt('/INSERT IGNORE INTO stufenleitungen/'));
    }

    // ------------------------------------------------------------------ Anzeigename

    /** @return array<string, array{string, string, string, ?string, string}> */
    public static function anzeigenamen(): array
    {
        return [
            'Grundkurs'            => ['SP_Q2_GK1_SZ', 'SPA', 'GK', 'Sport', 'Q2 Sport GK 1 SZ'],
            'Leistungskurs Nr. 2'  => ['M_Q1_LK2_MA', 'M', 'LK', 'Mathematik', 'Q1 Mathematik LK 2 MA'],
            'Fach unbekannt'       => ['XY_EF_GK3_AB', 'XY', 'GK', null, 'EF XY GK 3 AB'],
            'ohne Nummer'          => ['D_Q2_GK_DE', 'D', 'GK', 'Deutsch', 'Q2 Deutsch GK DE'],
            'ohne Lehrerkürzel'    => ['D_Q2_GK2', 'D', 'GK', 'Deutsch', 'Q2 Deutsch GK 2'],
            'nur Kurskürzel'       => ['KURS', 'D', 'GK', 'Deutsch', 'Deutsch GK'],
        ];
    }

    #[DataProvider('anzeigenamen')]
    public function testGeneriereAnzeigename(string $kuerzel, string $fach, string $kursart, ?string $fachname, string $erwartet): void
    {
        $this->db->onScalar('/FROM fach_bezeichnungen/', $fachname ?? false);

        $this->assertSame($erwartet, GomstImporter::generiereAnzeigename($kuerzel, $fach, $kursart, $this->db));
        $this->assertSame([strtoupper($fach)], $this->db->log[0]['params'], 'Fachkürzel wird groß nachgeschlagen');
    }
}
