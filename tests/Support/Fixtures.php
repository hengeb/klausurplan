<?php

declare(strict_types=1);

namespace Klausurplan\Tests\Support;

use PDO;

/** Legt Testdaten direkt in der Datenbank an (Integrationstests). */
final class Fixtures
{
    public function __construct(private readonly PDO $pdo) {}

    /** @param list<string> $rollen */
    public function benutzer(string $vorname, string $nachname, array $rollen = [], ?string $kuerzel = null, ?string $email = null, ?string $stufe = null, bool $extern = false): int
    {
        static $zaehler = 0;
        $zaehler++;

        $this->pdo->prepare(
            'INSERT INTO benutzer (moodle_id, vorname, nachname, email, kuerzel, stufe, extern) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([($extern ? 'extern:' : '') . "m{$zaehler}", $vorname, $nachname, $email, $kuerzel, $stufe, (int) $extern]);
        $id = (int) $this->pdo->lastInsertId();

        foreach ($rollen as $rolle) {
            $this->pdo->prepare('INSERT INTO rollen (benutzer_id, rolle) VALUES (?, ?)')->execute([$id, $rolle]);
        }
        return $id;
    }

    public function stufe(string $name, string $schuljahr = '2025/2026'): int
    {
        $this->pdo->prepare('INSERT INTO stufen (name, schuljahr) VALUES (?, ?)')->execute([$name, $schuljahr]);
        return (int) $this->pdo->lastInsertId();
    }

    public function halbjahr(int $stufeId, int $abschnitt = 1): int
    {
        $this->pdo->prepare('INSERT INTO halbjahre (stufe_id, abschnitt) VALUES (?, ?)')->execute([$stufeId, $abschnitt]);
        return (int) $this->pdo->lastInsertId();
    }

    public function stufenleitung(int $benutzerId, int $stufeId): void
    {
        $this->pdo->prepare('INSERT INTO stufenleitungen (benutzer_id, stufe_id) VALUES (?, ?)')->execute([$benutzerId, $stufeId]);
    }

    public function kurs(int $halbjahrId, string $kuerzel, ?int $lehrerId = null, ?string $lehrerKuerzel = null, string $kursart = 'GK', ?string $anzeigename = null): int
    {
        $this->pdo->prepare(
            'INSERT INTO kurse (halbjahr_id, kurs_kuerzel, fach_kuerzel, kursart, lehrer_kuerzel, lehrer_id, anzeigename)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$halbjahrId, $kuerzel, strtok($kuerzel, '_') ?: $kuerzel, $kursart, $lehrerKuerzel, $lehrerId, $anzeigename ?? $kuerzel]);
        return (int) $this->pdo->lastInsertId();
    }

    public function kursSchueler(int $kursId, string $nameRoh, ?int $schuelerId = null): int
    {
        $this->pdo->prepare('INSERT INTO kurs_schueler (kurs_id, name_roh, schueler_id) VALUES (?, ?, ?)')
            ->execute([$kursId, $nameRoh, $schuelerId]);
        return (int) $this->pdo->lastInsertId();
    }

    public function klausur(int $kursId, ?string $datum = null, ?string $uhrzeit = null, int $nr = 1): int
    {
        $this->pdo->prepare('INSERT INTO klausuren (kurs_id, klausur_nr, termin_datum, termin_uhrzeit) VALUES (?, ?, ?, ?)')
            ->execute([$kursId, $nr, $datum, $uhrzeit]);
        return (int) $this->pdo->lastInsertId();
    }

    public function anwesenheit(int $klausurId, int $kursSchuelerId, string $status, ?int $entschuldigt = null): int
    {
        $this->pdo->prepare('INSERT INTO anwesenheiten (klausur_id, kurs_schueler_id, status, entschuldigt) VALUES (?, ?, ?, ?)')
            ->execute([$klausurId, $kursSchuelerId, $status, $entschuldigt]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Anzahl Zeilen einer Abfrage (Kurzform für Assertions). */
    public function zaehle(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function wert(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public function zeilen(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
