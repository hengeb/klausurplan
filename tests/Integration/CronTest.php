<?php

declare(strict_types=1);

namespace Klausurplan\Tests\Integration;

use Klausurplan\Auth\MoodleApi;
use Klausurplan\Tests\Support\IntegrationTestCase;
use Klausurplan\Tests\Support\MoodleAttrappe;
use Klausurplan\Tests\Support\SmtpSenke;

/**
 * Führt src/Cron/cron_script.php als eigenen Prozess aus – gegen die Test-Datenbank
 * und einen lokalen SMTP-Server. Das Skript liegt dazu in einer Temp-Kopie des Projekts, damit
 * seine .env (Dotenv lädt sie zwingend) die Testwerte enthält und keine echte .env berührt wird.
 */
final class CronTest extends IntegrationTestCase
{
    private string $projekt;
    private SmtpSenke $senke;
    private int $sz;
    private int $q2;
    private array $altEnv;

    protected function setUp(): void
    {
        $this->altEnv = $_ENV;
        parent::setUp();

        $this->projekt = sys_get_temp_dir() . '/kp-cron-' . bin2hex(random_bytes(4));
        mkdir($this->projekt);
        self::kopiere(__DIR__ . '/../../src', $this->projekt . '/src');
        symlink(realpath(__DIR__ . '/../../vendor'), $this->projekt . '/vendor');
        file_put_contents($this->projekt . '/.env', "APP_ENV=test\n");

        $this->senke = new SmtpSenke();

        $this->sz = $this->fx->benutzer('Anna', 'Lehrer (SZ)', ['lehrkraft'], 'SZ', 'sz@example.org');
        $this->q2 = $this->fx->stufe('Q2');
    }

    protected function tearDown(): void
    {
        if (isset($this->projekt) && is_dir($this->projekt)) {
            unlink($this->projekt . '/vendor');
            self::loesche($this->projekt);
        }
        $_ENV = $this->altEnv;
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
            [PHP_BINARY, $this->projekt . '/src/Cron/cron_script.php'],
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

        // Derselbe Tag: keine zweite Mail
        $this->cron();
        $this->assertSame(1, $this->fx->zaehle('SELECT COUNT(*) FROM email_benachrichtigungen WHERE klausur_id = ?', [$klausur]));
    }

    public function testErinnerungKommtTaeglichSolangeKeineAnwesenheitErfasstIst(): void
    {
        $klausur = $this->klausur($this->q2, 'A', $this->sz, $this->tage(5));
        $this->db->prepare("INSERT INTO email_benachrichtigungen (klausur_id, empfaenger_id, typ, token, gesendet_am) VALUES (?, ?, 'erstmeldung', ?, NOW() - INTERVAL 3 DAY)")
            ->execute([$klausur, $this->sz, bin2hex(random_bytes(32))]);

        // 3 Tage seit der Erstmeldung her → eine Erinnerung
        $this->cron();
        $this->assertSame(1, $this->fx->zaehle("SELECT COUNT(*) FROM email_benachrichtigungen WHERE klausur_id = ? AND typ = 'erinnerung'", [$klausur]));

        // Derselbe Tag: keine zweite Erinnerung
        $this->cron();
        $this->assertSame(1, $this->fx->zaehle("SELECT COUNT(*) FROM email_benachrichtigungen WHERE klausur_id = ? AND typ = 'erinnerung'", [$klausur]));

        // Die (einzige) Erinnerung liegt jetzt "gestern" → nächster Lauf schickt die zweite
        $this->db->prepare("UPDATE email_benachrichtigungen SET gesendet_am = NOW() - INTERVAL 1 DAY WHERE klausur_id = ? AND typ = 'erinnerung'")
            ->execute([$klausur]);
        $this->cron();

        $this->assertSame(2, $this->fx->zaehle("SELECT COUNT(*) FROM email_benachrichtigungen WHERE klausur_id = ? AND typ = 'erinnerung'", [$klausur]));
        $mails = array_values(array_filter($this->senke->mails(), fn ($m) => str_contains($m['an'], 'sz@example.org')));
        $this->assertCount(2, $mails, 'zwei Erinnerungen versandt (keine erneute Erstmeldung)');
        $this->assertStringStartsWith('[Erinnerung] ', $mails[0]['betreff']);
    }

    public function testKeineErinnerungWennDieLetzteMailNochKeinenTagHer(): void
    {
        $klausur = $this->klausur($this->q2, 'A', $this->sz, $this->tage(5));
        $this->db->prepare("INSERT INTO email_benachrichtigungen (klausur_id, empfaenger_id, typ, token, gesendet_am) VALUES (?, ?, 'erstmeldung', ?, NOW() - INTERVAL 3 HOUR)")
            ->execute([$klausur, $this->sz, bin2hex(random_bytes(32))]);

        $this->cron();

        $this->assertSame(0, $this->fx->zaehle("SELECT COUNT(*) FROM email_benachrichtigungen WHERE typ = 'erinnerung'"));
    }

    public function testKeineWeitereMailWennAnwesenheitErfasstIst(): void
    {
        // Die Erinnerung wurde nie per Link beantwortet (beantwortet_am bleibt NULL) – trotzdem hat
        // die Lehrkraft die Anwesenheit direkt im Tool erfasst. Das allein muss reichen, um jede
        // weitere Mail zu unterbinden.
        $klausur = $this->klausur($this->q2, 'A', $this->sz, $this->tage(5));
        $this->db->prepare("INSERT INTO email_benachrichtigungen (klausur_id, empfaenger_id, typ, token, gesendet_am) VALUES (?, ?, 'erstmeldung', ?, NOW() - INTERVAL 3 DAY)")
            ->execute([$klausur, $this->sz, bin2hex(random_bytes(32))]);
        $ks = (int) $this->fx->wert('SELECT id FROM kurs_schueler WHERE kurs_id = (SELECT kurs_id FROM klausuren WHERE id = ?)', [$klausur]);
        $this->fx->anwesenheit($klausur, $ks, 'anwesend');

        $ausgabe = $this->cron();

        $this->assertSame([], $this->senke->mails());
        $this->assertStringContainsString('0 gesendet', $ausgabe);
    }

    public function testKlausurenOhneTerminOhneLehrkraftOderInDerZukunftBekommenKeineMail(): void
    {
        $this->klausur($this->q2, 'A', $this->sz, gmdate('Y-m-d', strtotime('+3 days')));   // Zukunft
        $this->klausur($this->q2, 'B', null, $this->tage(3));                             // keine Lehrkraft
        $this->klausur($this->q2, 'C', $this->sz, $this->tage(3), null);                  // ohne Uhrzeit
        $ohneMail = $this->fx->benutzer('Otto', 'Ohne (OO)', ['lehrkraft'], 'OO', null);
        $this->klausur($this->q2, 'D', $ohneMail, $this->tage(3));                        // Lehrkraft ohne E-Mail

        $ausgabe = $this->cron();

        $this->assertSame([], $this->senke->mails());
        $this->assertStringContainsString('0 gesendet', $ausgabe);
    }

    public function testFehlgeschlagenerVersandWirdGemeldetUndZaehltAlsFehler(): void
    {
        $this->klausur($this->q2, 'A', $this->sz, $this->tage(2));

        $ausgabe = $this->cron(SmtpSenke::toteUmgebung());

        $this->assertStringContainsString('ERR (erstmeldung)', $ausgabe);
        $this->assertStringContainsString('1 Fehler', $ausgabe);
    }

    // ------------------------------------------------------------------ Moodle-Sync (Teil des Cronjobs)

    public function testMoodleSyncLaeuftAlsTeilDesCronjobsAberHoechstensEinmalTaeglich(): void
    {
        $moodle = new MoodleAttrappe();
        $_ENV['MOODLE_URL']       = $moodle->url;
        $_ENV['MOODLE_API_TOKEN'] = 'ok';

        $ausgabe = $this->cron();

        $this->assertStringContainsString('OK  (Moodle-Sync): 2 neu', $ausgabe);
        $this->assertTrue(MoodleApi::wurdeHeuteSynchronisiert());
        $this->assertSame(2, $this->fx->zaehle('SELECT COUNT(*) FROM benutzer WHERE moodle_id IN (?, ?)', ['1', '2']));

        // Noch am selben Tag: kein zweiter Sync
        $ausgabe = $this->cron();

        $this->assertStringNotContainsString('Moodle-Sync', $ausgabe);
    }

    public function testMoodleSyncWirdErneutVersuchtWennDerLetzteVersuchFehlgeschlagenIst(): void
    {
        // Ohne MOODLE_URL/MOODLE_API_TOKEN schlägt die Konfiguration fehl – der Sync gilt dann nicht als erledigt
        $ausgabe = $this->cron();

        $this->assertStringContainsString('ERR (Moodle-Sync): MOODLE_URL oder MOODLE_API_TOKEN nicht konfiguriert.', $ausgabe);
        $this->assertFalse(MoodleApi::wurdeHeuteSynchronisiert());
    }

    public function testMoodleSyncBeeintraechtigtDenVersandDerAnwesenheitsMailsNicht(): void
    {
        $this->klausur($this->q2, 'A', $this->sz, $this->tage(2)); // ohne Moodle-Konfiguration → Sync schlägt fehl

        $ausgabe = $this->cron();

        $this->assertStringContainsString('ERR (Moodle-Sync)', $ausgabe);
        $this->assertStringContainsString('OK  (erstmeldung)', $ausgabe);
        $this->assertCount(1, $this->senke->mails());
    }
}
