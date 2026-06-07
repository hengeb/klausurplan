<?php

declare(strict_types=1);

namespace Klausurplan\Models;

use PDO;
use PDOException;
use RuntimeException;

class Database
{
    private static ?PDO $instance = null;

    private function __construct() {}
    private function __clone() {}

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            self::$instance = self::connect();
        }

        return self::$instance;
    }

    private static function connect(): PDO
    {
        $host = $_ENV['DB_HOST'] ?? 'localhost';
        $name = $_ENV['DB_NAME'] ?? throw new RuntimeException('DB_NAME nicht konfiguriert');
        $user = $_ENV['DB_USER'] ?? throw new RuntimeException('DB_USER nicht konfiguriert');
        $pass = $_ENV['DB_PASS'] ?? '';

        $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";

        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Datenbankverbindung fehlgeschlagen: ' . $e->getMessage(), 0, $e);
        }

        return $pdo;
    }
}
