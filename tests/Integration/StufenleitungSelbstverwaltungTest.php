<?php

declare(strict_types=1);

namespace Klausurplan\Tests\Integration;

use Klausurplan\Api\MeController;
use Klausurplan\Api\StufenleitungApi;
use Klausurplan\Tests\Support\IntegrationTestCase;

/** Stufenleitung verwaltet ihre Zuständigkeit selbst; Import macht automatisch zuständig. */
final class StufenleitungSelbstverwaltungTest extends IntegrationTestCase
{
    private int $sl;
    private int $q1;
    private int $q2;
    private array $tempDateien = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sl = $this->fx->benutzer('Sarah', 'Leitung', ['stufenleitung', 'lehrkraft']);
        $this->q1 = $this->fx->stufe('Q1');
        $this->q2 = $this->fx->stufe('Q2');
        $this->alsBenutzer($this->sl, ['stufenleitung', 'lehrkraft']);
    }

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->tempDateien, 'file_exists'));
        parent::tearDown();
    }

    private function meineIds(): array
    {
        return array_map('intval', array_column(array_filter(StufenleitungApi::getMeineStufen(), fn ($s) => (int) $s['ist_meine'] === 1), 'id'));
    }

    private function importiere(string $stufe, string $jahr = '2025'): array
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'gomst');
        file_put_contents($tmp, "Nachname|Vorname|Fach|Fachlehrer|Kursart|Kurs|Jahrgang|Abschnitt|Jahr\r\nA|Anna|M|MA|LK1|M_{$stufe}_LK1_MA|{$stufe}|1|{$jahr}\r\n");
        $this->tempDateien[] = $tmp;
        $_FILES = ['datei' => ['tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK]];
        return StufenleitungApi::gomstImport();
    }

    public function testUebernehmenUndAbgeben(): void
    {
        $this->assertSame([], $this->meineIds());

        StufenleitungApi::meineStufeUebernehmen($this->q1);
        StufenleitungApi::meineStufeUebernehmen($this->q1); // doppelt ist unschädlich
        StufenleitungApi::meineStufeUebernehmen($this->q2);
        $this->assertEqualsCanonicalizing([$this->q1, $this->q2], $this->meineIds());

        StufenleitungApi::meineStufeAbgeben($this->q1);
        $this->assertSame([$this->q2], $this->meineIds());
    }

    public function testJedeStufenleitungVerwaltetNurIhreEigeneZustaendigkeit(): void
    {
        $andere = $this->fx->benutzer('Otto', 'Anders', ['stufenleitung']);
        $this->fx->stufenleitung($andere, $this->q1);

        StufenleitungApi::meineStufeAbgeben($this->q1);

        $this->assertSame(1, $this->fx->zaehle('SELECT COUNT(*) FROM stufenleitungen WHERE benutzer_id = ?', [$andere]), 'fremde Zuständigkeit bleibt');
    }

    public function testStufenleitungOhneStufeIstMoeglich(): void
    {
        $me = MeController::handle();

        $this->assertSame([], $me['stufen']);
        $this->assertContains('stufenleitung', $me['rollen']);
    }

    public function testMeStufenListetDieZustaendigkeit(): void
    {
        StufenleitungApi::meineStufeUebernehmen($this->q2);

        $this->assertSame([['id' => $this->q2, 'name' => 'Q2', 'schuljahr' => '2025/2026']],
            array_map(fn ($s) => ['id' => (int) $s['id']] + $s, MeController::handle()['stufen']));
    }

    public function testImportMachtZustaendigUndMeldetNurNeues(): void
    {
        $erst = $this->importiere('Q2');
        $this->assertSame([$this->q2], array_map(fn ($s) => (int) $s['id'], $erst['stufenleitung_neu']));
        $this->assertSame([$this->q2], $this->meineIds());

        $zweit = $this->importiere('Q2');
        $this->assertSame([], $zweit['stufenleitung_neu']);

        StufenleitungApi::meineStufeAbgeben($this->q2);
        $dritt = $this->importiere('Q2');
        $this->assertCount(1, $dritt['stufenleitung_neu'], 'nach dem Abgeben ist die Stufe beim nächsten Import wieder „neu“');
    }

    public function testImportMachtAuchFuerFremdeStufenZustaendigUndAndereBleibenUnberuehrt(): void
    {
        $andere = $this->fx->benutzer('Otto', 'Anders', ['stufenleitung']);
        $this->fx->stufenleitung($andere, $this->q2);

        $r = $this->importiere('Q2');

        $this->assertCount(1, $r['stufenleitung_neu']);
        $this->assertSame(2, $this->fx->zaehle('SELECT COUNT(*) FROM stufenleitungen WHERE stufe_id = ?', [$this->q2]), 'mehrere Stufenleitungen je Stufe');
    }

    public function testAdminOhneStufenleitungRolleWirdNichtZustaendig(): void
    {
        $admin = $this->fx->benutzer('Ada', 'Admin', ['admin']);
        $this->alsBenutzer($admin, ['admin']);

        $r = $this->importiere('Q2');

        $this->assertSame([], $r['stufenleitung_neu']);
        $this->assertSame(0, $this->fx->zaehle('SELECT COUNT(*) FROM stufenleitungen WHERE benutzer_id = ?', [$admin]));
    }

    public function testEntzugDerRolleEntferntDieZustaendigkeit(): void
    {
        StufenleitungApi::meineStufeUebernehmen($this->q1);

        \Klausurplan\Api\AdminApi::setRollen($this->sl, ['lehrkraft']);

        $this->assertSame(0, $this->fx->zaehle('SELECT COUNT(*) FROM stufenleitungen WHERE benutzer_id = ?', [$this->sl]));
    }
}
