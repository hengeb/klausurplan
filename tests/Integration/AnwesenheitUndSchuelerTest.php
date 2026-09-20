<?php

declare(strict_types=1);

namespace Klausurplan\Tests\Integration;

use Klausurplan\Api\AnwesenheitApi;
use Klausurplan\Api\SchuelerApi;
use Klausurplan\Tests\Support\IntegrationTestCase;

/** Anwesenheit erfassen/korrigieren/entschuldigen, Token-Seiten und die Sicht der Schüler*innen. */
final class AnwesenheitUndSchuelerTest extends IntegrationTestCase
{
    private int $lehrer;
    private int $fremde;
    private int $sl;
    private int $max;
    private int $eva;
    private int $kurs;
    private int $klausur;
    private int $ksMax;
    private int $ksEva;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lehrer = $this->fx->benutzer('Anna', 'Lehrer (SZ)', ['lehrkraft'], 'SZ');
        $this->fremde = $this->fx->benutzer('Fritz', 'Fremd (FF)', ['lehrkraft'], 'FF');
        $this->sl     = $this->fx->benutzer('Sarah', 'Leitung', ['stufenleitung']);
        $this->max    = $this->fx->benutzer('Max', 'Mustermann', ['schueler']);
        $this->eva    = $this->fx->benutzer('Eva', 'Muster', ['schueler']);
        $hj           = $this->fx->halbjahr($this->fx->stufe('Q2'));
        $this->kurs   = $this->fx->kurs($hj, 'SPA_Q2_GK1_SZ', $this->lehrer, 'SZ', 'GK', 'Q2 Sport GK 1 SZ');
        $this->ksMax  = $this->fx->kursSchueler($this->kurs, 'Mustermann|Max', $this->max);
        $this->ksEva  = $this->fx->kursSchueler($this->kurs, 'Muster|Eva', $this->eva);
        $this->klausur = $this->fx->klausur($this->kurs, '2026-10-06', '08:00');
    }

    private function statusVon(int $ks): ?string
    {
        return $this->fx->wert('SELECT status FROM anwesenheiten WHERE klausur_id = ? AND kurs_schueler_id = ?', [$this->klausur, $ks]) ?: null;
    }

    // ------------------------------------------------------------------ Erfassen

    public function testLehrkraftTraegtEigeneKlausurEinAberKeineEntschuldigung(): void
    {
        $this->alsBenutzer($this->lehrer, ['lehrkraft']);

        $r = AnwesenheitApi::postAnwesenheit($this->klausur, [
            ['kurs_schueler_id' => $this->ksMax, 'status' => 'anwesend'],
            ['kurs_schueler_id' => $this->ksEva, 'status' => 'fehlend', 'kommentar' => 'krank', 'entschuldigt' => true],
        ]);

        $this->assertSame(2, $r['gespeichert']);
        $this->assertSame('fehlend', $this->statusVon($this->ksEva));
        $this->assertNull($this->fx->wert('SELECT entschuldigt FROM anwesenheiten WHERE kurs_schueler_id = ?', [$this->ksEva]), 'Lehrkräfte entschuldigen nicht');
        $this->assertSame('krank', $this->fx->wert('SELECT kommentar FROM anwesenheiten WHERE kurs_schueler_id = ?', [$this->ksEva]));
        $this->assertSame($this->lehrer, (int) $this->fx->wert('SELECT erfasst_von FROM anwesenheiten WHERE kurs_schueler_id = ?', [$this->ksEva]));
    }

    public function testFremdeLehrkraftHatKeinenZugriff(): void
    {
        $this->alsBenutzer($this->fremde, ['lehrkraft']);

        $this->erwarteFehler(fn () => AnwesenheitApi::getAnwesenheit($this->klausur), 'Kein Zugriff', 403);
        $this->erwarteFehler(fn () => AnwesenheitApi::postAnwesenheit($this->klausur, [['kurs_schueler_id' => $this->ksMax, 'status' => 'anwesend']]), 'Kein Zugriff', 403);
        $this->assertSame(0, $this->fx->zaehle('SELECT COUNT(*) FROM anwesenheiten'));
    }

    public function testStufenleitungKorrigiertUndEntschuldigt(): void
    {
        $this->alsBenutzer($this->lehrer, ['lehrkraft']);
        AnwesenheitApi::postAnwesenheit($this->klausur, [['kurs_schueler_id' => $this->ksEva, 'status' => 'fehlend']]);

        $this->alsBenutzer($this->sl, ['stufenleitung']);
        $anwId = (int) $this->fx->wert('SELECT id FROM anwesenheiten WHERE kurs_schueler_id = ?', [$this->ksEva]);
        AnwesenheitApi::postEntschuldigung($anwId, ['entschuldigt' => true, 'kommentar' => 'Attest']);

        $zeile = $this->fx->zeilen('SELECT * FROM anwesenheiten WHERE id = ?', [$anwId])[0];
        $this->assertSame([1, 'Attest', $this->sl], [(int) $zeile['entschuldigt'], $zeile['kommentar'], (int) $zeile['geaendert_von']]);

        // Korrektur über die Sammel-API, Entschuldigung bleibt erhalten, wenn nicht mitgesendet
        AnwesenheitApi::postAnwesenheit($this->klausur, [['kurs_schueler_id' => $this->ksEva, 'status' => 'anwesend']]);
        $this->assertSame('anwesend', $this->statusVon($this->ksEva));
        $this->assertSame(1, (int) $this->fx->wert('SELECT entschuldigt FROM anwesenheiten WHERE id = ?', [$anwId]));

        // … oder explizit zurückgesetzt
        AnwesenheitApi::postAnwesenheit($this->klausur, [['kurs_schueler_id' => $this->ksEva, 'status' => 'fehlend', 'entschuldigt' => null]]);
        $this->assertNull($this->fx->wert('SELECT entschuldigt FROM anwesenheiten WHERE id = ?', [$anwId]));
    }

    public function testAnwesenheitslisteEnthaeltAuchNochNichtErfasste(): void
    {
        $this->alsBenutzer($this->lehrer, ['lehrkraft']);
        AnwesenheitApi::postAnwesenheit($this->klausur, [['kurs_schueler_id' => $this->ksMax, 'status' => 'anwesend']]);

        $liste = array_column(AnwesenheitApi::getAnwesenheit($this->klausur), null, 'name_roh');

        $this->assertSame('anwesend', $liste['Mustermann|Max']['status']);
        $this->assertSame('ausstehend', $liste['Muster|Eva']['status']);
        $this->assertSame('Muster', $liste['Muster|Eva']['nachname']);
    }

    public function testPruefllingeAusFremdenKursenWerdenIgnoriert(): void
    {
        $anderer = $this->fx->kursSchueler($this->fx->kurs($this->fx->halbjahr($this->fx->stufe('Q1')), 'X_Q1'), 'Fremd|Fred');
        $this->alsBenutzer($this->sl, ['stufenleitung']);

        $r = AnwesenheitApi::postAnwesenheit($this->klausur, [['kurs_schueler_id' => $anderer, 'status' => 'anwesend']]);

        $this->assertSame(0, $r['gespeichert']);
    }

    // ------------------------------------------------------------------ Nachschreiben

    public function testNachschreibAnwesenheit(): void
    {
        $this->fx->anwesenheit($this->klausur, $this->ksEva, 'fehlend', null);
        $this->fx->anwesenheit($this->klausur, $this->ksMax, 'fehlend', 0); // unentschuldigt: kein Nachschreiben
        $this->db->exec("INSERT INTO nachschreibtermine (termin_datum) VALUES ('2026-12-01')");
        $nt = (int) $this->db->lastInsertId();
        $this->db->prepare('INSERT INTO nachschreib_zuordnungen (klausur_id, nachschreibtermin_id) VALUES (?, ?)')->execute([$this->klausur, $nt]);

        $this->alsBenutzer($this->lehrer, ['lehrkraft']);
        $liste = AnwesenheitApi::getNachschreibAnwesenheit($nt);
        $this->assertSame(['Muster|Eva'], array_column($liste, 'name_roh'));
        $this->assertSame('ausstehend', $liste[0]['status']);

        $this->assertSame(1, AnwesenheitApi::postNachschreibAnwesenheit($nt, [['kurs_schueler_id' => $this->ksEva, 'status' => 'anwesend']])['gespeichert']);
        AnwesenheitApi::postNachschreibAnwesenheit($nt, [['kurs_schueler_id' => $this->ksEva, 'status' => 'fehlend', 'kommentar' => 'wieder krank']]);
        $this->assertSame(1, $this->fx->zaehle('SELECT COUNT(*) FROM nachschreib_anwesenheiten'), 'zweiter Aufruf aktualisiert');
        $this->assertSame('fehlend', AnwesenheitApi::getNachschreibAnwesenheit($nt)[0]['status']);

        $this->alsBenutzer($this->fremde, ['lehrkraft']);
        $this->erwarteFehler(fn () => AnwesenheitApi::getNachschreibAnwesenheit($nt), 'Kein Zugriff', 403);
    }

    // ------------------------------------------------------------------ Token-Seiten

    private function token(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->db->prepare("INSERT INTO email_benachrichtigungen (klausur_id, empfaenger_id, typ, token, gesendet_am) VALUES (?, ?, 'erstmeldung', ?, NOW())")
            ->execute([$this->klausur, $this->lehrer, $token]);
        return $token;
    }

    public function testTokenFormularUndSpeichern(): void
    {
        $token = $this->token();

        $html = $this->ausgabe(fn () => AnwesenheitApi::eingabeSeite($token));
        $this->assertStringContainsString('Mustermann, Max', $html);
        $this->assertStringContainsString('Q2 Sport GK 1 SZ', $html);
        $this->assertStringContainsString('06.10.2026', $html);

        $_POST = ['token' => $token, 'fehlend' => [(string) $this->ksEva], 'kommentar_' . $this->ksEva => 'Arzttermin'];
        $html = $this->ausgabe(fn () => AnwesenheitApi::tokenEintrag());

        $this->assertStringContainsString('erfolgreich gespeichert', $html);
        $this->assertSame('anwesend', $this->statusVon($this->ksMax));
        $this->assertSame('fehlend', $this->statusVon($this->ksEva));
        $this->assertNotNull($this->fx->wert('SELECT beantwortet_am FROM email_benachrichtigungen WHERE token = ?', [$token]));
    }

    public function testTokenAlleDaUeberschreibtVorhandeneEintraege(): void
    {
        $this->fx->anwesenheit($this->klausur, $this->ksEva, 'fehlend');
        $token = $this->token();

        $this->ausgabe(fn () => AnwesenheitApi::alleDa($token));

        $this->assertSame('anwesend', $this->statusVon($this->ksEva));
        $this->assertSame(2, $this->fx->zaehle('SELECT COUNT(*) FROM anwesenheiten WHERE klausur_id = ?', [$this->klausur]));
    }

    public function testUnbekannterTokenAendertNichts(): void
    {
        $html = $this->ausgabe(fn () => AnwesenheitApi::alleDa(str_repeat('a', 64)));

        $this->assertStringContainsString('ungültig oder abgelaufen', $html);
        $this->assertSame(0, $this->fx->zaehle('SELECT COUNT(*) FROM anwesenheiten'));
    }

    // ------------------------------------------------------------------ Sicht der Schüler*innen

    public function testSchuelerSehenNurIhreEigenenKlausurenUndNachschreibtermine(): void
    {
        $zweite = $this->fx->klausur($this->kurs, null, null, 2);
        $this->fx->anwesenheit($this->klausur, $this->ksEva, 'fehlend');
        $this->db->exec("INSERT INTO nachschreibtermine (termin_datum, termin_uhrzeit, bemerkung) VALUES ('2026-12-01', '13:00', 'Raum 12')");
        $nt = (int) $this->db->lastInsertId();
        $this->db->prepare('INSERT INTO nachschreib_zuordnungen (klausur_id, nachschreibtermin_id) VALUES (?, ?)')->execute([$this->klausur, $nt]);

        $this->alsBenutzer($this->eva, ['schueler']);
        $klausuren = SchuelerApi::meineKlausuren();
        $this->assertSame([1, 2], array_map('intval', array_column($klausuren, 'klausur_nr')));
        $this->assertSame(['2026-10-06', null], array_column($klausuren, 'termin_datum'), 'datumslose zuletzt');
        $this->assertCount(1, SchuelerApi::meineNachschreibtermine());

        $this->alsBenutzer($this->max, ['schueler']);
        $this->assertCount(2, SchuelerApi::meineKlausuren());
        $this->assertSame([], SchuelerApi::meineNachschreibtermine(), 'Max hat nicht gefehlt');

        $ohne = $this->fx->benutzer('Ohne', 'Kurs', ['schueler']);
        $this->alsBenutzer($ohne, ['schueler']);
        $this->assertSame([], SchuelerApi::meineKlausuren());
        unset($zweite);
    }
}
