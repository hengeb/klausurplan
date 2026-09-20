<?php

declare(strict_types=1);

namespace Klausurplan\Tests\Integration;

use Klausurplan\Api\AdminApi;
use Klausurplan\Auth\LtiHandler;
use Klausurplan\Auth\Session;
use Klausurplan\Tests\Support\IntegrationTestCase;
use Klausurplan\Tests\Support\MoodleApiMitNutzern;
use Klausurplan\Tests\Support\MoodleAttrappe;
use ReflectionMethod;

/** Benutzer-/Rollenverwaltung, Fächer, Moodle-Sync und LTI-Anmeldung mit echten Tabellen. */
final class AdminUndAnmeldungTest extends IntegrationTestCase
{
    private array $altEnv;

    protected function setUp(): void
    {
        $this->altEnv = $_ENV;
        parent::setUp();
        $_ENV['MOODLE_URL'] = 'https://moodle.example';
        $_ENV['MOODLE_API_TOKEN'] = 'x';
    }

    protected function tearDown(): void
    {
        $_ENV = $this->altEnv;
        parent::tearDown();
    }

    // ------------------------------------------------------------------ Rollen

    public function testBenutzerlisteZeigtNurPersonenMitBetriebsrollen(): void
    {
        $a = $this->fx->benutzer('Ada', 'Admin', ['admin', 'lehrkraft']);
        $this->fx->benutzer('Sven', 'Schüler', ['schueler']);
        $this->fx->benutzer('Nora', 'Norolle');

        $liste = AdminApi::getBenutzer();

        $this->assertSame([$a], array_map(fn ($b) => (int) $b['id'], $liste));
        $this->assertSame(['admin', 'lehrkraft'], $liste[0]['rollen']);
    }

    public function testRollenSetzenUndStufenleitungEntziehen(): void
    {
        $p = $this->fx->benutzer('Sarah', 'Leitung', ['stufenleitung']);
        $stufe = $this->fx->stufe('Q2');
        $this->fx->stufenleitung($p, $stufe);

        AdminApi::setRollen($p, ['lehrkraft', 'stufenleitung', 'unbekannt']);
        $rollen = array_column($this->fx->zeilen('SELECT rolle FROM rollen WHERE benutzer_id = ?', [$p]), 'rolle');
        sort($rollen);
        $this->assertSame(['lehrkraft', 'stufenleitung'], $rollen);
        $this->assertSame(1, $this->fx->zaehle('SELECT COUNT(*) FROM stufenleitungen WHERE benutzer_id = ?', [$p]));

        AdminApi::setRollen($p, ['lehrkraft']);
        $this->assertSame(0, $this->fx->zaehle('SELECT COUNT(*) FROM stufenleitungen WHERE benutzer_id = ?', [$p]));

        AdminApi::setStufenleitungen($p, [$stufe]);
        $this->assertSame([$stufe], array_map('intval', AdminApi::getStufenleitungen($p)));
        $this->assertSame('Q2', AdminApi::getStufen()[0]['name']);
    }

    public function testFaecherVerwalten(): void
    {
        AdminApi::updateFach('zz', 'Testfach');
        $this->assertSame('Testfach', $this->fx->wert("SELECT bezeichnung FROM fach_bezeichnungen WHERE kuerzel = 'ZZ'"));

        AdminApi::updateFach('ZZ', 'Umbenannt');
        $this->assertSame('Umbenannt', $this->fx->wert("SELECT bezeichnung FROM fach_bezeichnungen WHERE kuerzel = 'ZZ'"));
        $this->assertContains('ZZ', array_column(AdminApi::getFaecher(), 'kuerzel'));

        AdminApi::deleteFach('zz');
        $this->assertSame(0, $this->fx->zaehle("SELECT COUNT(*) FROM fach_bezeichnungen WHERE kuerzel = 'ZZ'"));
    }

    // ------------------------------------------------------------------ Moodle-Sync

    private function moodleLehrkraft(int $id, string $nachname, string $email = 'l@x.de'): array
    {
        return ['id' => $id, 'firstname' => 'Vor', 'lastname' => $nachname, 'email' => $email,
                'customfields' => [['shortname' => 'klasse', 'value' => 'Lehrkraft']]];
    }

    private function moodleSchueler(int $id, string $nachname, string $klasse): array
    {
        return ['id' => $id, 'firstname' => 'Sue', 'lastname' => $nachname, 'email' => 'geheim@x.de',
                'customfields' => [['shortname' => 'klasse', 'value' => $klasse]]];
    }

    public function testMoodleSyncLegtAnAktualisiertUndLoescht(): void
    {
        $sync = new MoodleApiMitNutzern([$this->moodleLehrkraft(1, 'Gebauer (SZ)'), $this->moodleSchueler(2, 'Schmidt', 'Q1 Kurs 3')]);

        $ergebnis = $sync->sync();

        $this->assertSame(['neu' => 2, 'aktualisiert' => 0, 'geloescht' => 0, 'gesamt' => 2], $ergebnis);
        $lehrer = $this->fx->zeilen("SELECT * FROM benutzer WHERE moodle_id = '1'")[0];
        $this->assertSame(['SZ', 'l@x.de', null], [$lehrer['kuerzel'], $lehrer['email'], $lehrer['stufe']]);
        $schueler = $this->fx->zeilen("SELECT * FROM benutzer WHERE moodle_id = '2'")[0];
        $this->assertSame([null, null, 'Q1'], [$schueler['kuerzel'], $schueler['email'], $schueler['stufe']], 'Schüler-E-Mails werden nicht übernommen');
        $this->assertSame(['lehrkraft'], array_column($this->fx->zeilen('SELECT rolle FROM rollen WHERE benutzer_id = ?', [$lehrer['id']]), 'rolle'));
        $this->assertSame(['schueler'], array_column($this->fx->zeilen('SELECT rolle FROM rollen WHERE benutzer_id = ?', [$schueler['id']]), 'rolle'));

        // zweiter Lauf: Namensänderung, Schüler*in nicht mehr in Moodle
        $ergebnis = (new MoodleApiMitNutzern([$this->moodleLehrkraft(1, 'Gebauer-Neu (SZ)')]))->sync();
        $this->assertSame(1, $ergebnis['aktualisiert']);
        $this->assertSame(1, $ergebnis['geloescht']);
        $this->assertSame('Gebauer-Neu (SZ)', $this->fx->wert("SELECT nachname FROM benutzer WHERE moodle_id = '1'"));
    }

    public function testMoodleSyncBehaeltReferenzierteNutzer(): void
    {
        (new MoodleApiMitNutzern([$this->moodleSchueler(2, 'Schmidt', 'Q1')]))->sync();
        $id = (int) $this->fx->wert("SELECT id FROM benutzer WHERE moodle_id = '2'");
        $kurs = $this->fx->kurs($this->fx->halbjahr($this->fx->stufe('Q1')), 'X_Q1');
        $this->fx->kursSchueler($kurs, 'Schmidt|Sue', $id);

        $ergebnis = (new MoodleApiMitNutzern([$this->moodleLehrkraft(9, 'Anders (AN)')]))->sync();

        $this->assertSame(0, $ergebnis['geloescht'], 'einem Kurs zugeordnete Personen bleiben');
        $this->assertSame(1, $this->fx->zaehle('SELECT COUNT(*) FROM benutzer WHERE id = ?', [$id]));
    }

    public function testMoodleSyncKorrigiertFaelschlichAlsSchuelerAngelegteLehrkraefte(): void
    {
        $p = $this->fx->benutzer('Vor', 'Gebauer (SZ)', ['schueler']);
        $this->db->prepare("UPDATE benutzer SET moodle_id = '1' WHERE id = ?")->execute([$p]);

        (new MoodleApiMitNutzern([$this->moodleLehrkraft(1, 'Gebauer (SZ)')]))->sync();

        $this->assertSame(['lehrkraft'], array_column($this->fx->zeilen('SELECT rolle FROM rollen WHERE benutzer_id = ?', [$p]), 'rolle'));
    }

    public function testMoodleSyncBereinigtDoppelteKuerzel(): void
    {
        (new MoodleApiMitNutzern([$this->moodleLehrkraft(1, 'Alt (SZ)'), $this->moodleLehrkraft(7, 'Neu (SZ)')]))->sync();

        $kuerzel = array_column($this->fx->zeilen('SELECT moodle_id, kuerzel FROM benutzer ORDER BY CAST(moodle_id AS UNSIGNED)'), 'kuerzel', 'moodle_id');
        $this->assertSame([1 => null, 7 => 'SZ'], array_map(fn ($v) => $v, [1 => $kuerzel['1'], 7 => $kuerzel['7']]), 'die größte Moodle-ID behält das Kürzel');
    }

    public function testMoodleSyncUeberHttpMitAdminApi(): void
    {
        $moodle = new MoodleAttrappe();
        $_ENV['MOODLE_URL'] = $moodle->url . '/';
        $_ENV['MOODLE_API_TOKEN'] = 'ok';
        $this->alsBenutzer(1, ['admin']);

        $ergebnis = AdminApi::moodleSync();

        $this->assertSame(['neu' => 2, 'aktualisiert' => 0, 'geloescht' => 0, 'gesamt' => 2], $ergebnis, 'ldap + manual, nach ID dedupliziert');
        $this->assertSame('SZ', $this->fx->wert("SELECT kuerzel FROM benutzer WHERE moodle_id = '1'"));
    }

    public function testMoodleFehlerWerdenGemeldet(): void
    {
        $moodle = new MoodleAttrappe();
        $_ENV['MOODLE_URL'] = $moodle->url;

        $_ENV['MOODLE_API_TOKEN'] = 'http500';
        $this->erwarteFehler(fn () => AdminApi::moodleSync(), 'HTTP-Fehler: 500');

        $_ENV['MOODLE_API_TOKEN'] = 'exception';
        $this->erwarteFehler(fn () => AdminApi::moodleSync(), 'Moodle API: Zugriff verweigert');

        $_ENV['MOODLE_API_TOKEN'] = 'kaputt';
        $this->assertInstanceOf(\JsonException::class, $this->fange(fn () => AdminApi::moodleSync()));
        $this->assertSame(0, $this->fx->zaehle('SELECT COUNT(*) FROM benutzer'), 'bei Fehlern wird nichts verändert');
    }

    // ------------------------------------------------------------------ LTI-Anmeldung

    private function handler(): LtiHandler
    {
        $tool = (new \ReflectionClass(LtiHandler::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(LtiHandler::class, 'db'))->setValue($tool, $this->db);
        return $tool;
    }

    public function testLtiBenutzerSynchronisierung(): void
    {
        $tool = $this->handler();
        $sync = new ReflectionMethod(LtiHandler::class, 'syncBenutzer');

        $neu = $sync->invoke($tool, 'm-1', 'Anna', 'Gebauer (SZ)', 'a@x.de', 'SZ');
        $this->assertTrue($neu['ist_neu']);

        $wieder = $sync->invoke($tool, 'm-1', 'Anna', 'Gebauer-Neu (SZ)', null, 'SZ');
        $this->assertFalse($wieder['ist_neu']);
        $this->assertSame($neu['id'], $wieder['id']);
        $zeile = $this->fx->zeilen('SELECT * FROM benutzer WHERE id = ?', [$neu['id']])[0];
        $this->assertSame(['Gebauer-Neu (SZ)', 'a@x.de'], [$zeile['nachname'], $zeile['email']], 'Namen aktualisiert, E-Mail nicht durch null überschrieben');

        $rolle = new ReflectionMethod(LtiHandler::class, 'weiseRolleZu');
        $rolle->invoke($tool, $neu['id'], 'admin');
        $rolle->invoke($tool, $neu['id'], 'admin'); // doppelt ist unschädlich (INSERT IGNORE)
        $laden = new ReflectionMethod(LtiHandler::class, 'ladeRollen');
        $this->assertSame(['admin'], $laden->invoke($tool, $neu['id']));
    }

    public function testLtiHandlerLaedtSchluesselUndSetztDieJwksUrl(): void
    {
        $schluessel = (string) tempnam(sys_get_temp_dir(), 'key');
        file_put_contents($schluessel, \ceLTIc\LTI\Jwt\FirebaseClient::generateKey('RS256'));
        $_ENV['LTI_PRIVATE_KEY_FILE'] = $schluessel;
        $_ENV['APP_URL'] = 'https://klausurplan.example/';

        try {
            $tool = new LtiHandler();
        } finally {
            unlink($schluessel);
        }

        $this->assertStringContainsString('BEGIN', (string) $tool->rsaKey);
        $this->assertSame('https://klausurplan.example/lti-jwks.php', $tool->jku);
    }

    public function testLtiHandlerOhneSchluesselDateiTutNichtsGefaehrliches(): void
    {
        unset($_ENV['LTI_PRIVATE_KEY_FILE']);
        $_ENV['APP_URL'] = 'https://klausurplan.example';

        $tool = new LtiHandler();

        $this->assertSame('https://klausurplan.example/lti-jwks.php', $tool->jku);
    }

    public function testSessionZuletztGesehen(): void
    {
        $p = $this->fx->benutzer('Anna', 'L', ['lehrkraft']);
        $this->alsBenutzer($p, ['lehrkraft']);
        $this->assertNull($this->fx->wert('SELECT zuletzt_gesehen FROM benutzer WHERE id = ?', [$p]));

        Session::updateZuletztGesehen();

        $this->assertNotNull($this->fx->wert('SELECT zuletzt_gesehen FROM benutzer WHERE id = ?', [$p]));
    }
}
