<?php

declare(strict_types=1);

namespace Klausurplan\Tests\Unit\Models;

use Klausurplan\Models\Database;
use Klausurplan\Tests\Support\FakePdo;
use Klausurplan\Tests\Support\TestCase;
use RuntimeException;

final class DatabaseTest extends TestCase
{
    private array $altEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->altEnv = $_ENV;
        Database::setInstance(null);
    }

    protected function tearDown(): void
    {
        $_ENV = $this->altEnv;
        parent::tearDown();
    }

    public function testGetInstanceLiefertDieGesetzteInstanzImmerWieder(): void
    {
        $pdo = new FakePdo();
        Database::setInstance($pdo);

        $this->assertSame($pdo, Database::getInstance());
        $this->assertSame($pdo, Database::getInstance());
    }

    public function testOhneDatenbanknameWirdNichtVerbunden(): void
    {
        unset($_ENV['DB_NAME']);
        $_ENV['DB_USER'] = 'u';

        $this->erwarteFehler(fn () => Database::getInstance(), 'DB_NAME nicht konfiguriert');
    }

    public function testOhneBenutzernamenWirdNichtVerbunden(): void
    {
        $_ENV['DB_NAME'] = 'kp';
        unset($_ENV['DB_USER']);

        $this->erwarteFehler(fn () => Database::getInstance(), 'DB_USER nicht konfiguriert');
    }

    public function testVerbindungsfehlerWerdenAlsRuntimeExceptionGemeldet(): void
    {
        if (!extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql nicht verfügbar');
        }
        $_ENV['DB_HOST'] = '127.0.0.1:1';
        $_ENV['DB_NAME'] = 'kp';
        $_ENV['DB_USER'] = 'u';

        $e = $this->fange(fn () => Database::getInstance());

        $this->assertInstanceOf(RuntimeException::class, $e);
        $this->assertStringContainsString('Datenbankverbindung fehlgeschlagen', $e->getMessage());
    }
}
