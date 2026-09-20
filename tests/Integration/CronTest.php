<?php

declare(strict_types=1);

namespace Klausurplan\Tests\Integration;

use Klausurplan\Tests\Support\IntegrationTestCase;
use Klausurplan\Tests\Support\SmtpSenke;

/**
 * Führt src/Cron/erinnerungen_senden.php als eigenen Prozess aus – gegen die Test-Datenbank
 * und einen lokalen SMTP-Server. Das Skript liegt dazu in einer Temp-Kopie des Projekts, damit
 * seine .env (Dotenv lädt sie zwingend) die Testwerte enthält und keine echte .env berührt wird.
 */
final class CronTest extends IntegrationTestCase
{
    private string $projekt;
    private SmtpSenke $senke;
    private int $sl;
    private int $sz;
    private int $q2;
    private int $q1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projekt = sys_get_temp_dir() . '/kp-cron-' . bin2hex(random_bytes(4));
        mkdir($this->projekt);
        self::kopiere(__DIR__ . '/../../src', $this->projekt . '/src');
        symlink(realpath(__DIR__ . '/../../vendor'), $this->projekt . '/vendor');
        file_put_contents($this->projekt . '/.env', "APP_ENV=test\n");

        $this->senke = new SmtpSenke();

        $this->sl = $this->fx->benutzer('Sarah', 'Leitung', ['stufenleitung', 'lehrkraft'], null, 'sl@example.org');
        $this->sz = $this->fx->benutzer('Anna', 'Lehrer (SZ)', ['lehrkraft'], 'SZ', 'sz@example.org');
        $this->q2 = $this->fx->stufe('Q2');
        $this->q1 = $this->fx->stufe('Q1');
        $this->fx->stufenleitung($this->sl, $this->q2);
    }

    protected function tearDown(): void
    {
        if (isset($this->projekt) && is_dir($this->projekt)) {
            unlink($this->projekt . '/vendor');
            self::loesche($this->projekt);
        }
        parent::tearDown();
    }

    private static function kopiere(string $von, string $nach): void
    {
        mkdir($nach, 0777, true);
        foreach (scandir($von) as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            is_dir("$von/$e") ? self::kopiere("$von/$e", "$nach/$e") : copy("$von/$e", "$nach/$e");
        }
    }

    private static function loesche(string $pfad): void
    {
        foreach (scandir($pfad) as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            is_dir("$pfad/$e") ? self::loesche("$pfad/$e") : unlink("$pfad/$e");
        }
        rmdir($pfad);
    }

    /** @return string Ausgabe des Cron-Skripts */
    private function cron(?array $smtpUmgebung = null): string
    {
        $env = array_merge($_SERVER, $_ENV, self::dbUmgebung(), $smtpUmgebung ?? $this->senke->umgebung());
        $prozess = proc_open(
            [PHP_BINARY, $this->projekt . '/src/Cron/erinnerungen_senden.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            array_filter($env, 'is_string'),
        );
        $ausgabe = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        proc_close($prozess);
        return $ausgabe;
    }

    /** Datum vor $n Tagen – in UTC, weil auch die Test-Datenbank (NOW()) in UTC rechnet. */
    private function tage(int $n): string
    {
        return gmdate('Y-m-d', strtotime("-{$n} days"));
    }

    /** Kurs mit Prüfling und Klausur; liefert die Klausur-ID. */
    private function klausur(int $stufe, string $kuerzel, ?int $lehrer, string $datum, ?string $uhrzeit = '08:00'): int
    {
        $kurs = $this->fx->kurs($this->fx->halbjahr($stufe), $kuerzel, $lehrer, 'SZ', 'GK', "Kurs $kuerzel");
        $this->fx->kursSchueler($kurs, 'Mustermann|Max');
        return $this->fx->klausur($kurs, $datum, $uhrzeit);
    }

    // ------------------------------------------------------------------ Fachlehrkräfte

    public function testErstmeldungGehtAnDieFachlehrkraftNurEinmal(): void
    {
        $klausur = $this->klausur($this->q2, 'A', $this->sz, $this->tage(2));

        $ausgabe = $this->cron();

        $this->assertStringContainsString('OK  (erstmeldung)', $ausgabe);
        $mails = array_values(array_filter($this->senke->mails(), fn ($m) => str_contains($m['an'], 'sz@example.org')));
        $this->assertCount(1, $mails);
        $this->assertStringStartsWith('Anwesenheit Klausur Kurs A am ', $mails[0]['betreff']);
        $this->assertStringContainsString('alle-da?token=', $mails[0]['text']);
        $this->assertSame(1, $this->fx->zaehle("SELECT COUNT(*) FROM email_benachrichtigungen WHERE klausur_id = ? AND typ = 'erstmeldung'", [$klausur]));

        $this->cron();
        $this->assertSame(1, $this->fx->zaehle('SELECT COUNT(*) FROM email_benachrichtigungen WHERE klausur_id = ?', [$klausur]), 'keine zweite Erstmeldung');
    }

    public function testErinnerungNachSiebenTagenOhneAntwort(): void
    {
        $klausur = $this->klausur($this->q2, 'A', $this->sz, $this->tage(20));
        $this->db->prepare("INSERT INTO email_benachrichtigungen (klausur_id, empfaenger_id, typ, token, gesendet_am) VALUES (?, ?, 'erstmeldung', ?, NOW() - INTERVAL 8 DAY)")
            ->execute([$klausur, $this->sz, bin2hex(random_bytes(32))]);

        $this->cron();

        $this->assertSame(1, $this->fx->zaehle("SELECT COUNT(*) FROM email_benachrichtigungen WHERE typ = 'erinnerung'"));
        $mail = array_values(array_filter($this->senke->mails(), fn ($m) => str_contains($m['an'], 'sz@example.org')))[0];
        $this->assertStringStartsWith('[Erinnerung] ', $mail['betreff']);

        $this->cron();
        $this->assertSame(1, $this->fx->zaehle("SELECT COUNT(*) FROM email_benachrichtigungen WHERE typ = 'erinnerung'"), 'Erinnerung nur einmalig');
    }

    public function testKeineErinnerungWennBereitsBeantwortetOderZuFrisch(): void
    {
        $beantwortet = $this->klausur($this->q2, 'A', $this->sz, $this->tage(20));
        $frisch = $this->klausur($this->q2, 'B', $this->sz, $this->tage(20));
        $this->db->prepare("INSERT INTO email_benachrichtigungen (klausur_id, empfaenger_id, typ, token, gesendet_am, beantwortet_am) VALUES (?, ?, 'erstmeldung', ?, NOW() - INTERVAL 9 DAY, NOW())")
            ->execute([$beantwortet, $this->sz, bin2hex(random_bytes(32))]);
        $this->db->prepare("INSERT INTO email_benachrichtigungen (klausur_id, empfaenger_id, typ, token, gesendet_am) VALUES (?, ?, 'erstmeldung', ?, NOW() - INTERVAL 2 DAY)")
            ->execute([$frisch, $this->sz, bin2hex(random_bytes(32))]);

        $this->cron();

        $this->assertSame(0, $this->fx->zaehle("SELECT COUNT(*) FROM email_benachrichtigungen WHERE typ = 'erinnerung'"));
    }

    public function testKlausurenOhneTerminOhneLehrkraftOderInDerZukunftBekommenKeineMail(): void
    {
        $this->klausur($this->q2, 'A', $this->sz, gmdate('Y-m-d', strtotime('+3 days')));   // Zukunft
        $this->klausur($this->q2, 'B', null, $this->tage(3));                             // keine Lehrkraft
        $this->klausur($this->q2, 'C', $this->sz, $this->tage(3), null);                  // ohne Uhrzeit
        $ohneMail = $this->fx->benutzer('Otto', 'Ohne (OO)', ['lehrkraft'], 'OO', null);
        $this->klausur($this->q2, 'D', $ohneMail, $this->tage(3));                        // Lehrkraft ohne E-Mail

        $ausgabe = $this->cron();

        $this->assertSame([], array_values(array_filter($this->senke->mails(), fn ($m) => !str_contains($m['an'], 'sl@example.org'))));
        $this->assertStringContainsString('0 gesendet', $ausgabe);
    }

    public function testFehlgeschlagenerVersandWirdGemeldetUndZaehltAlsFehler(): void
    {
        $this->klausur($this->q2, 'A', $this->sz, $this->tage(2));

        $ausgabe = $this->cron(SmtpSenke::toteUmgebung());

        $this->assertStringContainsString('ERR (erstmeldung)', $ausgabe);
        $this->assertStringContainsString('1 Fehler', $ausgabe);
    }

    // ------------------------------------------------------------------ Stufenleitung

    private function slMails(): array
    {
        return array_values(array_filter($this->senke->mails(), fn ($m) => str_contains($m['an'], 'sl@example.org')));
    }

    public function testStufenleitungErhaeltEineWocheNachDerKlausurEineUebersichtNurFuerEigeneStufen(): void
    {
        $this->klausur($this->q2, 'EIGENE', $this->sz, $this->tage(10));
        $this->klausur($this->q1, 'FREMDE', $this->sz, $this->tage(10));   // nicht die Stufe der SL

        $ausgabe = $this->cron();

        $this->assertStringContainsString('OK  (Stufenleitung): 1 Klausur(en) → sl@example.org', $ausgabe);
        $mails = $this->slMails();
        $this->assertCount(1, $mails);
        $this->assertSame('Klausurplan: Anwesenheit noch nicht eingetragen', $mails[0]['betreff']);
        $this->assertStringContainsString('Kurs EIGENE', $mails[0]['text']);
        $this->assertStringNotContainsString('Kurs FREMDE', $mails[0]['text']);
        $this->assertStringContainsString('Q2 (2025/2026)', $mails[0]['text']);
    }

    public function testStufenleitungsUebersichtKommtProKlausurNurEinmal(): void
    {
        $this->klausur($this->q2, 'A', $this->sz, $this->tage(10));
        $this->cron();
        $this->assertCount(1, $this->slMails());

        $zweite = $this->klausur($this->q2, 'B', $this->sz, $this->tage(9));
        $this->cron();

        $mails = $this->slMails();
        $this->assertCount(2, $mails);
        $this->assertStringContainsString('Kurs B', $mails[1]['text']);
        $this->assertStringNotContainsString('Kurs A', $mails[1]['text'], 'bereits gemeldete Klausur wird nicht wiederholt');
        $this->assertSame(2, $this->fx->zaehle('SELECT COUNT(*) FROM stufenleitung_erinnerungen WHERE benutzer_id = ?', [$this->sl]));
        unset($zweite);
    }

    public function testStufenleitungsUebersichtNurBeiFehlenderAnwesenheitUndNachSiebenTagen(): void
    {
        $erfasst = $this->klausur($this->q2, 'ERFASST', $this->sz, $this->tage(10));
        $ks = $this->fx->wert('SELECT id FROM kurs_schueler LIMIT 1');
        $this->fx->anwesenheit($erfasst, (int) $ks, 'anwesend');
        $this->klausur($this->q2, 'FRISCH', $this->sz, $this->tage(3));
        $this->klausur($this->q2, 'OFFEN', $this->sz, $this->tage(8), null);   // ohne Uhrzeit zählt der Tagesende
        $leer = $this->fx->kurs($this->fx->halbjahr($this->q2), 'LEER', $this->sz, 'SZ');
        $this->fx->klausur($leer, $this->tage(10), '08:00');                    // Kurs ohne Prüflinge

        $this->cron();

        $text = $this->slMails()[0]['text'];
        $this->assertStringContainsString('Kurs OFFEN', $text);
        foreach (['ERFASST', 'FRISCH', 'Kurs LEER'] as $nicht) {
            $this->assertStringNotContainsString($nicht, $text);
        }
    }

    public function testStufenleitungOhneStufeOderOhneMailAdresseBekommtNichts(): void
    {
        $this->fx->benutzer('Zoe', 'Ohne Stufe', ['stufenleitung'], null, 'zoe@example.org');
        $ohneMail = $this->fx->benutzer('Ole', 'Ohne Mail', ['stufenleitung']);
        $this->fx->stufenleitung($ohneMail, $this->q2);
        $this->klausur($this->q2, 'A', $this->sz, $this->tage(10));

        $this->cron();

        $an = array_column($this->senke->mails(), 'an');
        $this->assertCount(0, array_filter($an, fn ($a) => str_contains($a, 'zoe@')));
        $this->assertCount(1, array_filter($an, fn ($a) => str_contains($a, 'sl@')));
    }

    public function testFehlgeschlagenerVersandMarkiertNichtsAlsGesendet(): void
    {
        $this->klausur($this->q2, 'A', $this->sz, $this->tage(10));

        $ausgabe = $this->cron(SmtpSenke::toteUmgebung());

        $this->assertStringContainsString('ERR (Stufenleitung)', $ausgabe);
        $this->assertSame(0, $this->fx->zaehle('SELECT COUNT(*) FROM stufenleitung_erinnerungen'), 'wird beim nächsten Lauf erneut versucht');
    }
}
