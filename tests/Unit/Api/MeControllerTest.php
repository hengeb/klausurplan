<?php

declare(strict_types=1);

namespace Klausurplan\Tests\Unit\Api;

use Klausurplan\Api\MeController;
use Klausurplan\Tests\Support\TestCase;

final class MeControllerTest extends TestCase
{
    public function testStufenleitungErhaeltIhreStufen(): void
    {
        $this->alsBenutzer(2, ['lehrkraft', 'stufenleitung'], 'Anna', 'Lehrer');
        $stufen = [['id' => 1, 'name' => 'Q2', 'schuljahr' => '2025/2026']];
        $this->db->onRows('/FROM stufenleitungen/', $stufen);

        $me = MeController::handle();

        $this->assertSame(2, $me['id']);
        $this->assertSame('Anna', $me['vorname']);
        $this->assertSame(['lehrkraft', 'stufenleitung'], $me['rollen']);
        $this->assertSame($stufen, $me['stufen']);
        $this->assertSame([2], $this->db->log[0]['params']);
    }

    public function testStufenleitungOhneStufeErhaeltLeereListe(): void
    {
        $this->alsBenutzer(2, ['stufenleitung']);

        $this->assertSame([], MeController::handle()['stufen']);
    }

    public function testAndereRollenFragenDieStufenNichtAb(): void
    {
        $this->alsBenutzer(3, ['lehrkraft', 'admin']);

        $me = MeController::handle();

        $this->assertSame([], $me['stufen']);
        $this->assertSame([], $this->db->log);
    }
}
