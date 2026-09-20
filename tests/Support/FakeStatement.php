<?php

declare(strict_types=1);

namespace Klausurplan\Tests\Support;

use PDO;
use PDOStatement;

/** Minimales PDOStatement, das die Ergebnisse der FakePdo ausliefert. */
final class FakeStatement extends PDOStatement
{
    /** @var list<array<string, mixed>> */
    private array $rows = [];
    private int $zeiger = 0;
    private int $betroffen = 0;

    public function __construct(private readonly FakePdo $pdo, private readonly string $sql) {}

    public function execute(?array $params = null): bool
    {
        $antwort = $this->pdo->auswerten($this->sql, $params ?? []);
        $this->rows      = $antwort->rows;
        $this->zeiger    = 0;
        $this->betroffen = $antwort->rowCount;
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if (!isset($this->rows[$this->zeiger])) {
            return false;
        }
        $zeile = $this->rows[$this->zeiger++];
        return $mode === PDO::FETCH_NUM ? array_values($zeile) : $zeile;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rest = array_slice($this->rows, $this->zeiger);
        $this->zeiger = count($this->rows);

        return match ($mode) {
            PDO::FETCH_COLUMN => array_map(static fn (array $z) => array_values($z)[(int) ($args[0] ?? 0)], $rest),
            PDO::FETCH_NUM    => array_map('array_values', $rest),
            default           => $rest,
        };
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $zeile = $this->fetch(PDO::FETCH_NUM);
        return $zeile === false ? false : $zeile[$column];
    }

    public function rowCount(): int
    {
        return $this->betroffen;
    }
}
