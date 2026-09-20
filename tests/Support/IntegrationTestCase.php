<?php

declare(strict_types=1);

namespace Klausurplan\Tests\Support;

use Klausurplan\Models\Database;
use PDO;

/**
 * Basis der Integrationstests: echte MariaDB/MySQL (Verbindungsdaten aus KLAUSURPLAN_TEST_DB_*).
 * Ohne diese Variablen werden die Tests übersprungen. Das Schema stammt aus migrations/001_schema.sql
 * und wird vor jedem Test geleert (Stammdaten fach_bezeichnungen bleiben).
 */
abstract class IntegrationTestCase extends BaseTestCase
{
    private static ?PDO $verbindung = null;
    private static bool $schemaGeladen = false;

    protected PDO $db;
    protected Fixtures $fx;

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('KLAUSURPLAN_TEST_DB_HOST');
        if ($host === false || $host === '') {
            $this->markTestSkipped('Keine Test-Datenbank (KLAUSURPLAN_TEST_DB_HOST) – bin/test-integration.sh starten.');
        }

        self::$verbindung ??= self::verbinden($host);
        $this->db = self::$verbindung;

        if (!self::$schemaGeladen) {
            $this->db->exec('DROP DATABASE IF EXISTS ' . self::datenbankname());
            $this->db->exec('CREATE DATABASE ' . self::datenbankname() . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $this->db->exec('USE ' . self::datenbankname());
            $this->db->exec((string) file_get_contents(__DIR__ . '/../../migrations/001_schema.sql'));
            self::$schemaGeladen = true;
        }

        $this->leereTabellen();
        Database::setInstance($this->db);
        $this->fx = new Fixtures($this->db);
    }

    private static function datenbankname(): string
    {
        return (string) (getenv('KLAUSURPLAN_TEST_DB_NAME') ?: 'klausurplan_test');
    }

    private static function verbinden(string $host): PDO
    {
        // Gleiche Optionen wie Database::connect() (native Prepared Statements)
        return new PDO(
            "mysql:host={$host};charset=utf8mb4",
            (string) (getenv('KLAUSURPLAN_TEST_DB_USER') ?: 'root'),
            (string) getenv('KLAUSURPLAN_TEST_DB_PASS'),
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ],
        );
    }

    /** Verbindungsdaten für Subprozesse (Cron) als Umgebungsvariablen. */
    protected static function dbUmgebung(): array
    {
        return [
            'DB_HOST' => (string) getenv('KLAUSURPLAN_TEST_DB_HOST'),
            'DB_NAME' => self::datenbankname(),
            'DB_USER' => (string) (getenv('KLAUSURPLAN_TEST_DB_USER') ?: 'root'),
            'DB_PASS' => (string) getenv('KLAUSURPLAN_TEST_DB_PASS'),
        ];
    }

    private function leereTabellen(): void
    {
        $tabellen = $this->db->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
               AND TABLE_NAME NOT IN ('fach_bezeichnungen')"
        )->fetchAll(PDO::FETCH_COLUMN);

        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tabellen as $tabelle) {
            $this->db->exec("DELETE FROM `$tabelle`"); // schneller als TRUNCATE (DDL) – besonders auf MySQL
        }
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
