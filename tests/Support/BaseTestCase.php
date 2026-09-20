<?php

declare(strict_types=1);

namespace Klausurplan\Tests\Support;

use Closure;
use Klausurplan\Models\Database;
use Klausurplan\Support\Prozess;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;
use RuntimeException;

/**
 * Gemeinsame Basis für Unit- und Integrationstests: setzt Globals (Session, GET, POST,
 * FILES, Umgebung) zurück und ersetzt `exit` durch eine Exception.
 */
abstract class BaseTestCase extends PhpUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION = $_GET = $_POST = $_FILES = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = '/';

        Prozess::$beendenHandler = function (): never {
            throw new AnfrageBeendet('Anfrage beendet');
        };
    }

    protected function tearDown(): void
    {
        Prozess::$beendenHandler = null;
        Database::setInstance(null);
        $_SESSION = $_GET = $_POST = $_FILES = [];
        http_response_code(200);
        parent::tearDown();
    }

    /** Meldet eine Person mit den Rollen an (ohne echte PHP-Session). */
    protected function alsBenutzer(int $id, array $rollen, string $vorname = 'Test', string $nachname = 'Person'): void
    {
        $_SESSION = [
            'benutzer_id' => $id,
            'moodle_id'   => (string) $id,
            'vorname'     => $vorname,
            'nachname'    => $nachname,
            'rollen'      => $rollen,
        ];
    }

    /** Führt $fn aus und liefert die geworfene Exception (oder null). */
    protected function fange(Closure $fn): ?\Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            return $e;
        }
        return null;
    }

    /** Erwartet, dass $fn eine RuntimeException mit passender Meldung wirft; liefert den HTTP-Status. */
    protected function erwarteFehler(Closure $fn, string $meldungTeil, ?int $status = null): void
    {
        http_response_code(200);
        $e = $this->fange($fn);
        $this->assertInstanceOf(RuntimeException::class, $e, 'Es wurde keine RuntimeException geworfen');
        $this->assertStringContainsString($meldungTeil, $e->getMessage());
        if ($status !== null) {
            $this->assertSame($status, http_response_code());
        }
    }

    /** Erwartet, dass $fn wegen fehlender Rechte/Anmeldung beendet wird. */
    protected function erwarteZugriffVerweigert(Closure $fn, int $status = 403): void
    {
        http_response_code(200);
        ob_start();
        try {
            $e = $this->fange($fn);
        } finally {
            $json = (string) ob_get_clean();
        }
        $this->assertInstanceOf(AnfrageBeendet::class, $e, 'Zugriff wurde nicht verweigert');
        $this->assertSame($status, http_response_code());
        $this->assertArrayHasKey('fehler', json_decode($json, true) ?? []);
    }

    /** Führt $fn aus und liefert die Ausgabe. */
    protected function ausgabe(Closure $fn): string
    {
        http_response_code(200);
        ob_start();
        try {
            $fn();
        } catch (AnfrageBeendet) {
            // erwartet bei Seiten, die mit exit enden
        } finally {
            $text = (string) ob_get_clean();
        }
        return $text;
    }
}
