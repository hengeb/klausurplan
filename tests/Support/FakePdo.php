<?php

declare(strict_types=1);

namespace Klausurplan\Tests\Support;

use Closure;
use PDO;
use PDOStatement;

/**
 * Datenbank-Attrappe für Unit-Tests.
 *
 * Antworten werden per Regex auf das SQL registriert; die erste passende Regel gewinnt.
 * Ohne passende Regel liefert jede Abfrage ein leeres Ergebnis. Alle ausgeführten
 * Statements samt Parametern stehen in $log – so prüfen Tests, WAS gesendet wurde.
 * Ob das SQL selbst korrekt ist, prüfen die Integrationstests gegen eine echte Datenbank.
 */
final class FakePdo extends PDO
{
    /** @var list<array{sql: string, params: array}> */
    public array $log = [];

    /** @var list<array{regex: string, antwort: FakeResult|Closure|list<FakeResult>}> */
    private array $regeln = [];

    /** @var array<int, int> Zähler für Folgeantworten je Regel */
    private array $zaehler = [];

    private int $letzteId = 0;

    public function __construct() {}

    /**
     * @param FakeResult|list<FakeResult>|Closure(string, array): FakeResult $antwort
     *        Eine Liste liefert bei jedem Aufruf die nächste Antwort (die letzte wiederholt sich).
     */
    public function on(string $regex, FakeResult|array|Closure $antwort): self
    {
        $this->regeln[] = ['regex' => $regex, 'antwort' => $antwort];
        return $this;
    }

    /** Kurzform: Regel liefert diese Zeilen. */
    public function onRows(string $regex, array $rows): self
    {
        return $this->on($regex, FakeResult::rows($rows));
    }

    /** Kurzform: Regel liefert einen Skalar (fetchColumn) – `false`/leer für „nichts gefunden“. */
    public function onScalar(string $regex, mixed $wert): self
    {
        return $this->on($regex, $wert === false ? FakeResult::leer() : FakeResult::scalar($wert));
    }

    public function auswerten(string $sql, array $params): FakeResult
    {
        $this->log[] = ['sql' => $sql, 'params' => $params];

        foreach ($this->regeln as $i => $regel) {
            if (!preg_match($regel['regex'], $sql)) {
                continue;
            }

            $antwort = $regel['antwort'];
            if ($antwort instanceof Closure) {
                $antwort = $antwort($sql, $params);
            } elseif (is_array($antwort)) {
                $n = $this->zaehler[$i] ?? 0;
                $this->zaehler[$i] = $n + 1;
                $antwort = $antwort[min($n, count($antwort) - 1)];
            }

            if ($antwort->insertId !== null) {
                $this->letzteId = $antwort->insertId;
            }
            return $antwort;
        }

        return FakeResult::leer();
    }

    /**
     * Ausgeführte Statements, deren SQL zum Regex passt.
     *
     * @return list<array{sql: string, params: array}>
     */
    public function aufrufe(string $regex): array
    {
        return array_values(array_filter($this->log, static fn (array $e) => (bool) preg_match($regex, $e['sql'])));
    }

    public function wurdeAusgefuehrt(string $regex): bool
    {
        return $this->aufrufe($regex) !== [];
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new FakeStatement($this, $query);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $stmt = new FakeStatement($this, $query);
        $stmt->execute();
        return $stmt;
    }

    public function exec(string $statement): int|false
    {
        return $this->auswerten($statement, [])->rowCount;
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return (string) $this->letzteId;
    }
}
