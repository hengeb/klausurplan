<?php

declare(strict_types=1);

namespace Klausurplan\Models;

use PDO;
use PDOException;
use RuntimeException;

class Database
{
    private static ?PDO $instance = null;

    /** Dauer des Verbindungsaufbaus in ms (für den Server-Timing-Header); null = keine Verbindung aufgebaut. */
    private static ?float $verbindungsdauerMs = null;

    public static function verbindungsdauerMs(): ?float
    {
        return self::$verbindungsdauerMs;
    }

    private function __construct() {}
    private function __clone() {}

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            self::$instance = self::connect();
        }

        return self::$instance;
    }

    /** Ersetzt die Instanz (nur für Tests); null setzt zurück, sodass neu verbunden wird. */
    public static function setInstance(?PDO $pdo): void
    {
        self::$instance = $pdo;
    }

    private static function connect(): PDO
    {
        $host = $_ENV['DB_HOST'] ?? 'localhost';
        $name = $_ENV['DB_NAME'] ?? throw new RuntimeException('DB_NAME nicht konfiguriert');
        $user = $_ENV['DB_USER'] ?? throw new RuntimeException('DB_USER nicht konfiguriert');
        $pass = $_ENV['DB_PASS'] ?? '';

        $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";

        $beginn = hrtime(true);
        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Datenbankverbindung fehlgeschlagen: ' . $e->getMessage(), 0, $e);
        }
        self::$verbindungsdauerMs = (hrtime(true) - $beginn) / 1e6;

        return $pdo;
    }
}
