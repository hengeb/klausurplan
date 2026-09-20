<?php

declare(strict_types=1);

namespace Klausurplan\Tests\Integration;

use Klausurplan\Models\Database;
use Klausurplan\Tests\Support\IntegrationTestCase;
use PDO;

final class DatenbankVerbindungTest extends IntegrationTestCase
{
    private array $altEnv;

    protected function setUp(): void
    {
        $this->altEnv = $_ENV;
        parent::setUp();
    }

    protected function tearDown(): void
    {
        $_ENV = $this->altEnv;
        parent::tearDown();
    }

    public function testVerbindetSichMitDenKonfiguriertenWerten(): void
    {
        foreach (self::dbUmgebung() as $k => $v) {
            $_ENV[$k] = $v;
        }
        Database::setInstance(null);

        $pdo = Database::getInstance();

        $this->assertSame(PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(PDO::ATTR_ERRMODE));
        $this->assertSame(PDO::FETCH_ASSOC, $pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE));
        $this->assertSame('1', (string) $pdo->query('SELECT 1')->fetchColumn());
        $this->assertSame($pdo, Database::getInstance(), 'Singleton');
    }

    public function testFalschesPasswortWirdAlsRuntimeExceptionGemeldet(): void
    {
        foreach (self::dbUmgebung() as $k => $v) {
            $_ENV[$k] = $v;
        }
        $_ENV['DB_PASS'] = 'falsch';
        Database::setInstance(null);

        $this->erwarteFehler(fn () => Database::getInstance(), 'Datenbankverbindung fehlgeschlagen');
    }
}
