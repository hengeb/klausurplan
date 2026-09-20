<?php

declare(strict_types=1);

namespace Klausurplan\Tests\Unit\Mail;

use Klausurplan\Mail\EmailTemplates;
use PHPUnit\Framework\TestCase;

final class EmailTemplatesTest extends TestCase
{
    private array $altEnv;

    protected function setUp(): void
    {
        $this->altEnv = $_ENV;
        $_ENV['APP_URL'] = 'https://schule.example/klausurplan/';
    }

    protected function tearDown(): void
    {
        $_ENV = $this->altEnv;
    }

    private const TOKEN = 'abc123def456';

    public function testErstmeldungEnthaeltKursDatumUndBeideLinks(): void
    {
        $html = EmailTemplates::erstmeldung(['kurs_anzeigename' => 'Q2 Sport GK 1 SZ', 'termin_datum' => '2026-01-31'], self::TOKEN);

        $this->assertStringContainsString('Bitte tragen Sie die Anwesenheit', $html);
        $this->assertStringContainsString('Q2 Sport GK 1 SZ', $html);
        $this->assertStringContainsString('31.01.2026', $html);
        $this->assertStringContainsString('href="https://schule.example/klausurplan/anwesenheit/alle-da?token=abc123def456"', $html);
        $this->assertStringContainsString('href="https://schule.example/klausurplan/anwesenheit/eingabe?token=abc123def456"', $html);
        $this->assertStringContainsString('Alle waren anwesend', $html);
        $this->assertStringContainsString('Jemand hat gefehlt', $html);
        $this->assertStringNotContainsString('Erinnerung:', $html);
    }

    public function testErinnerungIstAlsSolcheGekennzeichnet(): void
    {
        $html = EmailTemplates::erinnerung(['kurs_anzeigename' => 'Q1 Mathe LK 1', 'termin_datum' => '2026-02-03'], self::TOKEN);

        $this->assertStringContainsString('Erinnerung:', $html);
        $this->assertStringContainsString('Q1 Mathe LK 1', $html);
        $this->assertStringContainsString('token=abc123def456', $html);
    }

    public function testFehlendesDatumWirdMitStrichDargestellt(): void
    {
        $html = EmailTemplates::erstmeldung(['kurs_anzeigename' => 'X', 'termin_datum' => null], self::TOKEN);

        $this->assertStringContainsString('<strong>Datum:</strong> –', $html);
    }

    public function testKursnameWirdEscaped(): void
    {
        $html = EmailTemplates::erstmeldung(['kurs_anzeigename' => '<script>alert(1)</script>', 'termin_datum' => null], self::TOKEN);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testTokenWirdUrlKodiert(): void
    {
        $html = EmailTemplates::erstmeldung(['kurs_anzeigename' => 'X', 'termin_datum' => null], 'a b&c');

        $this->assertStringContainsString('token=a+b%26c', $html);
    }

    public function testOhneAppUrlBleibenRelativeLinks(): void
    {
        unset($_ENV['APP_URL']);

        $html = EmailTemplates::erstmeldung(['kurs_anzeigename' => 'X', 'termin_datum' => null], self::TOKEN);

        $this->assertStringContainsString('href="/anwesenheit/alle-da?token=', $html);
    }

    // ------------------------------------------------------------------ Stufenleitung

    private function klausur(string $stufe, string $kurs, ?string $datum = '2026-09-10', int|string $nr = 1, ?string $lehrkraft = 'Anna Lehrer'): array
    {
        return [
            'stufe' => $stufe, 'schuljahr' => '2025/2026', 'kurs_anzeigename' => $kurs,
            'klausur_nr' => $nr, 'termin_datum' => $datum, 'lehrkraft' => $lehrkraft,
        ];
    }

    public function testUebersichtGruppiertNachStufe(): void
    {
        $html = EmailTemplates::stufenleitungUebersicht([
            $this->klausur('Q2', 'Q2 Sport GK 1'),
            $this->klausur('Q1', 'Q1 Mathe LK 1'),
            $this->klausur('Q2', 'Q2 Deutsch GK 2'),
        ]);

        $this->assertStringContainsString('Anwesenheit noch nicht eingetragen', $html);
        $this->assertSame(1, substr_count($html, 'Q2 (2025/2026)'), 'Q2 nur einmal als Überschrift');
        $this->assertSame(1, substr_count($html, 'Q1 (2025/2026)'));
        $this->assertLessThan(strpos($html, 'Q1 (2025/2026)'), strpos($html, 'Q2 (2025/2026)'), 'Reihenfolge wie geliefert');
        $this->assertStringContainsString('Q2 Sport GK 1', $html);
        $this->assertStringContainsString('Q2 Deutsch GK 2', $html);
        $this->assertStringContainsString('10.09.2026', $html);
        $this->assertStringContainsString('Anna Lehrer', $html);
        $this->assertStringContainsString('<a href="https://schule.example/klausurplan">Klausurplan</a>', $html);
    }

    public function testUebersichtZeigtFolgeklausurenMitNummer(): void
    {
        $html = EmailTemplates::stufenleitungUebersicht([
            $this->klausur('Q2', 'Q2 Sport GK 1', nr: 2),
            $this->klausur('Q2', 'Q2 Kunst GK 1', nr: '1'),
        ]);

        $this->assertStringContainsString('Q2 Sport GK 1 (Nr. 2)', $html);
        $this->assertStringNotContainsString('Q2 Kunst GK 1 (Nr.', $html);
    }

    public function testUebersichtOhneLehrkraftUndDatum(): void
    {
        $html = EmailTemplates::stufenleitungUebersicht([$this->klausur('Q2', 'Q2 Sport GK 1', null, 1, null)]);

        $this->assertStringContainsString('>–</td>', $html);
    }

    public function testUebersichtEscapedInhalte(): void
    {
        $html = EmailTemplates::stufenleitungUebersicht([$this->klausur('Q<2', '<b>Kurs</b>', lehrkraft: '<i>x</i>')]);

        $this->assertStringNotContainsString('<b>Kurs</b>', $html);
        $this->assertStringNotContainsString('<i>x</i>', $html);
        $this->assertStringContainsString('Q&lt;2', $html);
    }

    public function testUebersichtOhneAppUrlHatKeinenLink(): void
    {
        unset($_ENV['APP_URL']);

        $html = EmailTemplates::stufenleitungUebersicht([$this->klausur('Q2', 'Q2 Sport GK 1')]);

        $this->assertStringNotContainsString('<a href=', $html);
        $this->assertStringContainsString('im Klausurplan unter', $html);
    }
}
