<?php

declare(strict_types=1);

namespace Klausurplan\Tests\Unit\Api;

use Klausurplan\Api\AdminApi;
use Klausurplan\Tests\Support\TestCase;

final class AdminApiTest extends TestCase
{
    private array $altEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->altEnv = $_ENV;
    }

    protected function tearDown(): void
    {
        $_ENV = $this->altEnv;
        parent::tearDown();
    }

    public function testBenutzerlisteZerlegtDieRollen(): void
    {
        $this->db->onRows('/FROM benutzer b/', [
            ['id' => 1, 'vorname' => 'A', 'rollen_csv' => 'admin,lehrkraft', 'extern' => 0],
            ['id' => 2, 'vorname' => 'B', 'rollen_csv' => null, 'extern' => 1],
        ]);

        $liste = AdminApi::getBenutzer();

        $this->assertSame(['admin', 'lehrkraft'], $liste[0]['rollen']);
        $this->assertSame([], $liste[1]['rollen']);
        $this->assertArrayNotHasKey('rollen_csv', $liste[0]);
        $this->assertSame(1, $liste[1]['extern'], 'externe Lehrkräfte sind erkennbar');
        $this->assertStringContainsString('b.extern', $this->db->log[0]['sql']);
    }

    // ------------------------------------------------------------------ Rollen

    public function testRollenEinerUnbekanntenPersonSetzen(): void
    {
        $this->erwarteFehler(fn () => AdminApi::setRollen(9, ['admin']), 'nicht gefunden', 404);
    }

    public function testRollenWerdenGefiltertUndVollstaendigErsetzt(): void
    {
        $this->db->onScalar('/SELECT 1 FROM benutzer WHERE id/', 1);

        $r = AdminApi::setRollen(4, ['lehrkraft', 'superuser', 'lehrkraft', 'stufenleitung', 5]);

        $this->assertSame(['id' => 4, 'rollen' => ['lehrkraft', 'stufenleitung']], $r);
        $this->assertSame([4], $this->db->aufrufe('/^DELETE FROM rollen/')[0]['params']);
        $this->assertSame([4, 'lehrkraft', 4, 'stufenleitung'], $this->db->aufrufe('/^INSERT INTO rollen/')[0]['params']);
        $this->assertFalse($this->db->wurdeAusgefuehrt('/DELETE FROM stufenleitungen/'), 'Stufenleitung bleibt bestehen');
    }

    public function testEntzugDerStufenleitungRolleEntferntDieStufenZuordnungen(): void
    {
        $this->db->onScalar('/SELECT 1 FROM benutzer WHERE id/', 1);

        AdminApi::setRollen(4, ['lehrkraft']);

        $this->assertSame([4], $this->db->aufrufe('/^DELETE FROM stufenleitungen/')[0]['params']);
    }

    public function testAlleRollenEntziehen(): void
    {
        $this->db->onScalar('/SELECT 1 FROM benutzer WHERE id/', 1);

        $r = AdminApi::setRollen(4, []);

        $this->assertSame([], $r['rollen']);
        $this->assertFalse($this->db->wurdeAusgefuehrt('/^INSERT INTO rollen/'));
    }

    // ------------------------------------------------------------------ Moodle-Sync

    public function testMoodleSyncOhneKonfiguration(): void
    {
        unset($_ENV['MOODLE_URL'], $_ENV['MOODLE_API_TOKEN']);

        $this->erwarteFehler(fn () => AdminApi::moodleSync(), 'nicht konfiguriert');
    }

    // ------------------------------------------------------------------ Fächer

    public function testFaecherliste(): void
    {
        $rows = [['kuerzel' => 'D', 'bezeichnung' => 'Deutsch']];
        $this->db->onRows('/FROM fach_bezeichnungen/', $rows);

        $this->assertSame($rows, AdminApi::getFaecher());
    }

    public function testFachSpeichernSchreibtKuerzelGross(): void
    {
        $r = AdminApi::updateFach('sp', 'Sport');

        $this->assertSame(['kuerzel' => 'SP', 'bezeichnung' => 'Sport'], $r);
        $this->assertSame(['SP', 'Sport'], $this->db->aufrufe('/ON DUPLICATE KEY UPDATE/')[0]['params']);
    }

    public function testFachWirdGeprueft(): void
    {
        $this->erwarteFehler(fn () => AdminApi::updateFach('', 'X'), 'Ungültiges Kürzel');
        $this->erwarteFehler(fn () => AdminApi::updateFach('ZUUUUUUUUUUU', 'X'), 'Ungültiges Kürzel');
        $this->erwarteFehler(fn () => AdminApi::updateFach('D', ''), 'Bezeichnung darf nicht leer');
    }

    public function testFachLoeschen(): void
    {
        $this->assertSame(['ok' => true], AdminApi::deleteFach('sp'));
        $this->assertSame(['SP'], $this->db->aufrufe('/^DELETE FROM fach_bezeichnungen/')[0]['params']);
    }

    // ------------------------------------------------------------------ Stufen

    public function testStufenUndZuordnungenLesen(): void
    {
        $this->db->onRows('/FROM stufen ORDER BY/', [['id' => 1, 'name' => 'Q2', 'schuljahr' => '2025/2026']]);
        $this->assertSame('Q2', AdminApi::getStufen()[0]['name']);

        $this->db->onRows('/SELECT stufe_id FROM stufenleitungen/', [['stufe_id' => 1], ['stufe_id' => 2]]);
        $this->assertSame([1, 2], AdminApi::getStufenleitungen(4));
    }

    public function testStufenleitungenSetzenErsetztDieAuswahl(): void
    {
        $this->db->onScalar('/SELECT 1 FROM benutzer WHERE id/', 1);

        $r = AdminApi::setStufenleitungen(4, ['1', 2, 2, 'x']);

        $this->assertSame(['ok' => true, 'stufen' => [1, 2, 0]], $r);
        $this->assertSame([4], $this->db->aufrufe('/^DELETE FROM stufenleitungen/')[0]['params']);
        $this->assertSame([4, 1, 4, 2, 4, 0], $this->db->aufrufe('/^INSERT INTO stufenleitungen/')[0]['params']);
    }

    public function testStufenleitungenLeerenUndUnbekanntePerson(): void
    {
        $this->erwarteFehler(fn () => AdminApi::setStufenleitungen(9, [1]), 'nicht gefunden', 404);

        $this->db->onScalar('/SELECT 1 FROM benutzer WHERE id/', 1);
        AdminApi::setStufenleitungen(4, []);
        $this->assertFalse($this->db->wurdeAusgefuehrt('/^INSERT INTO stufenleitungen/'));
    }
}
