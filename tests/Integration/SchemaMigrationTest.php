<?php

declare(strict_types=1);

namespace Klausurplan\Tests\Integration;

use Klausurplan\Tests\Support\IntegrationTestCase;

final class SchemaMigrationTest extends IntegrationTestCase
{
    private function tabellen(): array
    {
        return $this->db->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY 1"
        )->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function spalten(string $tabelle): array
    {
        $stmt = $this->db->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$tabelle]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function testNeuinstallationLegtAlleAnwendungstabellenAn(): void
    {
        $erwartet = [
            'benutzer', 'rollen', 'stufen', 'stufenleitungen', 'halbjahre', 'kurse', 'kurs_schueler', 'klausuren',
            'nachschreibtermine', 'nachschreib_zuordnungen', 'anwesenheiten', 'nachschreib_anwesenheiten',
            'email_benachrichtigungen', 'fach_bezeichnungen', 'schueler_zuordnungen', 'lehrer_zuordnungen',
            'stufenleitung_erinnerungen', 'lti2_consumer', 'lti2_nonce',
        ];

        $this->assertEmpty(array_diff($erwartet, $this->tabellen()));
        $this->assertContains('extern', $this->spalten('benutzer'));
        $this->assertGreaterThan(20, $this->fx->zaehle('SELECT COUNT(*) FROM fach_bezeichnungen'), 'Fächer-Stammdaten');
    }

    /** Baut den Zustand vor Migration 003 nach (ohne die neuen Tabellen/Spalte). */
    private function alterStand(): void
    {
        $this->db->exec('DROP TABLE IF EXISTS stufenleitung_erinnerungen, lehrer_zuordnungen, schueler_zuordnungen');
        $this->db->exec('ALTER TABLE benutzer DROP COLUMN extern');
    }

    private function migration003(): void
    {
        $this->db->exec((string) file_get_contents(__DIR__ . '/../../migrations/003_zuordnungen_extern.sql'));
    }

    public function testMigration003ErgaenztBestehendeInstallationenUndUebernimmtZuordnungen(): void
    {
        $lehrer = $this->fx->benutzer('Anna', 'Lehrer (SZ)', ['lehrkraft'], 'SZ');
        $schueler = $this->fx->benutzer('Max', 'Mustermann');
        $kurs = $this->fx->kurs($this->fx->halbjahr($this->fx->stufe('Q2')), 'SP_Q2_GK1_SZ', $lehrer, 'SZ');
        $this->fx->kursSchueler($kurs, 'Mustermann|Max', $schueler);
        $this->fx->kursSchueler($kurs, 'Offen|Otto');
        $this->alterStand();

        $this->migration003();

        $this->assertContains('extern', $this->spalten('benutzer'));
        $this->assertEmpty(array_diff(['schueler_zuordnungen', 'lehrer_zuordnungen', 'stufenleitung_erinnerungen'], $this->tabellen()));
        $this->assertSame(0, $this->fx->zaehle('SELECT extern FROM benutzer WHERE id = ?', [$lehrer]), 'Bestandsnutzer sind nicht extern');
        $this->assertSame([['name_roh' => 'Mustermann|Max', 'benutzer_id' => $schueler]],
            $this->fx->zeilen('SELECT name_roh, benutzer_id FROM schueler_zuordnungen'), 'nur echte Zuordnungen werden übernommen');
        $this->assertSame([['lehrer_kuerzel' => 'SZ', 'benutzer_id' => $lehrer]],
            $this->fx->zeilen('SELECT lehrer_kuerzel, benutzer_id FROM lehrer_zuordnungen'));
    }

    public function testMigration003IstMehrfachAusfuehrbar(): void
    {
        $this->alterStand();

        $this->migration003();
        $this->migration003();

        $this->assertContains('extern', $this->spalten('benutzer'));
    }

    public function testMigrationUndNeuinstallationErgebenDasselbeSchema(): void
    {
        $vorher = [];
        foreach (['benutzer', 'schueler_zuordnungen', 'lehrer_zuordnungen', 'stufenleitung_erinnerungen'] as $t) {
            $vorher[$t] = $this->spalten($t);
            sort($vorher[$t]);
        }

        $this->alterStand();
        $this->migration003();

        foreach ($vorher as $tabelle => $spalten) {
            $nachher = $this->spalten($tabelle);
            sort($nachher);
            $this->assertSame($spalten, $nachher, $tabelle);
        }
    }

    public function testFremdschluesselSchuetzenDieNeuenTabellen(): void
    {
        $lehrer = $this->fx->benutzer('Anna', 'L', ['lehrkraft']);
        $this->db->prepare('INSERT INTO lehrer_zuordnungen (lehrer_kuerzel, benutzer_id) VALUES (?, ?)')->execute(['SZ', $lehrer]);
        $this->db->prepare('INSERT INTO schueler_zuordnungen (name_roh, benutzer_id) VALUES (?, ?)')->execute(['A|B', $lehrer]);

        $this->db->prepare('DELETE FROM benutzer WHERE id = ?')->execute([$lehrer]);

        $this->assertSame(0, $this->fx->zaehle('SELECT COUNT(*) FROM lehrer_zuordnungen'), 'Zuordnungen verschwinden mit dem Konto');
        $this->assertSame(0, $this->fx->zaehle('SELECT COUNT(*) FROM schueler_zuordnungen'));

        $this->expectException(\PDOException::class);
        $this->db->prepare('INSERT INTO lehrer_zuordnungen (lehrer_kuerzel, benutzer_id) VALUES (?, ?)')->execute(['XX', 99999]);
    }
}
