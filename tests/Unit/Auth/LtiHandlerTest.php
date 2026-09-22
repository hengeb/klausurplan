<?php

declare(strict_types=1);

namespace Klausurplan\Tests\Unit\Auth;

use ceLTIc\LTI\UserResult;
use Klausurplan\Auth\LtiHandler;
use Klausurplan\Auth\Session;
use Klausurplan\Tests\Support\AnfrageBeendet;
use Klausurplan\Tests\Support\FakeResult;
use Klausurplan\Tests\Support\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/** Testet die Nutzer- und Rollenlogik des LTI-Launch ohne echte LTI-Plattform. */
final class LtiHandlerTest extends TestCase
{
    private LtiHandler $tool;
    private array $altEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->altEnv = $_ENV;
        $_ENV['APP_URL'] = 'https://schule.example/klausurplan';

        // Der Konstruktor braucht Datenbank und LTI-Schlüssel – hier nicht nötig.
        $this->tool = (new \ReflectionClass(LtiHandler::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(LtiHandler::class, 'db'))->setValue($this->tool, $this->db);

        ini_set('session.save_path', sys_get_temp_dir());
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        (new ReflectionProperty(Session::class, 'started'))->setValue(null, false);
        $_ENV = $this->altEnv;
        parent::tearDown();
    }

    private function nutzer(string $id = 'moodle-1', string $vor = 'Anna', string $nach = 'Gebauer (SZ)', ?string $email = 'a@x.de', array $rollen = []): UserResult
    {
        $u = new UserResult();
        $u->ltiUserId = $id;
        $u->firstname = $vor;
        $u->lastname  = $nach;
        $u->email     = $email;
        $u->roles     = $rollen;
        return $u;
    }

    private function privat(string $name, mixed ...$args): mixed
    {
        return (new ReflectionMethod(LtiHandler::class, $name))->invoke($this->tool, ...$args);
    }

    private function launch(): ?\Throwable
    {
        ob_start();
        try {
            return $this->fange(fn () => $this->privat('onLaunch'));
        } finally {
            ob_end_clean();
        }
    }

    // ------------------------------------------------------------------ Launch

    public function testLaunchOhneNutzerWirdAbgelehnt(): void
    {
        $this->tool->userResult = null;

        $this->privat('onLaunch');

        $this->assertFalse($this->tool->ok);
        $this->assertStringContainsString('Kein Nutzer', $this->tool->reason);
    }

    public function testLaunchOhneNutzerIdWirdAbgelehnt(): void
    {
        $this->tool->userResult = $this->nutzer(id: '');

        $this->privat('onLaunch');

        $this->assertFalse($this->tool->ok);
        $this->assertStringContainsString('ohne Nutzer-ID', $this->tool->reason);
    }

    public function testErsterLoginLegtNutzerAnUndVergibtSchuelerRolle(): void
    {
        $this->db->on('/^INSERT INTO benutzer/', FakeResult::insert(77));
        $this->tool->userResult = $this->nutzer();

        $e = $this->launch();

        $this->assertInstanceOf(AnfrageBeendet::class, $e, 'Weiterleitung beendet die Anfrage');
        $insert = $this->db->aufrufe('/^INSERT INTO benutzer/')[0];
        $this->assertSame(['moodle-1', 'Anna', 'Gebauer (SZ)', 'a@x.de', 'SZ'], $insert['params']);
        $this->assertSame([[77, 'schueler']], array_column($this->db->aufrufe('/INSERT IGNORE INTO rollen/'), 'params'));
        $this->assertSame(77, $_SESSION['benutzer_id']);
        $this->assertSame(['schueler'], $_SESSION['rollen']);
        $this->assertTrue($this->db->wurdeAusgefuehrt('/UPDATE benutzer SET zuletzt_gesehen/'));
    }

    public function testBestehendeNutzerBehaltenIhreRollenUndErhaltenKeineNeueBasisrolle(): void
    {
        $this->db->onRows('/FROM benutzer WHERE moodle_id/', [
            ['id' => 5, 'vorname' => 'Anna', 'nachname' => 'Gebauer (SZ)', 'email' => 'a@x.de', 'kuerzel' => 'SZ'],
        ]);
        $this->db->onRows('/SELECT rolle FROM rollen/', [['rolle' => 'lehrkraft'], ['rolle' => 'stufenleitung']]);
        $this->tool->userResult = $this->nutzer();

        $this->launch();

        $this->assertSame(['lehrkraft', 'stufenleitung'], $_SESSION['rollen']);
        $this->assertFalse($this->db->wurdeAusgefuehrt('/INSERT IGNORE INTO rollen/'));
        $this->assertFalse($this->db->wurdeAusgefuehrt('/^UPDATE benutzer SET (?!zuletzt)/'), 'nichts geändert → kein Update');
    }

    public function testMoodleAdminsBekommenDieAdminRolle(): void
    {
        $this->db->onRows('/FROM benutzer WHERE moodle_id/', [
            ['id' => 5, 'vorname' => 'Anna', 'nachname' => 'Gebauer (SZ)', 'email' => 'a@x.de', 'kuerzel' => 'SZ'],
        ]);
        $this->db->onRows('/SELECT rolle FROM rollen/', [['rolle' => 'lehrkraft']]);
        $this->tool->userResult = $this->nutzer(rollen: ['urn:lti:instrole:ims/lis/Administrator']);

        $this->launch();

        $this->assertSame([[5, 'admin']], array_column($this->db->aufrufe('/INSERT IGNORE INTO rollen/'), 'params'));
        $this->assertSame(['lehrkraft', 'admin'], $_SESSION['rollen']);
    }

    public function testVorhandeneAdminRolleWirdNichtDoppeltVergeben(): void
    {
        $this->db->onRows('/FROM benutzer WHERE moodle_id/', [
            ['id' => 5, 'vorname' => 'Anna', 'nachname' => 'Gebauer (SZ)', 'email' => 'a@x.de', 'kuerzel' => 'SZ'],
        ]);
        $this->db->onRows('/SELECT rolle FROM rollen/', [['rolle' => 'admin']]);
        $this->tool->userResult = $this->nutzer(rollen: ['Administrator']);

        $this->launch();

        $this->assertFalse($this->db->wurdeAusgefuehrt('/INSERT IGNORE INTO rollen/'));
    }

    public function testMoodleManagerBekommenEbenfallsDieAdminRolle(): void
    {
        $this->db->onRows('/FROM benutzer WHERE moodle_id/', [
            ['id' => 5, 'vorname' => 'Anna', 'nachname' => 'Gebauer (SZ)', 'email' => 'a@x.de', 'kuerzel' => 'SZ'],
        ]);
        $this->db->onRows('/SELECT rolle FROM rollen/', [['rolle' => 'lehrkraft']]);
        $this->tool->userResult = $this->nutzer(rollen: ['http://purl.imsglobal.org/vocab/lis/v2/membership#Manager']);

        $this->launch();

        $this->assertSame([[5, 'admin']], array_column($this->db->aufrufe('/INSERT IGNORE INTO rollen/'), 'params'));
        $this->assertSame(['lehrkraft', 'admin'], $_SESSION['rollen']);
    }

    public function testAdminRolleWirdBeiJedemLoginErneutSynchronisiertAuchOhneNeuenNutzer(): void
    {
        // Bestehender Nutzer, der beim vorigen Login noch nicht admin/manager war – jetzt schon
        $this->db->onRows('/FROM benutzer WHERE moodle_id/', [
            ['id' => 5, 'vorname' => 'Anna', 'nachname' => 'Gebauer (SZ)', 'email' => 'a@x.de', 'kuerzel' => 'SZ'],
        ]);
        $this->db->onRows('/SELECT rolle FROM rollen/', [['rolle' => 'lehrkraft']]);
        $this->tool->userResult = $this->nutzer(rollen: ['Manager']);

        $this->launch();

        $this->assertContains('admin', $_SESSION['rollen']);
    }

    public function testWederAdminNochManagerBleibtOhneAdminRolle(): void
    {
        $this->db->onRows('/FROM benutzer WHERE moodle_id/', [
            ['id' => 5, 'vorname' => 'Anna', 'nachname' => 'Gebauer (SZ)', 'email' => 'a@x.de', 'kuerzel' => 'SZ'],
        ]);
        $this->db->onRows('/SELECT rolle FROM rollen/', [['rolle' => 'lehrkraft']]);
        $this->tool->userResult = $this->nutzer(rollen: ['Learner']);

        $this->launch();

        $this->assertFalse($this->db->wurdeAusgefuehrt('/INSERT IGNORE INTO rollen/'));
        $this->assertSame(['lehrkraft'], $_SESSION['rollen']);
    }

    // ------------------------------------------------------------------ Nutzer synchronisieren

    public function testSyncBenutzerAktualisiertNurGeaenderteFelder(): void
    {
        $this->db->onRows('/FROM benutzer WHERE moodle_id/', [
            ['id' => 5, 'vorname' => 'Anna', 'nachname' => 'Alt', 'email' => 'alt@x.de', 'kuerzel' => 'AA'],
        ]);

        $r = $this->privat('syncBenutzer', 'm1', 'Anna', 'Neu (SZ)', 'neu@x.de', 'SZ');

        $this->assertSame(['id' => 5, 'vorname' => 'Anna', 'nachname' => 'Neu (SZ)', 'ist_neu' => false], $r);
        $update = $this->db->aufrufe('/^UPDATE benutzer SET/')[0];
        $this->assertSame('UPDATE benutzer SET nachname = ?, email = ?, kuerzel = ? WHERE id = ?', $update['sql']);
        $this->assertSame(['Neu (SZ)', 'neu@x.de', 'SZ', 5], $update['params']);
    }

    public function testSyncBenutzerUeberschreibtEmailUndKuerzelNichtMitNull(): void
    {
        $this->db->onRows('/FROM benutzer WHERE moodle_id/', [
            ['id' => 5, 'vorname' => 'Anna', 'nachname' => 'X', 'email' => 'a@x.de', 'kuerzel' => 'SZ'],
        ]);

        $this->privat('syncBenutzer', 'm1', 'Anna', 'X', null, null);

        $this->assertFalse($this->db->wurdeAusgefuehrt('/^UPDATE benutzer/'));
    }

    public function testSyncBenutzerAendertName(): void
    {
        $this->db->onRows('/FROM benutzer WHERE moodle_id/', [
            ['id' => 5, 'vorname' => 'Anne', 'nachname' => 'X', 'email' => 'a@x.de', 'kuerzel' => null],
        ]);

        $this->privat('syncBenutzer', 'm1', 'Anna', 'X', 'a@x.de', null);

        $this->assertSame(['Anna', 5], $this->db->aufrufe('/^UPDATE benutzer SET vorname/')[0]['params']);
    }

    // ------------------------------------------------------------------ OIDC-State

    public function testOidcStateWirdInDerSessionGespeichertUndEinmalPruefbar(): void
    {
        $auth = ['state' => 'S1', 'nonce' => 'N1'];
        (new ReflectionMethod(LtiHandler::class, 'onInitiateLogin'))->invokeArgs($this->tool, [[], &$auth]); // $auth wird per Referenz übergeben
        $this->assertSame($auth, $_SESSION['ceLTIc_lti_authentication_request']);

        $this->privat('onAuthenticate', 'S1', 'N1', false);

        $this->assertArrayNotHasKey('ceLTIc_lti_authentication_request', $_SESSION, 'State ist nur einmal gültig');
        $this->assertTrue($this->tool->ok ?? true);
    }

    public function testOidcStateMitPlattformSpeicherSuffix(): void
    {
        session_start(); // onAuthenticate liest die echte PHP-Session
        $_SESSION['ceLTIc_lti_authentication_request'] = ['state' => 'S1', 'nonce' => 'N1'];

        $this->privat('onAuthenticate', 'S1.platformStorage', 'N1', true);

        $this->assertNull($this->tool->reason ?? null);
    }

    public function testOidcStateMitFalschenWertenWirdAbgelehnt(): void
    {
        session_start();
        $_SESSION['ceLTIc_lti_authentication_request'] = ['state' => 'S1', 'nonce' => 'N1'];

        $this->privat('onAuthenticate', 'ANDERS', 'N1', false);

        $this->assertStringContainsString('Ungültige', (string) $this->tool->reason);
    }

    public function testOidcOhneSessionDatenWirdAbgelehnt(): void
    {
        $this->privat('onAuthenticate', 'S1', 'N1', false);

        $this->assertStringContainsString('Keine LTI-OIDC-Sitzungsdaten', (string) $this->tool->reason);
    }

    public function testOnErrorMarkiertLaunchAlsFehlgeschlagen(): void
    {
        $this->privat('onError');

        $this->assertFalse($this->tool->ok);
    }

    // ------------------------------------------------------------------ Schlüsselpfad

    public function testResolveKeyPath(): void
    {
        $this->assertNull(LtiHandler::resolveKeyPath(null));
        $this->assertNull(LtiHandler::resolveKeyPath(''));
        $this->assertNull(LtiHandler::resolveKeyPath('gibt-es-nicht.key'));

        $absolut = (string) tempnam(sys_get_temp_dir(), 'key');
        $this->assertSame($absolut, LtiHandler::resolveKeyPath($absolut));
        unlink($absolut);

        // relative Pfade werden ab dem Projektverzeichnis aufgelöst
        $this->assertSame(dirname(__DIR__, 3) . '/composer.json', LtiHandler::resolveKeyPath('composer.json'));
    }
}
