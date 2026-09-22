<?php

declare(strict_types=1);

namespace Klausurplan\Tests\Integration;

use Klausurplan\Api\StufenleitungApi;
use Klausurplan\Import\GomsthImporter;
use Klausurplan\Tests\Support\IntegrationTestCase;

/** GOMSTH-Import, automatisches Matching und dauerhafte, korrigierbare Zuordnungen (echte SQL-Abfragen). */
final class ImportUndZuordnungTest extends IntegrationTestCase
{
    private int $sl;
    private int $max;
    private int $eva;
    private int $uwe;
    private int $anna;
    private array $tempDateien = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sl   = $this->fx->benutzer('Sarah', 'Leitung (SL)', ['lehrkraft', 'stufenleitung'], 'SL');
        $this->anna = $this->fx->benutzer('Anna', 'Lehrer (SZ)', ['lehrkraft'], 'SZ', 'sz@example.org');
        $this->max  = $this->fx->benutzer('Max', 'Mustermann', ['schueler'], null, null, 'Q2');
        $this->eva  = $this->fx->benutzer('Eva', 'Falsch', ['schueler'], null, null, 'Q2');
        $this->uwe  = $this->fx->benutzer('Uwe', 'Konto', ['schueler'], null, null, 'Q2');
        $this->alsBenutzer($this->sl, ['stufenleitung', 'lehrkraft']);
    }

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->tempDateien, 'file_exists'));
        parent::tearDown();
    }

    private const KOPF = 'Nachname|Vorname|Fach|Fachlehrer|Kursart|Kurs|Jahrgang|Abschnitt|Jahr';

    private function datei(): string
    {
        return "\xEF\xBB\xBF" . implode("\r\n", [
            self::KOPF,
            'Mustermann|Max|SPA|SZ|GKS|SPA_Q2_GK1_SZ|Q2|1|2025',
            'Schüler|Eva|SPA|SZ|GKS|SPA_Q2_GK1_SZ|Q2|1|2025',
            'Zuordnung|Uwe|SPA|SZ|GKS|SPA_Q2_GK1_SZ|Q2|1|2025',
            'Mustermann|Max|D|ZZ|GKS|D_Q1_GK1_ZZ|Q1|1|2025',
            'Zuordnung|Uwe|D|ZZ|GKS|D_Q1_GK1_ZZ|Q1|1|2025',
            'Ignoriert|Ida|D|ZZ|GKM|D_Q1_GM1_ZZ|Q1|1|2025',
        ]) . "\r\n";
    }

    private function importiere(?string $inhalt = null): array
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'gomsth');
        file_put_contents($tmp, $inhalt ?? $this->datei());
        $this->tempDateien[] = $tmp;
        $_FILES = ['datei' => ['tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK]];
        return StufenleitungApi::gomsthImport();
    }

    private function alleHalbjahreLoeschen(): void
    {
        foreach ($this->fx->zeilen('SELECT id FROM halbjahre') as $hj) {
            StufenleitungApi::deleteHalbjahr((int) $hj['id']);
        }
    }

    private function offen(): int
    {
        return $this->fx->zaehle('SELECT COUNT(*) FROM kurs_schueler WHERE schueler_id IS NULL');
    }

    // ------------------------------------------------------------------ Import

    public function testImportLegtStrukturAn(): void
    {
        $ergebnis = $this->importiere();

        $this->assertSame(2, $ergebnis['kurse'], 'GKM wird übersprungen');
        $this->assertSame(2, $ergebnis['halbjahre']);
        $this->assertSame(5, $ergebnis['schueler']);
        $this->assertSame(['Q1', 'Q2'], array_column($this->fx->zeilen('SELECT name FROM stufen ORDER BY name'), 'name'));
        $this->assertSame('Q2 Sport GK 1 SZ', $this->fx->wert("SELECT anzeigename FROM kurse WHERE kurs_kuerzel = 'SPA_Q2_GK1_SZ'"), 'Anzeigename aus fach_bezeichnungen');
        $this->assertSame('GK', $this->fx->wert("SELECT kursart FROM kurse WHERE kurs_kuerzel = 'SPA_Q2_GK1_SZ'"));
        $this->assertSame('GKS', $this->fx->wert("SELECT kursart FROM kurs_schueler WHERE name_roh = 'Mustermann|Max' LIMIT 1"));
    }

    public function testAutomatischesMatchingFuerSchuelerUndLehrkraefte(): void
    {
        $this->importiere();

        $this->assertSame($this->max, (int) $this->fx->wert("SELECT schueler_id FROM kurs_schueler WHERE name_roh = 'Mustermann|Max' LIMIT 1"));
        $this->assertSame($this->anna, (int) $this->fx->wert("SELECT lehrer_id FROM kurse WHERE kurs_kuerzel = 'SPA_Q2_GK1_SZ'"), 'Kürzel SZ → Anna');
        $this->assertNull($this->fx->wert("SELECT lehrer_id FROM kurse WHERE kurs_kuerzel = 'D_Q1_GK1_ZZ'"), 'Kürzel ZZ unbekannt');
        $this->assertSame(['Schüler|Eva', 'Zuordnung|Uwe'], array_column(
            StufenleitungApi::getZuordnungen()['schueler_gomsth'], 'name_roh'), 'offen: kein passendes Konto');
    }

    public function testWiederholterImportIstIdempotent(): void
    {
        $this->importiere();
        $vorher = [$this->fx->zaehle('SELECT COUNT(*) FROM kurse'), $this->fx->zaehle('SELECT COUNT(*) FROM kurs_schueler'), $this->fx->zaehle('SELECT COUNT(*) FROM halbjahre')];

        $this->importiere();

        $this->assertSame($vorher, [$this->fx->zaehle('SELECT COUNT(*) FROM kurse'), $this->fx->zaehle('SELECT COUNT(*) FROM kurs_schueler'), $this->fx->zaehle('SELECT COUNT(*) FROM halbjahre')]);
    }

    public function testNichtMehrVorhandenePruefllingeWerdenEntferntAberNurOhneAnwesenheit(): void
    {
        $this->importiere();
        $klausur = $this->fx->klausur((int) $this->fx->wert("SELECT id FROM kurse WHERE kurs_kuerzel = 'SPA_Q2_GK1_SZ'"), '2026-01-01');
        $eva = (int) $this->fx->wert("SELECT id FROM kurs_schueler WHERE name_roh = 'Schüler|Eva'");
        $this->fx->anwesenheit($klausur, $eva, 'fehlend');

        // Neue Datei: nur noch Max im Sportkurs
        $ergebnis = $this->importiere("\xEF\xBB\xBF" . self::KOPF . "\r\nMustermann|Max|SPA|SZ|GKS|SPA_Q2_GK1_SZ|Q2|1|2025\r\n");

        $this->assertSame(1, $ergebnis['entfernt'], 'Uwe entfernt; Eva hat Anwesenheitsdaten und bleibt');
        $namen = array_column($this->fx->zeilen("SELECT name_roh FROM kurs_schueler WHERE kurs_id = (SELECT id FROM kurse WHERE kurs_kuerzel = 'SPA_Q2_GK1_SZ') ORDER BY 1"), 'name_roh');
        $this->assertSame(['Mustermann|Max', 'Schüler|Eva'], $namen);
    }

    public function testNeueStufeErbtDieStufenleitungDerVorgaengerstufe(): void
    {
        $q1Vorjahr = $this->fx->stufe('Q1', '2024/2025');
        $this->fx->stufenleitung($this->anna, $q1Vorjahr);
        $this->db->prepare("INSERT INTO rollen (benutzer_id, rolle) VALUES (?, 'stufenleitung')")->execute([$this->anna]);

        $this->importiere();

        $q2 = (int) $this->fx->wert("SELECT id FROM stufen WHERE name = 'Q2' AND schuljahr = '2025/2026'");
        $this->assertSame(1, $this->fx->zaehle('SELECT COUNT(*) FROM stufenleitungen WHERE benutzer_id = ? AND stufe_id = ?', [$this->anna, $q2]));
    }

    // ------------------------------------------------------------------ Dauerhafte Zuordnung

    public function testZuordnungUeberlebtDasLoeschenUndErneuteImportieren(): void
    {
        $this->importiere();
        StufenleitungApi::postZuordnung(['typ' => 'schueler', 'name_roh' => 'Zuordnung|Uwe', 'benutzer_id' => $this->uwe]);
        StufenleitungApi::postZuordnung(['typ' => 'schueler', 'name_roh' => 'Schüler|Eva', 'benutzer_id' => $this->eva]);
        StufenleitungApi::postZuordnung(['typ' => 'lehrkraft', 'lehrer_kuerzel' => 'ZZ', 'benutzer_id' => $this->anna]);
        $this->assertSame(0, $this->offen());

        $this->alleHalbjahreLoeschen();
        $this->assertSame(0, $this->fx->zaehle('SELECT COUNT(*) FROM kurse'));

        $this->importiere();

        $this->assertSame(0, $this->offen(), 'kein erneutes Zuordnen nötig');
        $this->assertSame($this->uwe, (int) $this->fx->wert("SELECT schueler_id FROM kurs_schueler WHERE name_roh = 'Zuordnung|Uwe' LIMIT 1"));
        $this->assertSame($this->anna, (int) $this->fx->wert("SELECT lehrer_id FROM kurse WHERE kurs_kuerzel = 'D_Q1_GK1_ZZ'"));
    }

    public function testKorrekturAendertAlleKurseDerPerson(): void
    {
        $this->importiere();

        StufenleitungApi::postZuordnung(['typ' => 'schueler', 'name_roh' => 'Mustermann|Max', 'benutzer_id' => $this->eva]);

        $this->assertSame([$this->eva], array_map('intval', array_column(
            $this->fx->zeilen("SELECT DISTINCT schueler_id FROM kurs_schueler WHERE name_roh = 'Mustermann|Max'"), 'schueler_id')));
    }

    public function testAufgehobeneZuordnungBleibtNachNeuimportAufgehoben(): void
    {
        $this->importiere();
        StufenleitungApi::postZuordnung(['typ' => 'schueler', 'name_roh' => 'Mustermann|Max', 'benutzer_id' => null]);
        $this->alleHalbjahreLoeschen();

        $this->importiere();

        $this->assertSame(2, $this->fx->zaehle("SELECT COUNT(*) FROM kurs_schueler WHERE name_roh = 'Mustermann|Max' AND schueler_id IS NULL"),
            'trotz exakt passendem Namen wird nicht automatisch neu zugeordnet');

        StufenleitungApi::postZuordnung(['typ' => 'schueler', 'name_roh' => 'Mustermann|Max', 'benutzer_id' => $this->max]);
        $this->assertSame(0, $this->fx->zaehle("SELECT COUNT(*) FROM kurs_schueler WHERE name_roh = 'Mustermann|Max' AND schueler_id IS NULL"));
    }

    public function testZuordnungenUndKandidatenlisten(): void
    {
        $this->importiere();
        StufenleitungApi::postZuordnung(['typ' => 'schueler', 'name_roh' => 'Schüler|Eva', 'benutzer_id' => $this->eva]);
        $this->fx->kursSchueler($this->fx->kurs($this->fx->halbjahr($this->fx->stufe('EF', '2030/2031')), 'X'), 'Verwaist|Vera'); // ohne Konto
        $this->db->prepare('INSERT INTO schueler_zuordnungen (name_roh, benutzer_id) VALUES (?, ?)')->execute(['Verwaist|Waise', $this->uwe]); // Kurse gelöscht

        $z = StufenleitungApi::getZuordnungen();

        $zugeordnet = array_column($z['schueler_zugeordnet'], null, 'name_roh');
        $this->assertSame(0, (int) $zugeordnet['Mustermann|Max']['manuell'], 'automatisch erkannt');
        $this->assertSame(1, (int) $zugeordnet['Schüler|Eva']['manuell'], 'manuell');
        $this->assertSame(0, (int) $zugeordnet['Verwaist|Waise']['anzahl_kurse'], 'nur noch als gespeicherte Zuordnung vorhanden');
        $this->assertSame('Q1, Q2', $zugeordnet['Mustermann|Max']['stufen']);
        $this->assertSame(2, (int) $zugeordnet['Mustermann|Max']['anzahl_kurse']);

        $freieKonten = array_column($z['schueler_moodle'], 'id');
        $this->assertNotContains($this->max, $freieKonten, 'zugeordnete Konten sind nicht frei');
        $this->assertNotContains($this->eva, $freieKonten);
        $this->assertNotContains($this->uwe, $freieKonten, 'auch gespeicherte Zuordnungen belegen ein Konto');
        $this->assertNotContains($this->anna, $freieKonten, 'Lehrkräfte sind keine Schüler*innen');

        $alle = array_column(StufenleitungApi::getMoodleSchueler(), null, 'id');
        $this->assertSame(1, (int) $alle[$this->max]['vergeben']);
        $this->assertSame(1, (int) $alle[$this->uwe]['vergeben'], 'gespeicherte Zuordnung belegt das Konto');
        $this->assertArrayNotHasKey($this->sl, $alle, 'Lehrkräfte sind keine Schüler*innen');
    }

    public function testLehrkraftListen(): void
    {
        $this->importiere();
        $ex = $this->fx->benutzer('Ex', 'Tern', ['lehrkraft'], 'XT', 'ex@example.org', null, true);
        StufenleitungApi::postZuordnung(['typ' => 'lehrkraft', 'lehrer_kuerzel' => 'ZZ', 'benutzer_id' => $ex]);

        $z = StufenleitungApi::getZuordnungen();

        $this->assertSame([], $z['lehrkraefte_kurse'], 'alle Kürzel sind zugeordnet');
        $zugeordnet = array_column($z['lehrkraefte_zugeordnet'], null, 'lehrer_kuerzel');
        $this->assertSame(0, (int) $zugeordnet['SZ']['manuell']);
        $this->assertSame(1, (int) $zugeordnet['ZZ']['manuell']);
        $this->assertSame(1, (int) $zugeordnet['ZZ']['extern']);
        $lehrkraefte = array_column($z['lehrkraefte_moodle'], null, 'id');
        $this->assertSame(1, (int) $lehrkraefte[$ex]['vergeben']);
        $this->assertSame(0, (int) $lehrkraefte[$this->sl]['vergeben']);
    }

    public function testZusatzPruefllingNutztGespeicherteZuordnung(): void
    {
        $this->importiere();
        $kurs = (int) $this->fx->wert("SELECT id FROM kurse WHERE kurs_kuerzel = 'SPA_Q2_GK1_SZ'");
        StufenleitungApi::postZuordnung(['typ' => 'schueler', 'name_roh' => 'Nachzügler Nils', 'benutzer_id' => $this->uwe]);

        $r = StufenleitungApi::addZusatzSchuelerZuKurs($kurs, ['name' => 'Nachzügler Nils']);
        $auto = StufenleitungApi::addZusatzSchuelerZuKurs($kurs, ['name' => 'Max Mustermann']);

        $this->assertSame($this->uwe, $r['schueler_id']);
        $this->assertSame($this->max, $auto['schueler_id'], 'Vorname Nachname wird automatisch erkannt');
        $this->assertSame(2, count(array_filter(StufenleitungApi::getKursSchueler($kurs), fn ($e) => (int) $e['ist_zusatz'] === 1)));
        StufenleitungApi::deleteZusatzSchuelerAusKurs($kurs, $r['kurs_schueler_id']);
    }

    // ------------------------------------------------------------------ Kurse & Halbjahre

    public function testHalbjahrKursUndLoeschen(): void
    {
        $r = StufenleitungApi::addHalbjahr(['stufe_name' => 'EF, Q1', 'schuljahr' => '2026/2027', 'abschnitt' => 1]);
        $this->assertCount(2, $r['erstellt']);
        $doppelt = StufenleitungApi::addHalbjahr(['stufe_name' => 'EF, Q2', 'schuljahr' => '2026/2027', 'abschnitt' => 1]);
        $this->assertCount(1, $doppelt['erstellt']);
        $this->assertCount(1, $doppelt['fehler']);

        $hj = (int) $r['erstellt'][0]['id'];
        $kurs = StufenleitungApi::addKurs($hj, ['bezeichnung' => 'M_EF_GK1_SZ', 'kursart' => 'GK', 'lehrer_kuerzel' => 'sz']);
        $this->assertSame($this->anna, $kurs['lehrer_id'], 'Lehrkraft über Kürzel gefunden');
        $this->assertSame([], array_diff(['M_EF_GK1_SZ'], array_column(StufenleitungApi::getKurse($hj), 'kurs_kuerzel')));

        StufenleitungApi::deleteKurs($kurs['id']);
        StufenleitungApi::deleteHalbjahr($hj);
        $this->assertSame(0, $this->fx->zaehle('SELECT COUNT(*) FROM halbjahre WHERE id = ?', [$hj]));
    }

    public function testHalbjahrVorschlagUndHalbjahreliste(): void
    {
        $this->importiere();

        $v = StufenleitungApi::getHalbjahrVorschlag();
        $this->assertSame('2025/2026', $v['schuljahr']);
        $this->assertSame(2, $v['abschnitt'], 'alle bekannten Stufen haben schon Halbjahr 1');

        $halbjahre = StufenleitungApi::getHalbjahre();
        $this->assertCount(2, $halbjahre);
        $this->assertSame([1, 1], array_map('intval', array_column($halbjahre, 'ist_eigene_stufe')), 'durch den Import zuständig');
        $this->assertStringContainsString('Leitung', $halbjahre[0]['stufenleitungen']);
    }

    public function testGomsthImporterOhneSessionDirektAufrufbar(): void
    {
        $ergebnis = (new GomsthImporter($this->sl))->importiere($this->datei());

        $this->assertCount(2, $ergebnis['stufen_ids']);
    }
}
