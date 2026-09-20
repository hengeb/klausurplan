<?php

declare(strict_types=1);

namespace Klausurplan\Tests\Unit\Models;

use Klausurplan\Models\Zuordnung;
use Klausurplan\Tests\Support\FakeResult;
use Klausurplan\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ZuordnungTest extends TestCase
{
    /** @return array<string, array{string, array{string, string}}> */
    public static function namen(): array
    {
        return [
            'GoMST-Format'         => ['Mustermann|Max Peter', ['Mustermann', 'Max Peter']],
            'GoMST ohne Vorname'   => ['Mustermann|', ['Mustermann', '']],
            'Komma-Format'         => ['Müller, Anna', ['Müller', 'Anna']],
            'Komma mit mehr Kommas' => ['Müller, Anna, Lena', ['Müller', 'Anna, Lena']],
            'Vorname Nachname'     => ['Anna Müller', ['Müller', 'Anna']],
            'Leerraum wird entfernt' => ['  Anna Müller ', ['Müller', 'Anna']],
            'nur ein Wort'         => ['Solo', ['Solo', '']],
            'leer'                 => ['', ['', '']],
        ];
    }

    /** @param array{string, string} $erwartet */
    #[DataProvider('namen')]
    public function testParseName(string $eingabe, array $erwartet): void
    {
        $this->assertSame($erwartet, Zuordnung::parseName($eingabe));
    }

    // ------------------------------------------------------------------ Schüler*innen

    public function testGespeicherteZuordnungHatVorrangVorAutomatik(): void
    {
        $this->db->onRows('/FROM schueler_zuordnungen/', [['benutzer_id' => 17]]);

        $this->assertSame(17, Zuordnung::ermittleSchuelerId($this->db, 'Mustermann|Max'));
        $this->assertFalse($this->db->wurdeAusgefuehrt('/FROM benutzer/'), 'kein automatisches Matching nötig');
    }

    public function testAufgehobeneZuordnungSperrtDasAutomatischeMatching(): void
    {
        $this->db->onRows('/FROM schueler_zuordnungen/', [['benutzer_id' => null]]);
        $this->db->onRows('/FROM benutzer/', [['id' => 5]]); // würde passen, darf aber nicht gefragt werden

        $this->assertNull(Zuordnung::ermittleSchuelerId($this->db, 'Mustermann|Max'));
        $this->assertFalse($this->db->wurdeAusgefuehrt('/FROM benutzer/'));
    }

    public function testOhneGespeicherteZuordnungGreiftAutomatischesMatching(): void
    {
        $this->db->onRows('/FROM benutzer/', [['id' => 5]]);

        $this->assertSame(5, Zuordnung::ermittleSchuelerId($this->db, 'Mustermann|Max Peter'));

        $abfrage = $this->db->aufrufe('/FROM benutzer/')[0];
        // nachname, vorname, erster Vorname, vorname (für die Sortierung exakter Treffer zuerst)
        $this->assertSame(['Mustermann', 'Max Peter', 'Max', 'Max Peter'], $abfrage['params']);
    }

    public function testAutomatischesMatchingOhneTreffer(): void
    {
        $this->assertNull(Zuordnung::automatischesNamensmatching($this->db, 'Niemand|Nirgends'));
    }

    public function testAutomatischesMatchingMitLeeremNamenFragtNichtDieDatenbank(): void
    {
        $this->assertNull(Zuordnung::automatischesNamensmatching($this->db, ''));
        $this->assertSame([], $this->db->log);
    }

    public function testOrdneSchuelerZuOhneKurseTutNichts(): void
    {
        Zuordnung::ordneSchuelerZu($this->db, []);

        $this->assertSame([], $this->db->log);
    }

    public function testOrdneSchuelerZuAktualisiertProNameEinmalAlleEintraege(): void
    {
        $this->db->onRows('/SELECT id, name_roh FROM kurs_schueler/', [
            ['id' => 1, 'name_roh' => 'Mustermann|Max'],
            ['id' => 2, 'name_roh' => 'Mustermann|Max'],   // gleicher Name in zweitem Kurs
            ['id' => 3, 'name_roh' => 'Unbekannt|Uwe'],    // kein Konto
        ]);
        $this->db->on('/FROM schueler_zuordnungen/', fn (string $sql, array $p) => $p[0] === 'Mustermann|Max'
            ? FakeResult::rows([['benutzer_id' => 9]]) : FakeResult::leer());

        Zuordnung::ordneSchuelerZu($this->db, [10, 11]);

        $updates = $this->db->aufrufe('/^UPDATE kurs_schueler/');
        $this->assertCount(1, $updates, 'Uwe bleibt offen, Max wird gesammelt gesetzt');
        $this->assertSame([9, 1, 2], $updates[0]['params']);
        $this->assertSame([10, 11], $this->db->aufrufe('/SELECT id, name_roh/')[0]['params']);
    }

    public function testSpeichereSchuelerZuordnungSpeichertDauerhaftUndAktualisiertAlleKurse(): void
    {
        $this->db->on('/^UPDATE kurs_schueler/', FakeResult::count(3));

        $n = Zuordnung::speichereSchuelerZuordnung($this->db, 'Mustermann|Max', 17, 2);

        $this->assertSame(3, $n);
        $upsert = $this->db->aufrufe('/INSERT INTO schueler_zuordnungen/')[0];
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $upsert['sql']);
        $this->assertSame(['Mustermann|Max', 17, 2], $upsert['params']);
        $this->assertSame([17, 'Mustermann|Max'], $this->db->aufrufe('/^UPDATE kurs_schueler/')[0]['params']);
    }

    public function testSpeichereSchuelerZuordnungMitNullHebtAuf(): void
    {
        Zuordnung::speichereSchuelerZuordnung($this->db, 'Mustermann|Max', null, null);

        $this->assertSame(['Mustermann|Max', null, null], $this->db->aufrufe('/INSERT INTO schueler_zuordnungen/')[0]['params']);
        $this->assertSame([null, 'Mustermann|Max'], $this->db->aufrufe('/^UPDATE kurs_schueler/')[0]['params']);
    }

    // ------------------------------------------------------------------ Lehrkräfte

    public function testGespeicherteKuerzelZuordnungHatVorrang(): void
    {
        $this->db->onRows('/FROM lehrer_zuordnungen/', [['benutzer_id' => 4]]);

        $this->assertSame(4, Zuordnung::ermittleLehrerId($this->db, 'SZ'));
        $this->assertFalse($this->db->wurdeAusgefuehrt('/FROM benutzer/'));
    }

    public function testAufgehobeneKuerzelZuordnungSperrtAutomatik(): void
    {
        $this->db->onRows('/FROM lehrer_zuordnungen/', [['benutzer_id' => null]]);

        $this->assertNull(Zuordnung::ermittleLehrerId($this->db, 'SZ'));
        $this->assertFalse($this->db->wurdeAusgefuehrt('/FROM benutzer/'));
    }

    public function testKuerzelMatchingBevorzugtMoodleKontenVorExternen(): void
    {
        $this->db->onRows('/FROM benutzer/', [['id' => 8]]);

        $this->assertSame(8, Zuordnung::ermittleLehrerId($this->db, 'sz'));
        $abfrage = $this->db->aufrufe('/FROM benutzer/')[0];
        $this->assertStringContainsString('ORDER BY extern', $abfrage['sql']);
        $this->assertSame(['sz'], $abfrage['params']);
    }

    public function testKuerzelOhneTreffer(): void
    {
        $this->assertNull(Zuordnung::ermittleLehrerId($this->db, 'ZZ'));
    }

    public function testOrdneLehrerZuOhneKurseTutNichts(): void
    {
        Zuordnung::ordneLehrerZu($this->db, []);

        $this->assertSame([], $this->db->log);
    }

    public function testOrdneLehrerZuSetztNurGefundeneLehrkraefte(): void
    {
        $this->db->onRows('/SELECT id, lehrer_kuerzel FROM kurse/', [
            ['id' => 1, 'lehrer_kuerzel' => 'SZ'],
            ['id' => 2, 'lehrer_kuerzel' => 'SZ'],
            ['id' => 3, 'lehrer_kuerzel' => 'ZZ'],
        ]);
        $this->db->on('/FROM benutzer/', fn (string $sql, array $p) => $p[0] === 'SZ'
            ? FakeResult::rows([['id' => 6]]) : FakeResult::leer());

        Zuordnung::ordneLehrerZu($this->db, [1, 2, 3]);

        $updates = $this->db->aufrufe('/^UPDATE kurse/');
        $this->assertCount(1, $updates);
        $this->assertSame([6, 1, 2], $updates[0]['params']);
    }

    public function testSpeichereLehrerZuordnung(): void
    {
        $this->db->on('/^UPDATE kurse/', FakeResult::count(2));

        $this->assertSame(2, Zuordnung::speichereLehrerZuordnung($this->db, 'SZ', 6, 1));

        $this->assertSame(['SZ', 6, 1], $this->db->aufrufe('/INSERT INTO lehrer_zuordnungen/')[0]['params']);
        $this->assertSame([6, 'SZ'], $this->db->aufrufe('/^UPDATE kurse/')[0]['params']);
    }
}
