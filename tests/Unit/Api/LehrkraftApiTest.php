<?php

declare(strict_types=1);

namespace Klausurplan\Tests\Unit\Api;

use Klausurplan\Api\LehrkraftApi;
use Klausurplan\Tests\Support\FakeResult;
use Klausurplan\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class LehrkraftApiTest extends TestCase
{
    // ------------------------------------------------------------------ Klausuren lesen

    /** Die Klausurabfrage hat keinen Zugriffsfilter (WHERE-Teil nach dem letzten JOIN fehlt). */
    private function assertOhneZugriffsfilter(string $sql): void
    {
        $this->assertMatchesRegularExpression('/lb\.id = kurs\.lehrer_id\s+ORDER BY/', $sql);
    }

    private function klausurAbfrage(): array
    {
        $abfragen = $this->db->aufrufe('/FROM klausuren k\s+JOIN kurse kurs/');
        $this->assertNotEmpty($abfragen, 'Klausurabfrage wurde nicht gestellt');
        return $abfragen[0];
    }

    public function testKlausurenErfordernEineDerDreiRollen(): void
    {
        $this->alsBenutzer(5, ['schueler']);

        $this->erwarteZugriffVerweigert(fn () => LehrkraftApi::getKlausuren());
    }

    public function testReineLehrkraftSiehtNurEigeneKurse(): void
    {
        $this->alsBenutzer(5, ['lehrkraft']);

        LehrkraftApi::getKlausuren();

        $q = $this->klausurAbfrage();
        $this->assertStringContainsString('WHERE kurs.lehrer_id = ?', $q['sql']);
        $this->assertSame([5, 5], $q['params'], 'ist_eigene_sl-Subquery und Lehrerfilter');
    }

    public function testLehrkraftKannMitAlleDenFilterNichtAushebeln(): void
    {
        $this->alsBenutzer(5, ['lehrkraft']);
        $_GET['alle'] = '1';

        LehrkraftApi::getKlausuren();

        $this->assertStringContainsString('kurs.lehrer_id = ?', $this->klausurAbfrage()['sql']);
    }

    public function testStufenleitungSiehtStandardmaessigEigeneStufenUndEigeneKurse(): void
    {
        $this->alsBenutzer(5, ['stufenleitung', 'lehrkraft']);

        LehrkraftApi::getKlausuren();

        $q = $this->klausurAbfrage();
        $this->assertStringContainsString('sl_f.stufe_id = s.id AND sl_f.benutzer_id = ?', $q['sql']);
        $this->assertStringContainsString('OR kurs.lehrer_id = ?', $q['sql']);
        $this->assertSame([5, 5, 5], $q['params']);
    }

    public function testStufenleitungKannAlleStufenEinblenden(): void
    {
        $this->alsBenutzer(5, ['stufenleitung']);
        $_GET['alle'] = '1';

        LehrkraftApi::getKlausuren();

        $q = $this->klausurAbfrage();
        $this->assertOhneZugriffsfilter($q['sql']);
        $this->assertSame([5], $q['params']);
    }

    public function testAdminOhneStufenleitungSiehtAlles(): void
    {
        $this->alsBenutzer(5, ['admin']);
        $this->db->onRows('/FROM klausuren k\s+JOIN kurse kurs/', [['id' => 1, 'ist_eigene_sl' => 0]]);

        $klausuren = LehrkraftApi::getKlausuren();

        $this->assertOhneZugriffsfilter($this->klausurAbfrage()['sql']);
        $this->assertSame(1, $klausuren[0]['ist_eigene_sl'], 'Admin gilt für alle Stufen als zuständig');
    }

    public function testAdminMitStufenleitungRolleSiehtStandardmaessigNurEigeneStufen(): void
    {
        $this->alsBenutzer(5, ['admin', 'stufenleitung']);

        LehrkraftApi::getKlausuren();
        $this->assertStringContainsString('sl_f.stufe_id', $this->klausurAbfrage()['sql']);

        $this->db->log = [];
        $_GET['alle'] = '1';
        LehrkraftApi::getKlausuren();
        $this->assertOhneZugriffsfilter($this->klausurAbfrage()['sql']);
    }

    public function testHalbjahrFilter(): void
    {
        $this->alsBenutzer(5, ['admin']);
        $_GET['halbjahr_id'] = '7';

        LehrkraftApi::getKlausuren();

        $q = $this->klausurAbfrage();
        $this->assertStringContainsString('WHERE kurs.halbjahr_id = ?', $q['sql']);
        $this->assertSame([5, 7], $q['params']);
    }

    public function testNachschreiberWerdenAnDieKlausurenGehaengt(): void
    {
        $this->alsBenutzer(5, ['admin']);
        $_GET['nachschreiber'] = '1';
        $this->db->onRows('/FROM klausuren k\s+JOIN kurse kurs/', [['id' => 10], ['id' => 11]]);
        $this->db->onRows('/FROM anwesenheiten a\s+JOIN kurs_schueler ks/', [
            ['klausur_id' => 10, 'kurs_schueler_id' => 1, 'name_roh' => 'A|B'],
            ['klausur_id' => 10, 'kurs_schueler_id' => 2, 'name_roh' => 'C|D'],
        ]);

        $klausuren = LehrkraftApi::getKlausuren();

        $this->assertCount(2, $klausuren[0]['nachschreiber']);
        $this->assertSame([], $klausuren[1]['nachschreiber']);
        $this->assertSame([10, 11], $this->db->aufrufe('/FROM anwesenheiten a\s+JOIN kurs_schueler ks/')[0]['params']);
    }

    public function testOhneKlausurenKeineNachschreiberAbfrage(): void
    {
        $this->alsBenutzer(5, ['admin']);
        $_GET['nachschreiber'] = '1';

        $this->assertSame([], LehrkraftApi::getKlausuren());
        $this->assertFalse($this->db->wurdeAusgefuehrt('/FROM anwesenheiten a\s+JOIN kurs_schueler ks/'));
    }

    // ------------------------------------------------------------------ Klausuren anlegen/ändern

    private function alsStufenleitung(): void
    {
        $this->alsBenutzer(5, ['stufenleitung']);
    }

    public function testKlausurAnlegenIstNurFuerAdminUndStufenleitung(): void
    {
        $this->alsBenutzer(5, ['lehrkraft']);

        $this->erwarteZugriffVerweigert(fn () => LehrkraftApi::postKlausur(['kurs_id' => 1]));
        $this->erwarteZugriffVerweigert(fn () => LehrkraftApi::putKlausur(1, []));
        $this->erwarteZugriffVerweigert(fn () => LehrkraftApi::deleteKlausur(1));
    }

    public function testKlausurAnlegenOhneKursId(): void
    {
        $this->alsStufenleitung();

        $this->erwarteFehler(fn () => LehrkraftApi::postKlausur([]), 'kurs_id fehlt', 400);
    }

    public function testKlausurAnlegenFuerUnbekanntenKurs(): void
    {
        $this->alsStufenleitung();

        $this->erwarteFehler(fn () => LehrkraftApi::postKlausur(['kurs_id' => 9]), 'Kurs 9 nicht gefunden', 404);
    }

    public function testStufenleitungDarfKlausurenFuerJedeStufeAnlegen(): void
    {
        $this->alsStufenleitung();
        $this->db->onScalar('/SELECT id FROM kurse WHERE id/', 9);
        $this->db->onScalar('/SELECT COALESCE\(MAX\(klausur_nr\)/', 3);
        $this->db->on('/^INSERT INTO klausuren/', FakeResult::insert(88));

        $r = LehrkraftApi::postKlausur(['kurs_id' => '9', 'termin_datum' => '31.01.2026', 'termin_uhrzeit' => '8:00', 'dauer_minuten' => '90']);

        $this->assertSame(['id' => 88, 'klausur_nr' => 3], $r);
        $this->assertSame([9, 3, '2026-01-31', '08:00:00', 90, 5], $this->db->aufrufe('/^INSERT INTO klausuren/')[0]['params']);
        $this->assertFalse($this->db->wurdeAusgefuehrt('/stufenleitungen/'), 'keine Stufenprüfung beim Anlegen');
    }

    public function testKlausurAnlegenMitVorgegebenerNummerUndIsoDatum(): void
    {
        $this->alsStufenleitung();
        $this->db->onScalar('/SELECT id FROM kurse WHERE id/', 9);
        $this->db->on('/^INSERT INTO klausuren/', FakeResult::insert(88));

        $r = LehrkraftApi::postKlausur(['kurs_id' => 9, 'klausur_nr' => 2, 'termin_datum' => '2026-02-03']);

        $this->assertSame(2, $r['klausur_nr']);
        $this->assertSame([9, 2, '2026-02-03', null, null, 5], $this->db->aufrufe('/^INSERT INTO klausuren/')[0]['params']);
        $this->assertFalse($this->db->wurdeAusgefuehrt('/MAX\(klausur_nr\)/'));
    }

    public function testKlausurAnlegenIgnoriertUngueltigeTermine(): void
    {
        $this->alsStufenleitung();
        $this->db->onScalar('/SELECT id FROM kurse WHERE id/', 9);
        $this->db->onScalar('/SELECT COALESCE\(MAX\(klausur_nr\)/', 1);
        $this->db->on('/^INSERT INTO klausuren/', FakeResult::insert(88));

        LehrkraftApi::postKlausur(['kurs_id' => 9, 'termin_datum' => '2026-02-30', 'termin_uhrzeit' => '25:00', 'dauer_minuten' => -5]);

        $this->assertSame([9, 1, null, null, null, 5], $this->db->aufrufe('/^INSERT INTO klausuren/')[0]['params']);
    }

    public function testKlausurBearbeiten(): void
    {
        $this->alsStufenleitung();
        $this->db->onScalar('/SELECT id FROM klausuren WHERE id/', 3);

        $r = LehrkraftApi::putKlausur(3, ['termin_datum' => '2026-05-05', 'termin_uhrzeit' => '09:15', 'dauer_minuten' => 45]);

        $this->assertSame(['ok' => true], $r);
        $this->assertSame(['2026-05-05', '09:15:00', 45, 3], $this->db->aufrufe('/^UPDATE klausuren/')[0]['params']);
    }

    public function testKlausurBearbeitenLoeschtTermineBeiLeerenFeldern(): void
    {
        $this->alsStufenleitung();
        $this->db->onScalar('/SELECT id FROM klausuren WHERE id/', 3);

        LehrkraftApi::putKlausur(3, ['termin_datum' => '', 'termin_uhrzeit' => null]);

        $this->assertSame([null, null, null, 3], $this->db->aufrufe('/^UPDATE klausuren/')[0]['params']);
    }

    public function testUnbekannteKlausurBearbeitenUndLoeschen(): void
    {
        $this->alsStufenleitung();

        $this->erwarteFehler(fn () => LehrkraftApi::putKlausur(3, []), 'Klausur 3 nicht gefunden', 404);
        $this->erwarteFehler(fn () => LehrkraftApi::deleteKlausur(3), 'Klausur 3 nicht gefunden', 404);
    }

    public function testKlausurLoeschen(): void
    {
        $this->alsStufenleitung();
        $this->db->onScalar('/SELECT id FROM klausuren WHERE id/', 3);

        $this->assertSame(['ok' => true], LehrkraftApi::deleteKlausur(3));
        $this->assertSame([3], $this->db->aufrufe('/^DELETE FROM klausuren/')[0]['params']);
    }

    // ------------------------------------------------------------------ Excel-Paste-Import

    private function pasteZeile(string $kurs, string $datum = '', string $uhrzeit = '', string $dauer = ''): array
    {
        return ['Kurs' => $kurs, 'Datum' => $datum, 'Uhrzeit' => $uhrzeit, 'Dauer' => $dauer];
    }

    /** Bekannte Kurse: kurs_kuerzel → id. */
    private function kurse(array $kurse): void
    {
        $this->db->on('/kurs_kuerzel = \?/', fn (string $s, array $p) => isset($kurse[$p[0]])
            ? FakeResult::scalar($kurse[$p[0]]) : FakeResult::leer());
    }

    public function testPasteImportLegtKlausurenAn(): void
    {
        $this->alsStufenleitung();
        $this->kurse(['SP_Q2_GK1_SZ' => 4]);
        $this->db->onScalar('/SELECT COALESCE\(MAX\(klausur_nr\)/', 1);

        $r = LehrkraftApi::postPasteImport([$this->pasteZeile('SP_Q2_GK1_SZ', '31.01.2026', '8:00', '90')]);

        $this->assertSame(['erstellt' => 1, 'aktualisiert' => 0, 'fehler' => []], $r);
        $this->assertSame([4, 1, '2026-01-31', '08:00:00', 90, 5], $this->db->aufrufe('/^INSERT INTO klausuren/')[0]['params']);
    }

    public function testPasteImportAktualisiertKlausurMitGleichemDatum(): void
    {
        $this->alsStufenleitung();
        $this->kurse(['X' => 4]);
        $this->db->onScalar('/WHERE kurs_id = \? AND termin_datum = \?/', 12);

        $r = LehrkraftApi::postPasteImport([$this->pasteZeile('X', '31.01.2026', '9:00', '60')]);

        $this->assertSame(1, $r['aktualisiert']);
        $this->assertSame(['09:00:00', 60, 12], $this->db->aufrufe('/^UPDATE klausuren SET termin_uhrzeit/')[0]['params']);
        $this->assertFalse($this->db->wurdeAusgefuehrt('/^INSERT/'));
    }

    public function testPasteImportFuelltEineKlausurOhneDatumAuf(): void
    {
        $this->alsStufenleitung();
        $this->kurse(['X' => 4]);
        $this->db->onScalar('/termin_datum IS NULL ORDER BY klausur_nr/', 13);

        $r = LehrkraftApi::postPasteImport([$this->pasteZeile('X', '31.01.2026')]);

        $this->assertSame(1, $r['aktualisiert']);
        $this->assertSame(['2026-01-31', null, null, 13], $this->db->aufrufe('/^UPDATE klausuren\s+SET termin_datum/')[0]['params']);
    }

    public function testPasteImportOhneDatumLegtBeiVorhandenerDatumsloserKlausurNichtsNeuesAn(): void
    {
        $this->alsStufenleitung();
        $this->kurse(['X' => 4]);
        $this->db->onScalar('/termin_datum IS NULL ORDER BY klausur_nr/', 13);

        $r = LehrkraftApi::postPasteImport([$this->pasteZeile('X')]);

        $this->assertSame(['erstellt' => 0, 'aktualisiert' => 1, 'fehler' => []], $r);
    }

    public function testPasteImportMeldetUnbekannteKurseUndFormatfehler(): void
    {
        $this->alsStufenleitung();
        $this->kurse(['X' => 4]);
        $this->db->onScalar('/SELECT COALESCE\(MAX\(klausur_nr\)/', 1);

        $r = LehrkraftApi::postPasteImport([
            $this->pasteZeile('X', 'gestern'),        // Formatfehler (Parser)
            $this->pasteZeile('GIBT_ES_NICHT'),       // unbekannter Kurs
            $this->pasteZeile('X', '01.02.2026'),     // gültig
        ]);

        $this->assertSame(1, $r['erstellt']);
        $this->assertCount(2, $r['fehler']);
        $this->assertStringContainsString('Ungültiges Datum', $r['fehler'][0]['meldung']);
        $this->assertSame('Kurs "GIBT_ES_NICHT" nicht gefunden.', $r['fehler'][1]['meldung']);
    }

    public function testPasteImportMitHalbjahrSuchtNurDort(): void
    {
        $this->alsStufenleitung();
        $this->db->onScalar('/SELECT 1 FROM halbjahre WHERE id/', 1);
        $this->db->on('/SELECT id FROM kurse WHERE kurs_kuerzel = \? AND halbjahr_id IN/', fn (string $s, array $p) => $p === ['X', 6]
            ? FakeResult::scalar(4) : FakeResult::leer());
        $this->db->onScalar('/SELECT COALESCE\(MAX\(klausur_nr\)/', 1);

        $r = LehrkraftApi::postPasteImport([$this->pasteZeile('X', '01.02.2026'), $this->pasteZeile('NUR_ANDERSWO', '01.02.2026')], 6);

        $this->assertSame(1, $r['erstellt']);
        $this->assertSame('Kurs "NUR_ANDERSWO" nicht gefunden.', $r['fehler'][0]['meldung']);
        $this->assertFalse($this->db->wurdeAusgefuehrt('/ORDER BY s\.schuljahr DESC, h\.abschnitt DESC\s+LIMIT 1/'), 'kein Rückfall auf andere Halbjahre');
    }

    public function testPasteImportMitUnbekanntemHalbjahr(): void
    {
        $this->alsStufenleitung();

        $this->erwarteFehler(fn () => LehrkraftApi::postPasteImport([], 99), 'Halbjahr 99 nicht gefunden', 404);
    }

    public function testPasteImportOhneHalbjahrBevorzugtDasAktuelleUndFaelltZurueck(): void
    {
        $this->alsStufenleitung();
        $this->db->onRows('/SELECT s\.schuljahr, MAX\(h\.abschnitt\)/', [['schuljahr' => '2025/2026', 'abschnitt' => 1]]);
        $this->db->onRows('/SELECT h\.id FROM halbjahre h/', [['id' => 6], ['id' => 7]]);
        $this->db->on('/SELECT id FROM kurse WHERE kurs_kuerzel = \? AND halbjahr_id IN/', FakeResult::leer());
        $this->db->onScalar('/ORDER BY s\.schuljahr DESC, h\.abschnitt DESC\s+LIMIT 1/', 4); // Rückfall
        $this->db->onScalar('/SELECT COALESCE\(MAX\(klausur_nr\)/', 1);

        $r = LehrkraftApi::postPasteImport([$this->pasteZeile('X', '01.02.2026')]);

        $this->assertSame(1, $r['erstellt']);
        $this->assertSame(['X', 6, 7], $this->db->aufrufe('/halbjahr_id IN/')[0]['params']);
    }

    // ------------------------------------------------------------------ Kurse & Vorlage

    public function testGetKurseLiefertAlleStufenMitZustaendigkeitsflag(): void
    {
        $this->alsStufenleitung();
        $rows = [['id' => 1, 'stufe' => 'Q2', 'ist_eigene_sl' => 1], ['id' => 2, 'stufe' => 'Q1', 'ist_eigene_sl' => 0]];
        $this->db->onRows('/FROM kurse k\s+JOIN halbjahre h/', $rows);

        $this->assertSame($rows, LehrkraftApi::getKurse());
        $this->assertStringNotContainsString('JOIN stufenleitungen', $this->db->log[0]['sql'], 'kein Stufenfilter');
        $this->assertSame([5], $this->db->log[0]['params']);
    }

    public function testGetKurseNurFuerAdminUndStufenleitung(): void
    {
        $this->alsBenutzer(5, ['lehrkraft']);

        $this->erwarteZugriffVerweigert(fn () => LehrkraftApi::getKurse());
    }

    private function vorlage(): string
    {
        return $this->ausgabe(fn () => LehrkraftApi::downloadVorlage());
    }

    public function testVorlageFuerEinHalbjahr(): void
    {
        $this->alsStufenleitung();
        $_GET['halbjahr_id'] = '6';
        $this->db->onRows('/SELECT s\.name, s\.schuljahr, h\.abschnitt/', [['name' => 'Q2', 'schuljahr' => '2025/2026', 'abschnitt' => 1]]);
        $this->db->onRows('/SELECT k\.kurs_kuerzel, k\.anzeigename/', [
            ['kurs_kuerzel' => 'SP_Q2_GK1_SZ', 'anzeigename' => 'Q2 Sport GK 1 SZ', 'anzahl' => 12],
            ['kurs_kuerzel' => 'M_Q2_LK1_MA', 'anzeigename' => 'Q2 Mathe "LK" 1', 'anzahl' => 0],
        ]);

        $csv = $this->vorlage();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'UTF-8-BOM für Excel');
        $zeilen = explode("\n", trim(substr($csv, 3)));
        $this->assertSame('Kurs;Anzeigename;TN;Datum;Uhrzeit;Dauer', $zeilen[0]);
        $this->assertSame('SP_Q2_GK1_SZ;"Q2 Sport GK 1 SZ";12;;;', $zeilen[1]);
        $this->assertSame('M_Q2_LK1_MA;"Q2 Mathe ""LK"" 1";0;;;', $zeilen[2]);
        $this->assertSame([6], $this->db->aufrufe('/SELECT k\.kurs_kuerzel/')[0]['params']);
    }

    public function testVorlageFuerUnbekanntesHalbjahr(): void
    {
        $this->alsStufenleitung();
        $_GET['halbjahr_id'] = '99';

        $this->erwarteFehler(fn () => LehrkraftApi::downloadVorlage(), 'Halbjahr 99 nicht gefunden', 404);
    }

    public function testVorlageOhneAngabeNimmtDasAktuelleHalbjahrMitKursen(): void
    {
        $this->alsStufenleitung();
        $this->db->onRows('/SELECT s\.schuljahr, MAX\(h\.abschnitt\)/', [['schuljahr' => '2025/2026', 'abschnitt' => 2]]);
        $this->db->onRows('/SELECT h\.id FROM halbjahre h/', [['id' => 8]]);
        $this->db->onRows('/SELECT k\.kurs_kuerzel/', [['kurs_kuerzel' => 'X', 'anzeigename' => 'Y', 'anzahl' => 1]]);

        $csv = $this->vorlage();

        $this->assertStringContainsString('X;Y;1;;;', $csv);
        $this->assertSame(['2025/2026', 2], $this->db->aufrufe('/SELECT h\.id FROM halbjahre h/')[0]['params']);
        $this->assertStringContainsString('EXISTS (SELECT 1 FROM kurse k WHERE k.halbjahr_id = h.id)', $this->db->aufrufe('/SELECT s\.schuljahr, MAX/')[0]['sql']);
    }

    public function testVorlageOhneKurseEnthaeltNurDieKopfzeile(): void
    {
        $this->alsStufenleitung();

        $csv = $this->vorlage();

        $this->assertSame("\xEF\xBB\xBFKurs;Anzeigename;TN;Datum;Uhrzeit;Dauer\n", $csv);
    }

    // ------------------------------------------------------------------ Nachschreibtermine

    public function testNachschreibterminePruefenDieRolle(): void
    {
        $this->alsBenutzer(5, ['schueler']);

        $this->erwarteZugriffVerweigert(fn () => LehrkraftApi::getNachschreibtermine());
        $this->erwarteZugriffVerweigert(fn () => LehrkraftApi::meineNachschreibtermine());
        $this->alsBenutzer(5, ['lehrkraft']);
        $this->erwarteZugriffVerweigert(fn () => LehrkraftApi::postNachschreibtermin([]));
        $this->erwarteZugriffVerweigert(fn () => LehrkraftApi::putNachschreibtermin(1, []));
        $this->erwarteZugriffVerweigert(fn () => LehrkraftApi::postNachschreibterminKlausuren(1, []));
        $this->erwarteZugriffVerweigert(fn () => LehrkraftApi::deleteNachschreibtermin(1));
    }

    public function testKeineTermineKeineFolgeabfrage(): void
    {
        $this->alsBenutzer(5, ['lehrkraft']);

        $this->assertSame([], LehrkraftApi::getNachschreibtermine());
        $this->assertCount(1, $this->db->log);
    }

    public function testNachschreibtermineWerdenMitKlausurenUndNachschreiberGruppiert(): void
    {
        $this->alsBenutzer(5, ['lehrkraft']);
        $this->db->onRows('/FROM nachschreibtermine n/', [['id' => 1, 'termin_datum' => '2026-03-01'], ['id' => 2, 'termin_datum' => null]]);
        $this->db->onRows('/FROM nachschreib_zuordnungen nz/', [
            ['nachschreibtermin_id' => 1, 'klausur_id' => 10, 'klausur_nr' => 1, 'kurs_anzeigename' => 'Q2 Sport', 'kurs_schueler_id' => 100,
             'name_roh' => 'A|B', 'benutzer_id' => 7, 'vorname' => 'B', 'nachname' => 'A', 'entschuldigt' => 1],
            ['nachschreibtermin_id' => 1, 'klausur_id' => 10, 'klausur_nr' => 1, 'kurs_anzeigename' => 'Q2 Sport', 'kurs_schueler_id' => 101,
             'name_roh' => 'C|D', 'benutzer_id' => null, 'vorname' => null, 'nachname' => null, 'entschuldigt' => null],
            ['nachschreibtermin_id' => 1, 'klausur_id' => 11, 'klausur_nr' => 2, 'kurs_anzeigename' => 'Q2 Mathe', 'kurs_schueler_id' => null,
             'name_roh' => null, 'benutzer_id' => null, 'vorname' => null, 'nachname' => null, 'entschuldigt' => null],
        ]);

        $termine = LehrkraftApi::getNachschreibtermine();

        $this->assertCount(2, $termine[0]['klausuren']);
        $this->assertCount(2, $termine[0]['klausuren'][0]['nachschreiber']);
        $this->assertSame([], $termine[0]['klausuren'][1]['nachschreiber'], 'Klausur ohne Fehlende');
        $this->assertSame(2, $termine[0]['klausuren'][1]['klausur_nr']);
        $this->assertSame([], $termine[1]['klausuren'], 'Termin ohne Verknüpfung');
    }

    public function testNachschreibterminAnlegen(): void
    {
        $this->alsBenutzer(5, ['stufenleitung']);
        $this->db->on('/^INSERT INTO nachschreibtermine/', FakeResult::insert(4));

        $r = LehrkraftApi::postNachschreibtermin(['termin_datum' => '01.03.2026', 'termin_uhrzeit' => '13:30', 'bemerkung' => ' Raum 12 ']);

        $this->assertSame(['id' => 4], $r);
        $this->assertSame(['2026-03-01', '13:30:00', 'Raum 12', 5], $this->db->aufrufe('/^INSERT INTO nachschreibtermine/')[0]['params']);
    }

    public function testNachschreibterminOhneAngaben(): void
    {
        $this->alsBenutzer(5, ['admin']);
        $this->db->on('/^INSERT INTO nachschreibtermine/', FakeResult::insert(4));

        LehrkraftApi::postNachschreibtermin(['bemerkung' => '']);

        $this->assertSame([null, null, null, 5], $this->db->aufrufe('/^INSERT INTO nachschreibtermine/')[0]['params']);
    }

    public function testNachschreibterminBearbeiten(): void
    {
        $this->alsBenutzer(5, ['stufenleitung']);
        $this->erwarteFehler(fn () => LehrkraftApi::putNachschreibtermin(4, []), 'Nachschreibtermin 4 nicht gefunden', 404);

        $this->db->onScalar('/SELECT id FROM nachschreibtermine WHERE id/', 4);
        $this->assertSame(['ok' => true], LehrkraftApi::putNachschreibtermin(4, ['termin_datum' => '2026-03-02', 'bemerkung' => 'x']));
        $this->assertSame(['2026-03-02', null, 'x', 4], $this->db->aufrufe('/^UPDATE nachschreibtermine/')[0]['params']);
    }

    public function testNachschreibterminKlausurenWerdenVollstaendigErsetzt(): void
    {
        $this->alsBenutzer(5, ['stufenleitung']);
        $this->erwarteFehler(fn () => LehrkraftApi::postNachschreibterminKlausuren(4, []), 'nicht gefunden', 404);

        $this->db->onScalar('/SELECT id FROM nachschreibtermine WHERE id/', 4);
        $r = LehrkraftApi::postNachschreibterminKlausuren(4, ['klausur_ids' => ['10', 11]]);

        $this->assertSame(['ok' => true, 'verknuepft' => 2], $r);
        $this->assertSame([4], $this->db->aufrufe('/^DELETE FROM nachschreib_zuordnungen/')[0]['params']);
        $this->assertSame([10, 4, 11, 4], $this->db->aufrufe('/^INSERT INTO nachschreib_zuordnungen/')[0]['params']);
    }

    public function testNachschreibterminKlausurenLeerenDieVerknuepfung(): void
    {
        $this->alsBenutzer(5, ['stufenleitung']);
        $this->db->onScalar('/SELECT id FROM nachschreibtermine WHERE id/', 4);

        $r = LehrkraftApi::postNachschreibterminKlausuren(4, []);

        $this->assertSame(0, $r['verknuepft']);
        $this->assertFalse($this->db->wurdeAusgefuehrt('/^INSERT INTO nachschreib_zuordnungen/'));
    }

    public function testMeineNachschreibtermine(): void
    {
        $this->alsBenutzer(5, ['lehrkraft']);
        $rows = [['id' => 1, 'kurs_anzeigename' => 'Q2 Sport', 'nachschreiber_anzahl' => 2]];
        $this->db->onRows('/FROM nachschreib_zuordnungen nz/', $rows);

        $this->assertSame($rows, LehrkraftApi::meineNachschreibtermine());
        $this->assertSame([5], $this->db->log[0]['params']);
    }

    public function testNachschreibterminLoeschen(): void
    {
        $this->alsBenutzer(5, ['stufenleitung']);
        $this->erwarteFehler(fn () => LehrkraftApi::deleteNachschreibtermin(4), 'nicht gefunden', 404);

        $this->db->onScalar('/SELECT id FROM nachschreibtermine WHERE id/', 4);
        $this->assertSame(['ok' => true], LehrkraftApi::deleteNachschreibtermin(4));
        $this->assertSame([4], $this->db->aufrufe('/^DELETE FROM nachschreibtermine/')[0]['params']);
    }
}
