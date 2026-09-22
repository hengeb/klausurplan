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

        $this->assertStringContainsString('Bitte trage die Anwesenheit', $html);
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

}
