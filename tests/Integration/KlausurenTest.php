<?php

declare(strict_types=1);

namespace Klausurplan\Tests\Integration;

use Klausurplan\Api\LehrkraftApi;
use Klausurplan\Tests\Support\IntegrationTestCase;

/** Klausurtermine: Listen je Rolle, Anlegen für alle Stufen, CSV-Vorlage, Excel-Import, Nachschreibtermine. */
final class KlausurenTest extends IntegrationTestCase
{
    private int $sl;
    private int $lehrer;
    private int $q1;
    private int $q2;
    private int $hjQ1;
    private int $hjQ2;
    private int $kursQ1;
    private int $kursQ2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sl     = $this->fx->benutzer('Sarah', 'Leitung', ['stufenleitung', 'lehrkraft']);
        $this->lehrer = $this->fx->benutzer('Anna', 'Lehrer (SZ)', ['lehrkraft'], 'SZ');
        $this->q1     = $this->fx->stufe('Q1');
        $this->q2     = $this->fx->stufe('Q2');
        $this->hjQ1   = $this->fx->halbjahr($this->q1);
        $this->hjQ2   = $this->fx->halbjahr($this->q2);
        $this->kursQ1 = $this->fx->kurs($this->hjQ1, 'D_Q1_GK1_ZZ', null, 'ZZ', 'GK', 'Q1 Deutsch GK 1 ZZ');
        $this->kursQ2 = $this->fx->kurs($this->hjQ2, 'SPA_Q2_GK1_SZ', $this->lehrer, 'SZ', 'GK', 'Q2 Sport GK 1 SZ');
        $this->fx->stufenleitung($this->sl, $this->q2);
        $this->alsBenutzer($this->sl, ['stufenleitung', 'lehrkraft']);
    }

    private function kuerzel(array $klausuren): array
    {
        return array_column($klausuren, 'kurs_kuerzel');
    }

    // ------------------------------------------------------------------ Listen

    public function testKlausurlisteJeRolle(): void
    {
        $this->fx->klausur($this->kursQ1, '2026-10-05', '08:00');
        $this->fx->klausur($this->kursQ2, '2026-10-06', '08:00');
        $this->fx->klausur($this->kursQ2, null, null, 2);

        // Stufenleitung: eigene Stufe Q2 (Klausur ohne Datum zuletzt)
        $liste = LehrkraftApi::getKlausuren();
        $this->assertSame(['SPA_Q2_GK1_SZ', 'SPA_Q2_GK1_SZ'], $this->kuerzel($liste));
        $this->assertSame(['2026-10-06', null], array_column($liste, 'termin_datum'));
        $this->assertSame([1, 1], array_map('intval', array_column($liste, 'ist_eigene_sl')));

        // … mit ?alle=1 alle Stufen
        $_GET['alle'] = '1';
        $alle = LehrkraftApi::getKlausuren();
        $this->assertCount(3, $alle);
        $this->assertSame([0], array_unique(array_map('intval', array_column(array_filter($alle, fn ($k) => $k['stufe'] === 'Q1'), 'ist_eigene_sl'))));

        // Lehrkraft: nur eigene Kurse, ?alle wird ignoriert
        $this->alsBenutzer($this->lehrer, ['lehrkraft']);
        $this->assertCount(2, LehrkraftApi::getKlausuren());

        // Admin ohne Stufenleitung: alles
        $this->alsBenutzer($this->fx->benutzer('Ada', 'Admin', ['admin']), ['admin']);
        unset($_GET['alle']);
        $admin = LehrkraftApi::getKlausuren();
        $this->assertCount(3, $admin);
        $this->assertSame([1], array_unique(array_map('intval', array_column($admin, 'ist_eigene_sl'))));
    }

    public function testStufenleitungOhneStufeSiehtNurEigeneKurse(): void
    {
        $zweite = $this->fx->benutzer('Zoe', 'Zweit', ['stufenleitung', 'lehrkraft']);
        $eigenerKurs = $this->fx->kurs($this->hjQ1, 'E_Q1_GK2_ZO', $zweite, 'ZO');
        $this->fx->klausur($eigenerKurs, '2026-10-07');
        $this->fx->klausur($this->kursQ2, '2026-10-06');
        $this->alsBenutzer($zweite, ['stufenleitung', 'lehrkraft']);

        $this->assertSame(['E_Q1_GK2_ZO'], $this->kuerzel(LehrkraftApi::getKlausuren()));
    }

    public function testHalbjahrFilterUndNachschreiberliste(): void
    {
        $klausur = $this->fx->klausur($this->kursQ2, '2026-10-06');
        $eva = $this->fx->kursSchueler($this->kursQ2, 'Schüler|Eva');
        $max = $this->fx->kursSchueler($this->kursQ2, 'Mustermann|Max');
        $this->fx->anwesenheit($klausur, $eva, 'fehlend', null);
        $this->fx->anwesenheit($klausur, $max, 'fehlend', 0); // unentschuldigt → kein Nachschreiber
        $_GET['nachschreiber'] = '1';
        $_GET['halbjahr_id'] = (string) $this->hjQ2;

        $liste = LehrkraftApi::getKlausuren();

        $this->assertCount(1, $liste);
        $this->assertSame(['Schüler|Eva'], array_column($liste[0]['nachschreiber'], 'name_roh'));
        $this->assertSame(2, (int) $liste[0]['schueler_anzahl']);
        $this->assertSame(1, (int) $liste[0]['anwesenheit_erfasst'] - 1, 'zwei erfasste Einträge');
    }

    // ------------------------------------------------------------------ Anlegen & Ändern

    public function testStufenleitungLegtKlausurenFuerJedeStufeAnUndBearbeitetSie(): void
    {
        $r = LehrkraftApi::postKlausur(['kurs_id' => $this->kursQ1, 'termin_datum' => '05.10.2026', 'termin_uhrzeit' => '8:00', 'dauer_minuten' => 90]);
        $zweite = LehrkraftApi::postKlausur(['kurs_id' => $this->kursQ1]);

        $this->assertSame([1, 2], [$r['klausur_nr'], $zweite['klausur_nr']]);
        $zeile = $this->fx->zeilen('SELECT * FROM klausuren WHERE id = ?', [$r['id']])[0];
        $this->assertSame(['2026-10-05', '08:00:00', 90, $this->sl], [$zeile['termin_datum'], $zeile['termin_uhrzeit'], (int) $zeile['dauer_minuten'], (int) $zeile['erstellt_von']]);

        LehrkraftApi::putKlausur($r['id'], ['termin_datum' => '2026-10-12', 'dauer_minuten' => 45]);
        $zeile = $this->fx->zeilen('SELECT * FROM klausuren WHERE id = ?', [$r['id']])[0];
        $this->assertSame(['2026-10-12', null, 45], [$zeile['termin_datum'], $zeile['termin_uhrzeit'], (int) $zeile['dauer_minuten']]);

        LehrkraftApi::deleteKlausur($r['id']);
        $this->assertSame(0, $this->fx->zaehle('SELECT COUNT(*) FROM klausuren WHERE id = ?', [$r['id']]));
    }

    public function testGetKurseLiefertAlleStufenMitZustaendigkeit(): void
    {
        $kurse = array_column(LehrkraftApi::getKurse(), null, 'kurs_kuerzel');

        $this->assertCount(2, $kurse);
        $this->assertSame(1, (int) $kurse['SPA_Q2_GK1_SZ']['ist_eigene_sl']);
        $this->assertSame(0, (int) $kurse['D_Q1_GK1_ZZ']['ist_eigene_sl']);
        $this->assertSame($this->q1, (int) $kurse['D_Q1_GK1_ZZ']['stufe_id']);
        $this->assertSame($this->hjQ1, (int) $kurse['D_Q1_GK1_ZZ']['halbjahr_id']);
    }

    // ------------------------------------------------------------------ Excel-Import

    private function pasteZeile(string $kurs, string $datum = '', string $uhrzeit = '', string $dauer = ''): array
    {
        return ['Kurs' => $kurs, 'Datum' => $datum, 'Uhrzeit' => $uhrzeit, 'Dauer' => $dauer];
    }

    public function testExcelImportLegtAnAktualisiertUndFuelltDatumslose(): void
    {
        // neu anlegen
        $r = LehrkraftApi::postPasteImport([$this->pasteZeile('D_Q1_GK1_ZZ', '05.10.2026', '8:00', '90')], $this->hjQ1);
        $this->assertSame(['erstellt' => 1, 'aktualisiert' => 0, 'fehler' => []], $r);

        // gleiches Datum → aktualisieren
        $r = LehrkraftApi::postPasteImport([$this->pasteZeile('D_Q1_GK1_ZZ', '05.10.2026', '9:30', '60')], $this->hjQ1);
        $this->assertSame(1, $r['aktualisiert']);
        $this->assertSame('09:30:00', $this->fx->wert('SELECT termin_uhrzeit FROM klausuren WHERE kurs_id = ?', [$this->kursQ1]));

        // datumslose Klausur wird gefüllt
        $offen = $this->fx->klausur($this->kursQ2, null, null);
        $r = LehrkraftApi::postPasteImport([$this->pasteZeile('SPA_Q2_GK1_SZ', '06.10.2026')], $this->hjQ2);
        $this->assertSame(1, $r['aktualisiert']);
        $this->assertSame('2026-10-06', $this->fx->wert('SELECT termin_datum FROM klausuren WHERE id = ?', [$offen]));
    }

    public function testExcelImportSuchtNurImGewaehltenHalbjahr(): void
    {
        $r = LehrkraftApi::postPasteImport([$this->pasteZeile('D_Q1_GK1_ZZ', '05.10.2026')], $this->hjQ2);

        $this->assertSame(0, $r['erstellt']);
        $this->assertStringContainsString('nicht gefunden', $r['fehler'][0]['meldung']);
        $this->assertSame(0, $this->fx->zaehle('SELECT COUNT(*) FROM klausuren'));
    }

    public function testExcelImportOhneHalbjahrFaelltAufAndereHalbjahreZurueck(): void
    {
        $r = LehrkraftApi::postPasteImport([$this->pasteZeile('D_Q1_GK1_ZZ', '05.10.2026'), $this->pasteZeile('GIBT_ES_NICHT'), $this->pasteZeile('SPA_Q2_GK1_SZ', 'kaputt')]);

        $this->assertSame(1, $r['erstellt']);
        $this->assertCount(2, $r['fehler']);
    }

    // ------------------------------------------------------------------ CSV-Vorlage

    private function vorlage(): array
    {
        $csv = $this->ausgabe(fn () => LehrkraftApi::downloadVorlage());
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        return array_map(fn ($z) => str_getcsv($z, ';', '"', ''), explode("\n", trim(substr($csv, 3))));
    }

    public function testVorlageProHalbjahr(): void
    {
        $this->fx->kursSchueler($this->kursQ1, 'A|B');
        $this->fx->kursSchueler($this->kursQ1, 'C|D');
        $_GET['halbjahr_id'] = (string) $this->hjQ1;

        $zeilen = $this->vorlage();

        $this->assertSame(['Kurs', 'Anzeigename', 'TN', 'Datum', 'Uhrzeit', 'Dauer'], $zeilen[0]);
        $this->assertSame([['D_Q1_GK1_ZZ', 'Q1 Deutsch GK 1 ZZ', '2', '', '', '']], array_slice($zeilen, 1));
    }

    public function testVorlageIstNichtLeerWennDasNeuesteHalbjahrOhneKurseIst(): void
    {
        // Ursache des gemeldeten Fehlers: eine neuere Stufe ohne Kurse hat die Vorlage „leer“ gemacht
        $this->fx->halbjahr($this->fx->stufe('EF', '2026/2027'));

        $zeilen = $this->vorlage();

        $this->assertGreaterThan(1, count($zeilen), 'Vorlage enthält die Kurse des neuesten Halbjahres MIT Kursen');
        $this->assertContains('SPA_Q2_GK1_SZ', array_column($zeilen, 0));
    }

    public function testVorlageFuerUnbekanntesHalbjahrUndOhneKurse(): void
    {
        $_GET['halbjahr_id'] = '999999';
        $this->erwarteFehler(fn () => LehrkraftApi::downloadVorlage(), 'nicht gefunden', 404);

        $this->db->exec('DELETE FROM kurse');
        unset($_GET['halbjahr_id']);
        $this->assertCount(1, $this->vorlage(), 'nur die Kopfzeile');
    }

    // ------------------------------------------------------------------ Nachschreibtermine

    public function testNachschreibtermineVomAnlegenBisZurAnzeige(): void
    {
        $klausur = $this->fx->klausur($this->kursQ2, '2026-10-06');
        $eva = $this->fx->kursSchueler($this->kursQ2, 'Schüler|Eva');
        $this->fx->anwesenheit($klausur, $eva, 'fehlend');

        $id = LehrkraftApi::postNachschreibtermin(['termin_datum' => '01.12.2026', 'termin_uhrzeit' => '13:00', 'bemerkung' => 'Raum 12'])['id'];
        LehrkraftApi::putNachschreibtermin($id, ['termin_datum' => '2026-12-02', 'bemerkung' => 'Raum 14']);
        LehrkraftApi::postNachschreibterminKlausuren($id, ['klausur_ids' => [$klausur]]);

        $termine = LehrkraftApi::getNachschreibtermine();
        $this->assertCount(1, $termine);
        $this->assertSame('2026-12-02', $termine[0]['termin_datum']);
        $this->assertSame(['Schüler|Eva'], array_column($termine[0]['klausuren'][0]['nachschreiber'], 'name_roh'));

        // Lehrkraft des Kurses sieht ihren Termin
        $this->alsBenutzer($this->lehrer, ['lehrkraft']);
        $mine = LehrkraftApi::meineNachschreibtermine();
        $this->assertSame([1], array_map('intval', array_column($mine, 'nachschreiber_anzahl')));

        // Verknüpfung ersetzen und Termin löschen
        $this->alsBenutzer($this->sl, ['stufenleitung']);
        LehrkraftApi::postNachschreibterminKlausuren($id, ['klausur_ids' => []]);
        $this->assertSame([], LehrkraftApi::getNachschreibtermine()[0]['klausuren']);
        LehrkraftApi::deleteNachschreibtermin($id);
        $this->assertSame([], LehrkraftApi::getNachschreibtermine());
    }
}
