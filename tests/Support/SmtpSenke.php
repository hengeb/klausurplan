<?php

declare(strict_types=1);

namespace Klausurplan\Tests\Support;

use RuntimeException;

/** Startet tests/Support/smtp_senke.php und liefert die empfangenen Mails. */
final class SmtpSenke
{
    /** @var resource|null */
    private $prozess;
    private string $datei;
    public readonly int $port;

    public function __construct()
    {
        $this->datei = (string) tempnam(sys_get_temp_dir(), 'kp-mails');
        $this->port  = random_int(20000, 60000);

        $this->prozess = proc_open(
            [PHP_BINARY, __DIR__ . '/smtp_senke.php', (string) $this->port, $this->datei],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );

        // Warten, bis der Port erreichbar ist
        for ($i = 0; $i < 50; $i++) {
            $verbindung = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.1);
            if ($verbindung !== false) {
                fclose($verbindung);
                return;
            }
            usleep(100_000);
        }
        throw new RuntimeException('SMTP-Senke startet nicht');
    }

    /** SMTP-Einstellungen für Mailer/Cron (Werte für $_ENV bzw. Prozessumgebung). */
    public function umgebung(): array
    {
        return [
            'SMTP_HOST'       => '127.0.0.1',
            'SMTP_PORT'       => (string) $this->port,
            // 'none': weder SSL noch STARTTLS. (Ein leerer Wert ginge in Subprozessen verloren – proc_open verwirft leere Variablen.)
            'SMTP_ENCRYPTION' => 'none',
            'SMTP_USER'       => 'klausurplan@example.org',
            'SMTP_PASS'       => 'x',
            'SMTP_FROM_NAME'  => 'Klausurplan',
            'APP_URL'         => 'https://klausurplan.example',
        ];
    }

    /** SMTP-Einstellungen für einen Server, der nicht erreichbar ist (Fehlerfälle). */
    public static function toteUmgebung(): array
    {
        $tot = new self();
        $umgebung = $tot->umgebung();
        $tot->beenden();
        return $umgebung;
    }

    private function beenden(): void
    {
        if (is_resource($this->prozess)) {
            proc_terminate($this->prozess);
            proc_close($this->prozess);
            $this->prozess = null;
        }
    }

    /**
     * Empfangene Mails, dekodiert.
     *
     * @return list<array{an: string, betreff: string, text: string, roh: string}>
     */
    public function mails(): array
    {
        $inhalt = is_file($this->datei) ? (string) file_get_contents($this->datei) : '';
        $mails  = [];

        foreach (array_filter(explode("=====MAIL-ENDE=====", $inhalt), fn ($m) => trim($m) !== '') as $roh) {
            [$kopf, $koerper] = array_pad(preg_split("/\r?\n\r?\n/", ltrim($roh), 2), 2, '');
            $kopf = preg_replace("/\r?\n[ \t]+/", ' ', $kopf);

            preg_match('/^To: (.*)$/mi', $kopf, $an);
            preg_match('/^Subject: (.*)$/mi', $kopf, $betreff);

            $text = $koerper;
            if (preg_match('/boundary="?([^"\r\n;]+)"?/i', $kopf, $b)) {
                // multipart/alternative: den HTML-Teil verwenden
                foreach (explode('--' . $b[1], $koerper) as $teil) {
                    if (stripos($teil, 'text/html') !== false) {
                        [$teilKopf, $teilText] = array_pad(preg_split("/\r?\n\r?\n/", ltrim($teil), 2), 2, '');
                        $text = self::dekodiere($teilKopf, $teilText);
                        break;
                    }
                }
            } else {
                $text = self::dekodiere($kopf, $koerper);
            }

            $mails[] = [
                'an'      => trim($an[1] ?? ''),
                'betreff' => mb_decode_mimeheader(trim($betreff[1] ?? '')),
                'text'    => $text,
                'roh'     => $roh,
            ];
        }

        return $mails;
    }

    private static function dekodiere(string $kopf, string $text): string
    {
        if (preg_match('/Content-Transfer-Encoding:\s*quoted-printable/i', $kopf)) {
            return quoted_printable_decode($text);
        }
        if (preg_match('/Content-Transfer-Encoding:\s*base64/i', $kopf)) {
            return (string) base64_decode($text);
        }
        return $text;
    }

    public function __destruct()
    {
        $this->beenden();
        @unlink($this->datei);
    }
}
