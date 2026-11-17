<?php

declare(strict_types=1);

namespace Klausurplan\Tests\Unit\Mail;

use Klausurplan\Mail\Mailer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MailerTest extends TestCase
{
    private array $altEnv;

    protected function setUp(): void
    {
        $this->altEnv = $_ENV;
    }

    protected function tearDown(): void
    {
        $_ENV = $this->altEnv;
    }

    public function testSendefehlerWerdenAlsRuntimeExceptionGemeldet(): void
    {
        // Nichts lauscht auf Port 1 → Verbindung scheitert sofort, ohne Netzwerkzugriff nach außen
        $_ENV['SMTP_HOST'] = '127.0.0.1';
        $_ENV['SMTP_PORT'] = '1';
        $_ENV['SMTP_USER'] = 'klausurplan@example.org';
        $_ENV['SMTP_PASS'] = 'x';
        $_ENV['SMTP_ENCRYPTION'] = '';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^E-Mail konnte nicht gesendet werden: /');

        Mailer::send('lehrkraft@example.org', 'Anna Lehrer', 'Betreff', '<p>Text</p>');
    }

    public function testUngueltigeEmpfaengeradresseWirdAbgelehnt(): void
    {
        $_ENV['SMTP_HOST'] = '127.0.0.1';
        $_ENV['SMTP_PORT'] = '1';
        $_ENV['SMTP_USER'] = 'klausurplan@example.org';

        $this->expectException(RuntimeException::class);

        Mailer::send('keine-adresse', 'Anna', 'Betreff', '<p>Text</p>');
    }

    public function testTextfassungBehaeltLinksUndLaesstHeadWeg(): void
    {
        $text = Mailer::alsText(
            '<html><head><title>Titel</title></head><body><h2>Kopf &amp; mehr</h2><p>Hallo<br>Welt</p>'
            . '<table><tr><td>Kurs:</td><td>Q2 Sport</td></tr></table>'
            . '<a href="https://x.example/a?token=1&amp;b=2" style="color:#fff">  Alle waren anwesend </a></body></html>'
        );

        $this->assertStringNotContainsString('Titel', $text);
        $this->assertStringNotContainsString('<', $text);
        $this->assertStringContainsString("Kopf & mehr\nHallo\nWelt", $text);
        $this->assertStringContainsString('Kurs: Q2 Sport', $text);
        $this->assertStringContainsString('Alle waren anwesend: https://x.example/a?token=1&b=2', $text);
        $this->assertDoesNotMatchRegularExpression("/\n{3,}/", $text);
    }
}
