<?php

declare(strict_types=1);

namespace Klausurplan\Tests\Unit\Auth;

use Klausurplan\Auth\Session;
use Klausurplan\Tests\Support\TestCase;
use ReflectionProperty;

final class SessionTest extends TestCase
{
    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        (new ReflectionProperty(Session::class, 'started'))->setValue(null, false);
        parent::tearDown();
    }

    public function testStartStartetDiePhpSessionNurEinmal(): void
    {
        ini_set('session.save_path', sys_get_temp_dir());

        Session::start();
        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
        $this->assertSame('klausurplan_session', session_name());

        $id = session_id();
        Session::start(); // zweiter Aufruf ändert nichts
        $this->assertSame($id, session_id());
    }

    public function testSetBenutzerSchreibtDieSitzungsdatenUndErneuertDieSessionId(): void
    {
        ini_set('session.save_path', sys_get_temp_dir());
        Session::start();
        $alteId = session_id();

        Session::setBenutzer(7, 'moodle-7', 'Anna', 'Lehrer (SZ)', ['lehrkraft']);

        $this->assertNotSame($alteId, session_id(), 'session_regenerate_id nach dem Login');
        $this->assertSame(7, $_SESSION['benutzer_id']);
        $this->assertSame(['lehrkraft'], $_SESSION['rollen']);
        $this->assertTrue(Session::isAuthenticated());
    }

    public function testNichtAngemeldet(): void
    {
        $this->assertFalse(Session::isAuthenticated());
        $this->assertNull(Session::getBenutzer());
        $this->assertNull(Session::getBenutzerId());
        $this->assertFalse(Session::hasRolle('admin'));
    }

    public function testAngemeldeterBenutzer(): void
    {
        $this->alsBenutzer(3, ['lehrkraft', 'stufenleitung'], 'Anna', 'Lehrer');

        $this->assertTrue(Session::isAuthenticated());
        $this->assertSame(3, Session::getBenutzerId());
        $this->assertSame([
            'id' => 3, 'moodle_id' => '3', 'vorname' => 'Anna', 'nachname' => 'Lehrer',
            'rollen' => ['lehrkraft', 'stufenleitung'],
        ], Session::getBenutzer());
        $this->assertTrue(Session::hasRolle('stufenleitung'));
        $this->assertFalse(Session::hasRolle('admin'));
    }

    public function testRequireAuthVerweigertOhneAnmeldung(): void
    {
        $this->erwarteZugriffVerweigert(fn () => Session::requireAuth(), 401);
    }

    public function testRequireAuthLaesstAngemeldeteDurch(): void
    {
        $this->alsBenutzer(1, []);

        Session::requireAuth();

        $this->addToAssertionCount(1);
    }

    public function testRequireRolleVerweigertOhneAnmeldungMit401(): void
    {
        $this->erwarteZugriffVerweigert(fn () => Session::requireRolle('admin'), 401);
    }

    public function testRequireRolleVerweigertFalscheRolleMit403(): void
    {
        $this->alsBenutzer(1, ['schueler']);

        $this->erwarteZugriffVerweigert(fn () => Session::requireRolle('admin', 'stufenleitung'), 403);
    }

    public function testRequireRolleErlaubtEineDerRollen(): void
    {
        $this->alsBenutzer(1, ['stufenleitung']);

        Session::requireRolle('admin', 'stufenleitung');

        $this->addToAssertionCount(1);
    }

    public function testUpdateZuletztGesehen(): void
    {
        $this->alsBenutzer(5, ['lehrkraft']);

        Session::updateZuletztGesehen();

        $this->assertSame([5], $this->db->aufrufe('/UPDATE benutzer SET zuletzt_gesehen/')[0]['params']);
    }

    public function testUpdateZuletztGesehenOhneAnmeldungTutNichts(): void
    {
        Session::updateZuletztGesehen();

        $this->assertSame([], $this->db->log);
    }
}
