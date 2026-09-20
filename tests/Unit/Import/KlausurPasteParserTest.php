<?php

declare(strict_types=1);

namespace Klausurplan\Tests\Unit\Import;

use Klausurplan\Import\KlausurPasteParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KlausurPasteParserTest extends TestCase
{
    /** @return array<string, array{string, ?string}> */
    public static function datumFaelle(): array
    {
        return [
            'vierstelliges Jahr'  => ['31.01.2024', '2024-01-31'],
            'zweistelliges Jahr'  => ['5.3.24', '2024-03-05'],
            'mit Leerraum'        => ['  01.02.2025 ', '2025-02-01'],
            'leer'                => ['', null],
            'ungültiger Tag'      => ['32.01.2024', null],
            'kein 29.2. 2023'     => ['29.02.2023', null],
            'Schaltjahr 29.2.'    => ['29.02.2024', '2024-02-29'],
            'ISO-Format'          => ['2024-01-31', null],
            'Text'                => ['morgen', null],
        ];
    }

    #[DataProvider('datumFaelle')]
    public function testParseDatum(string $eingabe, ?string $erwartet): void
    {
        $this->assertSame($erwartet, KlausurPasteParser::parseDatum($eingabe));
    }

    /** @return array<string, array{string, ?string}> */
    public static function uhrzeitFaelle(): array
    {
        return [
            'HH:MM'             => ['08:00', '08:00:00'],
            'H:MM'              => ['8:00', '08:00:00'],
            'mit Sekunden'      => ['13:45:30', '13:45:00'],
            'leer'              => ['', null],
            'Stunde zu groß'    => ['24:00', null],
            'Minute zu groß'    => ['12:60', null],
            'ohne Doppelpunkt'  => ['0800', null],
            'Text'              => ['acht Uhr', null],
        ];
    }

    #[DataProvider('uhrzeitFaelle')]
    public function testParseUhrzeit(string $eingabe, ?string $erwartet): void
    {
        $this->assertSame($erwartet, KlausurPasteParser::parseUhrzeit($eingabe));
    }

    /** @return array<string, array{string, ?int}> */
    public static function dauerFaelle(): array
    {
        return [
            'Minuten'  => ['90', 90],
            'leer'     => ['', null],
            'null'     => ['0', null],
            'negativ'  => ['-5', null],
            'Dezimal'  => ['90.5', null],
            'Text'     => ['lang', null],
            'Leerraum' => [' 45 ', 45],
        ];
    }

    #[DataProvider('dauerFaelle')]
    public function testParseDauer(string $eingabe, ?int $erwartet): void
    {
        $this->assertSame($erwartet, KlausurPasteParser::parseDauer($eingabe));
    }

    public function testParseVollstaendigeZeile(): void
    {
        $ergebnis = KlausurPasteParser::parse([
            ['Kurs' => 'SP_Q2_GK1_SZ', 'Datum' => '31.01.2024', 'Uhrzeit' => '8:00', 'Dauer' => '90'],
        ]);

        $this->assertSame([], $ergebnis['fehler']);
        $this->assertSame([[
            'kurs_kuerzel'   => 'SP_Q2_GK1_SZ',
            'termin_datum'   => '2024-01-31',
            'termin_uhrzeit' => '08:00:00',
            'dauer_minuten'  => 90,
        ]], $ergebnis['zeilen']);
    }

    public function testSpaltenüberschriftenSindCaseInsensitiv(): void
    {
        $ergebnis = KlausurPasteParser::parse([['  KURS ' => 'X', 'datum' => '01.02.2025']]);

        $this->assertSame('X', $ergebnis['zeilen'][0]['kurs_kuerzel']);
        $this->assertSame('2025-02-01', $ergebnis['zeilen'][0]['termin_datum']);
    }

    public function testNurKursIstPflicht(): void
    {
        $ergebnis = KlausurPasteParser::parse([['Kurs' => 'X']]);

        $this->assertSame([], $ergebnis['fehler']);
        $this->assertNull($ergebnis['zeilen'][0]['termin_datum']);
        $this->assertNull($ergebnis['zeilen'][0]['termin_uhrzeit']);
        $this->assertNull($ergebnis['zeilen'][0]['dauer_minuten']);
    }

    public function testFehlerWerdenProZeileGemeldetUndFehlerhafteZeilenUebersprungen(): void
    {
        $ergebnis = KlausurPasteParser::parse([
            ['Kurs' => '',  'Datum' => '01.02.2025'],                    // 1: Kurs fehlt
            ['Kurs' => 'A', 'Datum' => 'gestern'],                       // 2: Datum
            ['Kurs' => 'B', 'Uhrzeit' => '25:00'],                       // 3: Uhrzeit
            ['Kurs' => 'C', 'Dauer' => 'lang'],                          // 4: Dauer
            ['Kurs' => 'D', 'Datum' => '01.02.2025', 'Dauer' => '60'],   // 5: gültig
        ]);

        $this->assertCount(1, $ergebnis['zeilen']);
        $this->assertSame('D', $ergebnis['zeilen'][0]['kurs_kuerzel']);
        $this->assertSame([1, 2, 3, 4], array_column($ergebnis['fehler'], 'zeile'));
        $this->assertStringContainsString('Kurs fehlt', $ergebnis['fehler'][0]['meldung']);
        $this->assertStringContainsString('Ungültiges Datum', $ergebnis['fehler'][1]['meldung']);
        $this->assertStringContainsString('Ungültige Uhrzeit', $ergebnis['fehler'][2]['meldung']);
        $this->assertStringContainsString('Ungültige Dauer', $ergebnis['fehler'][3]['meldung']);
    }

    public function testLeereEingabe(): void
    {
        $this->assertSame(['zeilen' => [], 'fehler' => []], KlausurPasteParser::parse([]));
    }
}
