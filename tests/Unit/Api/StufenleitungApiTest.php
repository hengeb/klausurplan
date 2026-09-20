<?php

declare(strict_types=1);

namespace Klausurplan\Tests\Unit\Api;

use Klausurplan\Api\StufenleitungApi;
use Klausurplan\Tests\Support\FakeResult;
use Klausurplan\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class StufenleitungApiTest extends TestCase
{
    private const SL = ['stufenleitung', 'lehrkraft'];

    private array $altEnv;
    private array $tempDateien = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->altEnv = $_ENV;
        $this->alsBenutzer(1, self::SL);
    }

    protected function tearDown(): void
    {
        $_ENV = $this->altEnv;
        array_map('unlink', array_filter($this->tempDateien, 'file_exists'));
        parent::tearDown();
    }

    // ------------------------------------------------------------------ GoMST-Import

    private function hochladen(string $inhalt): void
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'gomst');
        file_put_contents($tmp, $inhalt);
        $this->tempDateien[] = $tmp;
        $_FILES = ['datei' => ['tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK]];
    }

    private const DATEI = "Nachname|Vorname|Fach|Fachlehrer|Kursart|Kurs|Jahrgang|Abschnitt|Jahr\r\nA|Anna|M|MA|LK1|M_Q2_LK1_MA|Q2|1|2025\r\n";

    /** Alles existiert bereits (Stufe 5), sodass der Import nur aktualisiert. */
    private function bestehendeStufe(): void
    {
        $this->db->onScalar('/SELECT id FROM stufen WHERE name/', 5);
        $this->db->onScalar('/SELECT id FROM halbjahre WHERE/', 6);
        $this->db->onScalar('/SELECT id FROM kurse WHERE/', 7);
        $this->db->onScalar('/SELECT id FROM kurs_schueler WHERE/', 8);
        $this->db->onRows('/SELECT s\.id, s\.name, s\.schuljahr\s+FROM stufen s/', [['id' => 5, 'name' => 'Q2', 'schuljahr' => '2025/2026']]);
    }

    public function testImportErfordertAdminOderStufenleitung(): void
    {
        $this->alsBenutzer(2, ['lehrkraft']);

        $this->erwarteZugriffVerweigert(fn () => StufenleitungApi::gomstImport());
    }

    public function testImportOhneDateiWirdAbgelehnt(): void
    {
        $this->erwarteFehler(fn () => StufenleitungApi::gomstImport(), 'Keine gültige Datei', 400);
    }

    public function testImportMitUploadFehlerNenntDenFehlercode(): void
    {
        $_FILES = ['datei' => ['tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE]];

        $this->erwarteFehler(fn () => StufenleitungApi::gomstImport(), 'Fehlercode: ' . UPLOAD_ERR_INI_SIZE);
    }

    public function testImportEinerLeerenDatei(): void
    {
        $this->hochladen('');

        $this->erwarteFehler(fn () => StufenleitungApi::gomstImport(), 'leer');
    }

    public function testImportMachtStufenleitungZustaendigUndMeldetNeueStufen(): void
    {
        $this->bestehendeStufe();
        $this->hochladen(self::DATEI);

        $ergebnis = StufenleitungApi::gomstImport();

        $this->assertSame([['id' => 5, 'name' => 'Q2', 'schuljahr' => '2025/2026']], $ergebnis['stufenleitung_neu']);
        $this->assertSame(1, $ergebnis['kurse']);
        $this->assertArrayNotHasKey('stufen_ids', $ergebnis);
        $this->assertSame([[1, 5]], array_column($this->db->aufrufe('/^INSERT IGNORE INTO stufenleitungen/'), 'params'));
    }

    public function testImportMeldetNurWirklichNeueZustaendigkeiten(): void
    {
        $this->bestehendeStufe();
        $this->db->onRows('/SELECT stufe_id FROM stufenleitungen/', [['stufe_id' => 5]]); // schon zuständig
        $this->hochladen(self::DATEI);

        $ergebnis = StufenleitungApi::gomstImport();

        $this->assertSame([], $ergebnis['stufenleitung_neu']);
    }

    public function testImportDurchAdminOhneStufenleitungRolleAendertKeineZustaendigkeit(): void
    {
        $this->alsBenutzer(9, ['admin']);
        $this->bestehendeStufe();
        $this->hochladen(self::DATEI);

        $ergebnis = StufenleitungApi::gomstImport();

        $this->assertSame([], $ergebnis['stufenleitung_neu']);
        $this->assertFalse($this->db->wurdeAusgefuehrt('/stufenleitungen/'));
    }

    // ------------------------------------------------------------------ Meine Stufen

    public function testMeineStufenSindNurFuerDieStufenleitungRolleErreichbar(): void
    {
        $this->alsBenutzer(9, ['admin', 'lehrkraft']);

        $this->erwarteZugriffVerweigert(fn () => StufenleitungApi::getMeineStufen());
        $this->erwarteZugriffVerweigert(fn () => StufenleitungApi::meineStufeUebernehmen(1));
        $this->erwarteZugriffVerweigert(fn () => StufenleitungApi::meineStufeAbgeben(1));
    }

    public function testGetMeineStufen(): void
    {
        $stufen = [['id' => 1, 'name' => 'Q2', 'schuljahr' => '2025/2026', 'ist_meine' => 1]];
        $this->db->onRows('/FROM stufen s\s+LEFT JOIN stufenleitungen/', $stufen);

        $this->assertSame($stufen, StufenleitungApi::getMeineStufen());
        $this->assertSame([1], $this->db->log[0]['params']);
        $this->assertStringContainsString('EXISTS (SELECT 1 FROM halbjahre h WHERE h.stufe_id = s.id)', $this->db->log[0]['sql'], 'nur Stufen mit Halbjahren');
    }

    public function testStufeUebernehmen(): void
    {
        $this->db->onScalar('/SELECT 1 FROM stufen s WHERE s\.id = \?/', 1);

        $this->assertSame(['ok' => true], StufenleitungApi::meineStufeUebernehmen(5));

        $pruefung = $this->db->aufrufe('/SELECT 1 FROM stufen s/')[0]['sql'];
        $this->assertStringContainsString('FROM halbjahre h WHERE h.stufe_id = s.id', $pruefung, 'verwaiste Stufen zählen nicht');
        $this->assertSame([1, 5], $this->db->aufrufe('/^INSERT IGNORE INTO stufenleitungen/')[0]['params']);
    }

    public function testUnbekannteStufeKannNichtUebernommenWerden(): void
    {
        $this->erwarteFehler(fn () => StufenleitungApi::meineStufeUebernehmen(999), 'nicht gefunden', 404);
        $this->assertFalse($this->db->wurdeAusgefuehrt('/INSERT/'));
    }

    public function testStufeAbgeben(): void
    {
        $this->assertSame(['ok' => true], StufenleitungApi::meineStufeAbgeben(5));

        $this->assertSame([1, 5], $this->db->aufrufe('/^DELETE FROM stufenleitungen/')[0]['params']);
    }

    // ------------------------------------------------------------------ Zuordnungen abrufen

    public function testGetZuordnungenFuehrtAlleListenZusammen(): void
    {
        $this->db->onRows('/WHERE ks\.schueler_id IS NULL/', [['name_roh' => 'Unbekannt|Uwe', 'anzahl_kurse' => 2, 'stufen' => 'Q2']]);
        $this->db->onRows('/MAX\(z\.name_roh IS NOT NULL\)/', [
            ['name_roh' => 'Schüler|Eva', 'schueler_id' => 3, 'manuell' => 0],
            ['name_roh' => 'Anders|Otto', 'schueler_id' => 4, 'manuell' => 1],
        ]);
        $this->db->onRows('/FROM schueler_zuordnungen z\s+JOIN benutzer/', [['name_roh' => 'Adler|Ada', 'schueler_id' => 5, 'manuell' => 1]]);
        $this->db->onRows('/MAX\(z\.lehrer_kuerzel IS NOT NULL\)/', [['lehrer_kuerzel' => 'SZ', 'lehrer_id' => 2]]);
        $this->db->onRows('/FROM lehrer_zuordnungen z\s+JOIN benutzer/', [['lehrer_kuerzel' => 'AB', 'lehrer_id' => 3]]);
        $this->db->onRows('/WHERE b\.extern = 1/', [['id' => 9, 'kuerzel' => 'XT']]);

        $z = StufenleitungApi::getZuordnungen();

        $this->assertSame(['schueler_gomst', 'schueler_moodle', 'schueler_zugeordnet', 'lehrkraefte_kurse',
            'lehrkraefte_moodle', 'lehrkraefte_zugeordnet', 'externe_lehrkraefte'], array_keys($z));
        $this->assertSame(['Adler|Ada', 'Anders|Otto', 'Schüler|Eva'], array_column($z['schueler_zugeordnet'], 'name_roh'),
            'aktuelle und nur gespeicherte Zuordnungen, alphabetisch');
        $this->assertSame(['AB', 'SZ'], array_column($z['lehrkraefte_zugeordnet'], 'lehrer_kuerzel'));
        $this->assertSame([['id' => 9, 'kuerzel' => 'XT']], $z['externe_lehrkraefte']);
    }

    public function testGetZuordnungenErfordertAdminOderStufenleitung(): void
    {
        $this->alsBenutzer(3, ['lehrkraft']);

        $this->erwarteZugriffVerweigert(fn () => StufenleitungApi::getZuordnungen());
    }

    public function testGetMoodleSchueler(): void
    {
        $konten = [['id' => 1, 'vorname' => 'Max', 'nachname' => 'M', 'stufe' => 'Q2', 'vergeben' => 0]];
        $this->db->onRows('/FROM benutzer b/', $konten);

        $this->assertSame($konten, StufenleitungApi::getMoodleSchueler());
    }

    // ------------------------------------------------------------------ Zuordnung speichern

    public function testZuordnungMitUnbekanntemTyp(): void
    {
        $this->erwarteFehler(fn () => StufenleitungApi::postZuordnung(['typ' => 'kurs']), "Unbekannter Typ 'kurs'", 400);
    }

    public function testZuordnungOhneNamen(): void
    {
        $this->erwarteFehler(fn () => StufenleitungApi::postZuordnung(['typ' => 'schueler', 'name_roh' => ' ']), 'name_roh fehlt', 400);
    }

    public function testZuordnungOhneKuerzel(): void
    {
        $this->erwarteFehler(fn () => StufenleitungApi::postZuordnung(['typ' => 'lehrkraft']), 'lehrer_kuerzel fehlt', 400);
    }

    public function testZuordnungZuUnbekanntemKonto(): void
    {
        $this->erwarteFehler(
            fn () => StufenleitungApi::postZuordnung(['typ' => 'schueler', 'name_roh' => 'A|B', 'benutzer_id' => 999]),
            'nicht gefunden',
            404,
        );
        $this->assertFalse($this->db->wurdeAusgefuehrt('/schueler_zuordnungen/'));
    }

    public function testSchuelerZuordnungWirdDauerhaftGespeichert(): void
    {
        $this->db->onScalar('/SELECT 1 FROM benutzer WHERE id/', 1);
        $this->db->on('/^UPDATE kurs_schueler/', FakeResult::count(2));

        $r = StufenleitungApi::postZuordnung(['typ' => 'schueler', 'name_roh' => ' Mustermann|Max ', 'benutzer_id' => '17']);

        $this->assertSame(['ok' => true, 'aktualisiert' => 2], $r);
        $this->assertSame(['Mustermann|Max', 17, 1], $this->db->aufrufe('/INSERT INTO schueler_zuordnungen/')[0]['params']);
    }

    public function testZuordnungAufheben(): void
    {
        $this->db->on('/^UPDATE kurs_schueler/', FakeResult::count(1));

        StufenleitungApi::postZuordnung(['typ' => 'schueler', 'name_roh' => 'Mustermann|Max', 'benutzer_id' => null]);

        $this->assertSame(['Mustermann|Max', null, 1], $this->db->aufrufe('/INSERT INTO schueler_zuordnungen/')[0]['params']);
        $this->assertFalse($this->db->wurdeAusgefuehrt('/SELECT 1 FROM benutzer/'), 'kein Konto zu prüfen');
    }

    public function testLehrkraftZuordnung(): void
    {
        $this->db->onScalar('/SELECT 1 FROM benutzer WHERE id/', 1);
        $this->db->on('/^UPDATE kurse/', FakeResult::count(4));

        $r = StufenleitungApi::postZuordnung(['typ' => 'lehrkraft', 'lehrer_kuerzel' => 'SZ', 'benutzer_id' => 23]);

        $this->assertSame(['ok' => true, 'aktualisiert' => 4], $r);
        $this->assertSame(['SZ', 23, 1], $this->db->aufrufe('/INSERT INTO lehrer_zuordnungen/')[0]['params']);
    }

    // ------------------------------------------------------------------ Externe Lehrkräfte

    private function extern(array $abweichung = []): array
    {
        return $abweichung + ['vorname' => 'Ex', 'nachname' => 'Tern', 'kuerzel' => 'XT', 'email' => 'ex@schule2.de'];
    }

    public function testExterneLehrkraefteKannJedeStufenleitungVerwalten(): void
    {
        // Stufenleitung ohne Admin-Rolle und ohne zugewiesene Stufe genügt
        $this->alsBenutzer(4, ['stufenleitung']);
        $this->db->on('/^INSERT INTO benutzer/', FakeResult::insert(30));
        $this->db->onScalar('/SELECT 1 FROM benutzer WHERE id = \? AND extern = 1/', 1);
        $this->db->on('/^DELETE FROM benutzer/', FakeResult::count(1));

        $this->assertSame(30, StufenleitungApi::addExterneLehrkraft($this->extern())['id']);
        $this->assertSame(['ok' => true], StufenleitungApi::updateExterneLehrkraft(30, $this->extern()));
        $this->assertSame(['ok' => true], StufenleitungApi::deleteExterneLehrkraft(30));
    }

    public function testExterneLehrkraefteKannAuchDerAdminVerwalten(): void
    {
        $this->alsBenutzer(4, ['admin']);
        $this->db->on('/^INSERT INTO benutzer/', FakeResult::insert(30));

        $this->assertSame(30, StufenleitungApi::addExterneLehrkraft($this->extern())['id']);
    }

    public function testExterneLehrkraefteSindFuerAndereRollenGesperrt(): void
    {
        $this->alsBenutzer(4, ['lehrkraft', 'schueler']);

        $this->erwarteZugriffVerweigert(fn () => StufenleitungApi::addExterneLehrkraft($this->extern()));
        $this->erwarteZugriffVerweigert(fn () => StufenleitungApi::updateExterneLehrkraft(1, $this->extern()));
        $this->erwarteZugriffVerweigert(fn () => StufenleitungApi::deleteExterneLehrkraft(1));
    }

    public function testExterneLehrkraftAnlegen(): void
    {
        $this->db->on('/^INSERT INTO benutzer/', FakeResult::insert(30));
        $this->db->on('/^UPDATE kurse/', FakeResult::count(2));

        $r = StufenleitungApi::addExterneLehrkraft($this->extern(['vorname' => ' Ex ', 'kuerzel' => ' XT ']));

        $this->assertSame(['id' => 30, 'zugeordnete_kurse' => 2], $r);
        $insert = $this->db->aufrufe('/^INSERT INTO benutzer/')[0];
        $this->assertStringContainsString('extern) VALUES', preg_replace('/\s+/', ' ', $insert['sql']));
        $this->assertMatchesRegularExpression('/^extern:[0-9a-f]{16}$/', $insert['params'][0], 'Platzhalter-moodle_id');
        $this->assertSame(['Ex', 'Tern', 'ex@schule2.de', 'XT'], array_slice($insert['params'], 1));
        $this->assertSame([30, 'lehrkraft'], $this->db->aufrufe('/^INSERT INTO rollen/')[0]['params']);
        $this->assertSame(['XT', 30, 1], $this->db->aufrufe('/INSERT INTO lehrer_zuordnungen/')[0]['params']);
    }

    public function testBestehendeKuerzelZuordnungWirdNichtUeberschrieben(): void
    {
        $this->db->on('/^INSERT INTO benutzer/', FakeResult::insert(30));
        $this->db->onScalar('/FROM lehrer_zuordnungen WHERE/', 1);

        $r = StufenleitungApi::addExterneLehrkraft($this->extern());

        $this->assertSame(0, $r['zugeordnete_kurse']);
        $this->assertFalse($this->db->wurdeAusgefuehrt('/INSERT INTO lehrer_zuordnungen/'));
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function ungueltigeExterne(): array
    {
        return [
            'Vorname fehlt'   => [['vorname' => ''], 'Vor- und Nachname'],
            'Nachname fehlt'  => [['nachname' => '  '], 'Vor- und Nachname'],
            'Name zu lang'    => [['nachname' => str_repeat('x', 101)], 'Vor- und Nachname'],
            'Kürzel fehlt'    => [['kuerzel' => ''], 'Kürzel ist erforderlich'],
            'Kürzel zu lang'  => [['kuerzel' => str_repeat('K', 21)], 'Kürzel ist erforderlich'],
            'E-Mail ungültig' => [['email' => 'keine-mail'], 'gültige E-Mail'],
            'E-Mail fehlt'    => [['email' => ''], 'gültige E-Mail'],
        ];
    }

    /** @param array<string, string> $abweichung */
    #[DataProvider('ungueltigeExterne')]
    public function testUngueltigeAngabenBeimAnlegen(array $abweichung, string $meldung): void
    {
        $this->erwarteFehler(fn () => StufenleitungApi::addExterneLehrkraft($this->extern($abweichung)), $meldung, 400);
        $this->assertFalse($this->db->wurdeAusgefuehrt('/^INSERT/'));
    }

    public function testKuerzelDasSchonVergebenIstWirdAbgelehnt(): void
    {
        $this->db->onScalar('/WHERE UPPER\(kuerzel\)/', 1);

        $this->erwarteFehler(fn () => StufenleitungApi::addExterneLehrkraft($this->extern()), 'bereits von einer anderen Person', 409);
        $this->assertFalse($this->db->wurdeAusgefuehrt('/^INSERT/'));
    }

    public function testExterneLehrkraftBearbeiten(): void
    {
        $this->db->onScalar('/SELECT 1 FROM benutzer WHERE id = \? AND extern = 1/', 1);

        $r = StufenleitungApi::updateExterneLehrkraft(30, $this->extern(['vorname' => 'Exi', 'email' => 'neu@schule2.de', 'kuerzel' => 'IGNORIERT']));

        $this->assertSame(['ok' => true], $r);
        $update = $this->db->aufrufe('/^UPDATE benutzer/')[0];
        $this->assertSame(['Exi', 'Tern', 'neu@schule2.de', 30], $update['params'], 'das Kürzel bleibt unverändert');
    }

    public function testNurExterneLehrkraefteSindBearbeitbar(): void
    {
        $this->erwarteFehler(fn () => StufenleitungApi::updateExterneLehrkraft(2, $this->extern()), 'nicht gefunden', 404);
        $this->assertFalse($this->db->wurdeAusgefuehrt('/^UPDATE/'));
    }

    public function testBeimBearbeitenWirdDieEingabeGeprueft(): void
    {
        $this->db->onScalar('/SELECT 1 FROM benutzer WHERE id = \? AND extern = 1/', 1);

        $this->erwarteFehler(fn () => StufenleitungApi::updateExterneLehrkraft(30, $this->extern(['email' => 'x'])), 'gültige E-Mail', 400);
    }

    public function testExterneLehrkraftLoeschen(): void
    {
        $this->db->on('/^DELETE FROM benutzer/', FakeResult::count(1));

        $this->assertSame(['ok' => true], StufenleitungApi::deleteExterneLehrkraft(30));

        $this->assertStringContainsString('extern = 1', $this->db->aufrufe('/^DELETE FROM benutzer/')[0]['sql'], 'Moodle-Konten sind nicht löschbar');
    }

    public function testUnbekannteExterneLehrkraftLoeschen(): void
    {
        $this->erwarteFehler(fn () => StufenleitungApi::deleteExterneLehrkraft(2), 'nicht gefunden', 404);
    }

    // ------------------------------------------------------------------ Halbjahr-Vorschlag

    private function vorschlagDaten(array $stufenNamen, ?array $neuestes, array $vorhandene): void
    {
        $this->db->onRows('/SELECT DISTINCT s\.name FROM stufen s/', array_map(fn ($n) => ['name' => $n], $stufenNamen));
        if ($neuestes !== null) {
            $this->db->onRows('/ORDER BY s\.schuljahr DESC, h\.abschnitt DESC\s+LIMIT 1/', [$neuestes]);
        }
        $this->db->onRows('/SELECT s\.name FROM halbjahre h/', array_map(fn ($n) => ['name' => $n], $vorhandene));
    }

    public function testVorschlagOhneAlteDatenNimmtDasAktuelleSchuljahr(): void
    {
        $this->vorschlagDaten([], null, []);

        $v = StufenleitungApi::getHalbjahrVorschlag();

        $this->assertSame(1, $v['abschnitt']);
        $this->assertSame([], $v['fehlende_stufen']);
        $this->assertMatchesRegularExpression('#^(\d{4})/(\d{4})$#', $v['schuljahr']);
        [$von, $bis] = explode('/', $v['schuljahr']);
        $this->assertSame((int) $von + 1, (int) $bis);
    }

    public function testVorschlagNenntFehlendeStufenDesNeuestenHalbjahrs(): void
    {
        $this->vorschlagDaten(['EF', 'Q1', 'Q2'], ['schuljahr' => '2025/2026', 'abschnitt' => 1], ['Q2']);

        $v = StufenleitungApi::getHalbjahrVorschlag();

        $this->assertSame(['schuljahr' => '2025/2026', 'abschnitt' => 1, 'fehlende_stufen' => ['EF', 'Q1']], $v);
    }

    public function testVorschlagNachVollstaendigemErstenHalbjahrIstDasZweite(): void
    {
        $this->vorschlagDaten(['Q2', 'EF'], ['schuljahr' => '2025/2026', 'abschnitt' => 1], ['EF', 'Q2']);

        $v = StufenleitungApi::getHalbjahrVorschlag();

        $this->assertSame(['schuljahr' => '2025/2026', 'abschnitt' => 2, 'fehlende_stufen' => ['EF', 'Q2']], $v);
    }

    public function testVorschlagNachVollstaendigemZweitenHalbjahrIstDasNaechsteSchuljahr(): void
    {
        $this->vorschlagDaten(['Q1'], ['schuljahr' => '2025/2026', 'abschnitt' => 2], ['Q1']);

        $v = StufenleitungApi::getHalbjahrVorschlag();

        $this->assertSame(['schuljahr' => '2026/2027', 'abschnitt' => 1, 'fehlende_stufen' => ['Q1']], $v);
    }

    // ------------------------------------------------------------------ Halbjahr anlegen

    public function testHalbjahrAnlegenValidiertDieEingabe(): void
    {
        $ok = ['stufe_name' => 'Q2', 'schuljahr' => '2025/2026', 'abschnitt' => 1];

        $this->erwarteFehler(fn () => StufenleitungApi::addHalbjahr(['stufe_name' => ' , '] + $ok), 'Stufe darf nicht leer', 400);
        $this->erwarteFehler(fn () => StufenleitungApi::addHalbjahr(['schuljahr' => '2025/2026', 'abschnitt' => 1]), 'Stufe darf nicht leer', 400);
        $this->erwarteFehler(fn () => StufenleitungApi::addHalbjahr(['schuljahr' => '2025-2026'] + $ok), 'JJJJ/JJJJ', 400);
        $this->erwarteFehler(fn () => StufenleitungApi::addHalbjahr(['abschnitt' => 3] + $ok), 'Abschnitt muss 1 oder 2', 400);
        $this->assertFalse($this->db->wurdeAusgefuehrt('/INSERT/'));
    }

    public function testNeueStufeMitHalbjahrAnlegen(): void
    {
        $this->db->on('/^INSERT INTO stufen/', FakeResult::insert(11));
        $this->db->on('/^INSERT INTO halbjahre/', FakeResult::insert(21));

        $r = StufenleitungApi::addHalbjahr(['stufe_name' => ' q2 ', 'schuljahr' => '2025/2026', 'abschnitt' => '1']);

        $this->assertSame(['id' => 21, 'stufe' => 'Q2', 'schuljahr' => '2025/2026', 'abschnitt' => 1, 'kurs_anzahl' => 0], $r);
        $this->assertSame(['Q2', '2025/2026'], $this->db->aufrufe('/^INSERT INTO stufen/')[0]['params']);
        $this->assertSame([11, 1, 1], $this->db->aufrufe('/^INSERT INTO halbjahre/')[0]['params']);
    }

    public function testHalbjahrZuBestehenderStufe(): void
    {
        $this->db->onScalar('/SELECT id FROM stufen WHERE name/', 11);
        $this->db->on('/^INSERT INTO halbjahre/', FakeResult::insert(22));

        $r = StufenleitungApi::addHalbjahr(['stufe_name' => 'Q2', 'schuljahr' => '2025/2026', 'abschnitt' => 2]);

        $this->assertSame(22, $r['id']);
        $this->assertFalse($this->db->wurdeAusgefuehrt('/^INSERT INTO stufen/'));
    }

    public function testDoppeltesHalbjahrWirdAbgelehnt(): void
    {
        $this->db->onScalar('/SELECT id FROM stufen WHERE name/', 11);
        $this->db->onScalar('/SELECT id FROM halbjahre WHERE stufe_id/', 21);

        $this->erwarteFehler(
            fn () => StufenleitungApi::addHalbjahr(['stufe_name' => 'Q2', 'schuljahr' => '2025/2026', 'abschnitt' => 1]),
            'Q2 – 1. Halbjahr 2025/2026 existiert bereits',
            409,
        );
        $this->assertFalse($this->db->wurdeAusgefuehrt('/^INSERT/'));
    }

    public function testMehrereStufenErlaubenTeilerfolge(): void
    {
        $this->db->on('/SELECT id FROM stufen WHERE name/', fn (string $s, array $p) => $p[0] === 'Q1' ? FakeResult::scalar(11) : FakeResult::leer());
        $this->db->onScalar('/SELECT id FROM halbjahre WHERE stufe_id/', 21); // Q1 hat das Halbjahr schon
        $this->db->on('/^INSERT INTO stufen/', FakeResult::insert(12));
        $this->db->on('/^INSERT INTO halbjahre/', FakeResult::insert(22));

        $r = StufenleitungApi::addHalbjahr(['stufe_name' => 'Q1, Q2, q2', 'schuljahr' => '2025/2026', 'abschnitt' => 1]);

        $this->assertCount(1, $r['erstellt']);
        $this->assertSame('Q2', $r['erstellt'][0]['stufe']);
        $this->assertSame('Q1', $r['fehler'][0]['stufe'], 'doppelte Namen werden nur einmal verarbeitet');
        $this->assertStringContainsString('existiert bereits', $r['fehler'][0]['meldung']);
        $this->assertCount(1, $r['fehler']);
    }

    public function testNeueStufeUebernimmtDieStufenleitungDerVorgaengerstufe(): void
    {
        $this->db->on('/SELECT id FROM stufen WHERE name = \? AND schuljahr = \?/', fn (string $s, array $p) => $p === ['Q1', '2024/2025']
            ? FakeResult::scalar(3) : FakeResult::leer());
        $this->db->on('/^INSERT INTO stufen/', FakeResult::insert(12));
        $this->db->on('/^INSERT INTO halbjahre/', FakeResult::insert(22));
        $this->db->onRows('/SELECT sl\.benutzer_id/', [['benutzer_id' => 7]]);

        StufenleitungApi::addHalbjahr(['stufe_name' => 'Q2', 'schuljahr' => '2025/2026', 'abschnitt' => 1]);

        $this->assertSame([[7, 12]], array_column($this->db->aufrufe('/^INSERT IGNORE INTO stufenleitungen/'), 'params'));
    }

    // ------------------------------------------------------------------ Listen

    public function testGetLehrkraefteEnthaeltAuchExterne(): void
    {
        $rows = [['id' => 1, 'vorname' => 'A', 'nachname' => 'B', 'kuerzel' => 'AB', 'extern' => 0]];
        $this->db->onRows('/FROM benutzer b/', $rows);

        $this->assertSame($rows, StufenleitungApi::getLehrkraefte());
        $this->assertStringContainsString('b.extern', $this->db->log[0]['sql']);
    }

    public function testGetHalbjahreMarkiertEigeneStufen(): void
    {
        $this->db->onRows('/FROM halbjahre h/', [
            ['id' => 1, 'stufe' => 'Q2', 'ist_eigene_stufe' => 1],
            ['id' => 2, 'stufe' => 'Q1', 'ist_eigene_stufe' => 0],
        ]);

        $rows = StufenleitungApi::getHalbjahre();

        $this->assertSame([1, 0], array_column($rows, 'ist_eigene_stufe'));
        $this->assertSame([1], $this->db->log[0]['params']);
    }

    public function testAdminIstFuerAlleHalbjahreZustaendig(): void
    {
        $this->alsBenutzer(9, ['admin']);
        $this->db->onRows('/FROM halbjahre h/', [['id' => 2, 'ist_eigene_stufe' => 0], ['id' => 3, 'ist_eigene_stufe' => 0]]);

        $this->assertSame([1, 1], array_column(StufenleitungApi::getHalbjahre(), 'ist_eigene_stufe'));
    }

    public function testGetKurse(): void
    {
        $rows = [['id' => 1, 'anzeigename' => 'Q2 Sport GK 1 SZ']];
        $this->db->onRows('/FROM kurse k/', $rows);

        $this->assertSame($rows, StufenleitungApi::getKurse(6));
        $this->assertSame([6], $this->db->log[0]['params']);
    }

    // ------------------------------------------------------------------ Kurs-Teilnehmende

    public function testKursTeilnehmerEinesUnbekanntenKurses(): void
    {
        $this->erwarteFehler(fn () => StufenleitungApi::getKursSchueler(9), 'Kurs 9 nicht gefunden', 404);
    }

    public function testKursTeilnehmer(): void
    {
        $this->db->onScalar('/SELECT id FROM kurse WHERE id/', 9);
        $eintraege = [['id' => 1, 'name_roh' => 'A|B', 'ist_zusatz' => 0]];
        $this->db->onRows('/FROM kurs_schueler ks\s+LEFT JOIN benutzer/', $eintraege);

        $this->assertSame($eintraege, StufenleitungApi::getKursSchueler(9));
    }

    public function testZusatzPruefling(): void
    {
        $this->db->onScalar('/SELECT id FROM kurse WHERE id/', 9);
        $this->db->on('/^INSERT INTO kurs_schueler/', FakeResult::insert(70));
        $this->db->onRows('/FROM benutzer/', [['id' => 4]]);

        $r = StufenleitungApi::addZusatzSchuelerZuKurs(9, ['name' => '  Anna Müller ']);

        $this->assertSame(['kurs_schueler_id' => 70, 'name_roh' => 'Anna Müller', 'schueler_id' => 4], $r);
        $this->assertSame([4, 70], $this->db->aufrufe('/^UPDATE kurs_schueler SET schueler_id/')[0]['params']);
    }

    public function testZusatzPruefllingOhneKontoBleibtOffen(): void
    {
        $this->db->onScalar('/SELECT id FROM kurse WHERE id/', 9);
        $this->db->on('/^INSERT INTO kurs_schueler/', FakeResult::insert(70));

        $r = StufenleitungApi::addZusatzSchuelerZuKurs(9, ['name' => 'Niemand']);

        $this->assertNull($r['schueler_id']);
        $this->assertFalse($this->db->wurdeAusgefuehrt('/^UPDATE kurs_schueler/'));
    }

    public function testZusatzPruefllingWirdGeprueft(): void
    {
        $this->erwarteFehler(fn () => StufenleitungApi::addZusatzSchuelerZuKurs(9, ['name' => ' ']), 'Name darf nicht leer', 400);
        $this->erwarteFehler(fn () => StufenleitungApi::addZusatzSchuelerZuKurs(9, ['name' => str_repeat('x', 201)]), 'zu lang', 400);
        $this->erwarteFehler(fn () => StufenleitungApi::addZusatzSchuelerZuKurs(9, ['name' => 'X']), 'Kurs 9 nicht gefunden', 404);

        $this->db->onScalar('/SELECT id FROM kurse WHERE id/', 9);
        $this->db->onScalar('/SELECT id FROM kurs_schueler WHERE kurs_id = \? AND name_roh/', 1);
        $this->erwarteFehler(fn () => StufenleitungApi::addZusatzSchuelerZuKurs(9, ['name' => 'X']), 'bereits in diesem Kurs', 409);
    }

    public function testNurZusatzPruefllingeSindLoeschbar(): void
    {
        $this->erwarteFehler(fn () => StufenleitungApi::deleteZusatzSchuelerAusKurs(9, 70), 'nicht gefunden', 404);
        $this->assertStringContainsString("NOT LIKE '%|%'", $this->db->log[0]['sql'], 'GoMST-Einträge sind geschützt');

        $this->db->onScalar('/SELECT id FROM kurs_schueler/', 70);
        $this->assertSame(['ok' => true], StufenleitungApi::deleteZusatzSchuelerAusKurs(9, 70));
        $this->assertSame([70], $this->db->aufrufe('/^DELETE FROM kurs_schueler/')[0]['params']);
    }

    // ------------------------------------------------------------------ E-Mail auslösen

    public function testEmailAusloesenBeiUnbekannterKlausur(): void
    {
        $this->erwarteFehler(fn () => StufenleitungApi::emailAusloesen(5), 'nicht gefunden oder keine Lehrkraft', 404);
    }

    public function testEmailAusloesenOhneAdresse(): void
    {
        $this->db->onRows('/FROM klausuren kl/', [['id' => 5, 'termin_datum' => '2026-01-01', 'kurs_anzeigename' => 'X', 'lehrer_id' => 2, 'email' => '', 'vorname' => 'A', 'nachname' => 'B']]);

        $this->erwarteFehler(fn () => StufenleitungApi::emailAusloesen(5), 'keine E-Mail-Adresse', 422);
    }

    public function testEmailAusloesenLegtErstDenTokenAnUndSendetDann(): void
    {
        $this->db->onRows('/FROM klausuren kl/', [['id' => 5, 'termin_datum' => '2026-01-01', 'kurs_anzeigename' => 'X', 'lehrer_id' => 2, 'email' => 'a@x.de', 'vorname' => 'A', 'nachname' => 'B']]);
        $_ENV += ['SMTP_HOST' => '127.0.0.1', 'SMTP_PORT' => '1', 'SMTP_USER' => 'k@x.de'];
        $_ENV['SMTP_HOST'] = '127.0.0.1';
        $_ENV['SMTP_PORT'] = '1';
        $_ENV['SMTP_ENCRYPTION'] = '';

        // Der Versand scheitert (kein SMTP-Server) – der Token muss dann bereits gespeichert sein.
        $this->erwarteFehler(fn () => StufenleitungApi::emailAusloesen(5), 'E-Mail konnte nicht gesendet werden');

        $insert = $this->db->aufrufe('/^INSERT INTO email_benachrichtigungen/')[0];
        $this->assertSame(5, $insert['params'][0]);
        $this->assertSame(2, $insert['params'][1]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $insert['params'][2]);
    }

    public function testEmailOhneTerminDatumZeigtStrich(): void
    {
        $this->db->onRows('/FROM klausuren kl/', [['id' => 5, 'termin_datum' => null, 'kurs_anzeigename' => 'X', 'lehrer_id' => 2, 'email' => 'a@x.de', 'vorname' => 'A', 'nachname' => 'B']]);
        $_ENV['SMTP_HOST'] = '127.0.0.1';
        $_ENV['SMTP_PORT'] = '1';
        $_ENV['SMTP_ENCRYPTION'] = 'none';

        $this->erwarteFehler(fn () => StufenleitungApi::emailAusloesen(5), 'E-Mail konnte nicht gesendet werden');
        $this->assertTrue($this->db->wurdeAusgefuehrt('/^INSERT INTO email_benachrichtigungen/'));
    }

    public function testStufeOhneBekanntenVorgaengerErbtNichts(): void
    {
        $this->db->on('/^INSERT INTO stufen/', FakeResult::insert(12));
        $this->db->on('/^INSERT INTO halbjahre/', FakeResult::insert(22));

        StufenleitungApi::addHalbjahr(['stufe_name' => '9a', 'schuljahr' => '2025/2026', 'abschnitt' => 1]);

        $this->assertFalse($this->db->wurdeAusgefuehrt('/INSERT IGNORE INTO stufenleitungen/'));
    }

    // ------------------------------------------------------------------ Kurse anlegen/löschen

    private function halbjahrExistiert(): void
    {
        $this->db->onScalar('/SELECT id FROM halbjahre WHERE id/', 6);
    }

    public function testKursAnlegenValidiertDieEingabe(): void
    {
        $this->erwarteFehler(fn () => StufenleitungApi::addKurs(6, ['bezeichnung' => ' ']), 'Bezeichnung darf nicht leer', 400);
        $this->erwarteFehler(fn () => StufenleitungApi::addKurs(6, ['bezeichnung' => str_repeat('x', 51)]), 'zu lang', 400);
        $this->erwarteFehler(fn () => StufenleitungApi::addKurs(6, ['bezeichnung' => 'X', 'kursart' => 'AB']), "Ungültige Kursart 'AB'", 400);
        $this->erwarteFehler(fn () => StufenleitungApi::addKurs(6, ['bezeichnung' => 'X']), 'Halbjahr 6 nicht gefunden', 404);

        $this->halbjahrExistiert();
        $this->db->onScalar('/SELECT id FROM kurse WHERE halbjahr_id/', 1);
        $this->erwarteFehler(fn () => StufenleitungApi::addKurs(6, ['bezeichnung' => 'X']), 'existiert bereits', 409);
    }

    public function testKursOhneLehrkraft(): void
    {
        $this->halbjahrExistiert();
        $this->db->on('/^INSERT INTO kurse/', FakeResult::insert(40));

        $r = StufenleitungApi::addKurs(6, ['bezeichnung' => 'sp_Q2_GK1_SZ', 'kursart' => 'LK']);

        $this->assertSame(40, $r['id']);
        $this->assertSame('LK', $r['kursart']);
        $this->assertSame('SP', $r['fach_kuerzel'], 'aus der Bezeichnung abgeleitet');
        $this->assertNull($r['lehrer_id']);
        $this->assertSame(0, $r['schueler_gesamt']);
        $this->assertSame([6, 'sp_Q2_GK1_SZ', 'SP', 'LK', 'sp_Q2_GK1_SZ', null, null], $this->db->aufrufe('/^INSERT INTO kurse/')[0]['params']);
    }

    public function testKursMitDirektGewaehlterLehrkraft(): void
    {
        $this->halbjahrExistiert();
        $this->db->on('/^INSERT INTO kurse/', FakeResult::insert(40));
        $this->db->onRows('/SELECT id, vorname, nachname, kuerzel, extern FROM benutzer/', [
            ['id' => 8, 'vorname' => 'Ex', 'nachname' => 'Tern', 'kuerzel' => 'XT', 'extern' => 1],
        ]);

        $r = StufenleitungApi::addKurs(6, ['bezeichnung' => 'D_Q2_GK1_XT', 'fach_kuerzel' => 'd', 'lehrer_id' => 8, 'lehrer_kuerzel' => 'egal']);

        $this->assertSame(8, $r['lehrer_id']);
        $this->assertSame('XT', $r['lehrer_kuerzel'], 'das Kürzel der gewählten Lehrkraft gilt');
        $this->assertSame('D', $r['fach_kuerzel']);
        $this->assertSame(1, $r['lehrer_extern']);
        $this->assertSame('Tern', $r['lehrer_nachname']);
    }

    public function testKursMitLehrerkuerzelLoestDieLehrkraftUeberDieZuordnungAuf(): void
    {
        $this->halbjahrExistiert();
        $this->db->on('/^INSERT INTO kurse/', FakeResult::insert(40));
        $this->db->onRows('/FROM lehrer_zuordnungen/', [['benutzer_id' => 8]]);
        $this->db->onRows('/SELECT id, vorname, nachname, kuerzel, extern FROM benutzer/', [
            ['id' => 8, 'vorname' => 'Anna', 'nachname' => 'Lehrer', 'kuerzel' => 'SZ', 'extern' => 0],
        ]);

        $r = StufenleitungApi::addKurs(6, ['bezeichnung' => 'D_Q2_GK1_SZ', 'lehrer_kuerzel' => ' sz ']);

        $this->assertSame(8, $r['lehrer_id']);
        $this->assertSame('SZ', $r['lehrer_kuerzel']);
        $this->assertSame(0, $r['lehrer_extern']);
    }

    public function testKursMitUnbekannterLehrkraftBleibtOhne(): void
    {
        $this->halbjahrExistiert();
        $this->db->on('/^INSERT INTO kurse/', FakeResult::insert(40));

        $r = StufenleitungApi::addKurs(6, ['bezeichnung' => 'D_Q2_GK1_ZZ', 'lehrer_id' => 999]);

        $this->assertNull($r['lehrer_id']);
        $this->assertNull($r['lehrer_vorname']);
    }

    public function testKursLoeschen(): void
    {
        $this->erwarteFehler(fn () => StufenleitungApi::deleteKurs(3), 'nicht gefunden', 404);

        $this->db->onScalar('/SELECT id FROM kurse WHERE id/', 3);
        $this->assertSame(['ok' => true], StufenleitungApi::deleteKurs(3));
        $this->assertSame([3], $this->db->aufrufe('/^DELETE FROM kurse/')[0]['params']);
    }

    public function testHalbjahrLoeschenEntferntLeereStufe(): void
    {
        $this->db->onScalar('/SELECT stufe_id FROM halbjahre WHERE id/', 11);
        $this->db->onScalar('/SELECT COUNT\(\*\) FROM halbjahre/', 0);

        $this->assertSame(['ok' => true], StufenleitungApi::deleteHalbjahr(6));

        $this->assertSame([6], $this->db->aufrufe('/^DELETE FROM halbjahre/')[0]['params']);
        $this->assertSame([11], $this->db->aufrufe('/^DELETE FROM stufen/')[0]['params']);
    }

    public function testHalbjahrLoeschenBehaeltStufeMitWeiterenHalbjahren(): void
    {
        $this->db->onScalar('/SELECT stufe_id FROM halbjahre WHERE id/', 11);
        $this->db->onScalar('/SELECT COUNT\(\*\) FROM halbjahre/', 1);

        StufenleitungApi::deleteHalbjahr(6);

        $this->assertFalse($this->db->wurdeAusgefuehrt('/^DELETE FROM stufen/'));
    }

    public function testUnbekanntesHalbjahrLoeschen(): void
    {
        $this->erwarteFehler(fn () => StufenleitungApi::deleteHalbjahr(6), 'nicht gefunden', 404);
    }
}
