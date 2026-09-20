<?php

declare(strict_types=1);

namespace Klausurplan\Tests\Support;

/** Antwort der FakePdo auf eine SQL-Abfrage. */
final class FakeResult
{
    /**
     * @param list<array<string, mixed>> $rows      Ergebniszeilen (SELECT)
     * @param int                        $rowCount  Betroffene Zeilen (INSERT/UPDATE/DELETE)
     * @param int|null                   $insertId  Wert für lastInsertId() nach dem Statement
     */
    public function __construct(
        public readonly array $rows = [],
        public readonly int $rowCount = 0,
        public readonly ?int $insertId = null,
    ) {}

    /** @param list<array<string, mixed>> $rows */
    public static function rows(array $rows): self
    {
        return new self($rows, count($rows));
    }

    public static function count(int $n): self
    {
        return new self([], $n);
    }

    public static function insert(int $id): self
    {
        return new self([], 1, $id);
    }

    /** Einzelner Skalarwert (fetchColumn). */
    public static function scalar(mixed $wert): self
    {
        return new self([['wert' => $wert]], 1);
    }

    public static function leer(): self
    {
        return new self();
    }
}
