<?php

declare(strict_types=1);

namespace Klausurplan\Tests\Unit\Api;

use Klausurplan\Api\SchuelerApi;
use Klausurplan\Tests\Support\TestCase;

final class SchuelerApiTest extends TestCase
{
    public function testNurSchuelerInnenHabenZugriff(): void
    {
        $this->alsBenutzer(5, ['lehrkraft', 'admin']);

        $this->erwarteZugriffVerweigert(fn () => SchuelerApi::meineKlausuren());
        $this->erwarteZugriffVerweigert(fn () => SchuelerApi::meineNachschreibtermine());
    }

    public function testMeineKlausurenSindAufDieEigeneIdBeschraenkt(): void
    {
        $this->alsBenutzer(17, ['schueler']);
        $rows = [['kurs_anzeigename' => 'Q2 Sport GK 1 SZ', 'klausur_nr' => 1]];
        $this->db->onRows('/FROM kurs_schueler ks/', $rows);

        $this->assertSame($rows, SchuelerApi::meineKlausuren());
        $this->assertSame([17], $this->db->log[0]['params'], 'keine fremden Daten: nur die eigene ID wird abgefragt');
        $this->assertStringContainsString('ks.schueler_id = ?', $this->db->log[0]['sql']);
    }

    public function testMeineNachschreibtermineNurFuerFehlendeOffeneOderEntschuldigte(): void
    {
        $this->alsBenutzer(17, ['schueler']);
        $rows = [['id' => 1, 'kurs_anzeigename' => 'Q2 Sport']];
        $this->db->onRows('/FROM anwesenheiten a/', $rows);

        $this->assertSame($rows, SchuelerApi::meineNachschreibtermine());
        $this->assertSame([17], $this->db->log[0]['params']);
        $this->assertStringContainsString("a.status = 'fehlend'", $this->db->log[0]['sql']);
        $this->assertStringContainsString('a.entschuldigt IS NULL OR a.entschuldigt = 1', $this->db->log[0]['sql']);
    }
}
