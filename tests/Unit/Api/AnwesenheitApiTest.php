<?php

declare(strict_types=1);

namespace Klausurplan\Tests\Unit\Api;

use Klausurplan\Api\AnwesenheitApi;
use Klausurplan\Tests\Support\FakeResult;
use Klausurplan\Tests\Support\TestCase;

final class AnwesenheitApiTest extends TestCase
{
    private const TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    // ------------------------------------------------------------------ Zugriff

    public function testSchuelerHabenKeinenZugriff(): void
    {
        $this->alsBenutzer(5, ['schueler']);

        $this->erwarteZugriffVerweigert(fn () => AnwesenheitApi::getAnwesenheit(1));
        $this->erwarteZugriffVerweigert(fn () => AnwesenheitApi::postAnwesenheit(1, []));
        $this->erwarteZugriffVerweigert(fn () => AnwesenheitApi::postEntschuldigung(1, []));
        $this->erwarteZugriffVerweigert(fn () => AnwesenheitApi::getNachschreibAnwesenheit(1));
        $this->erwarteZugriffVerweigert(fn () => AnwesenheitApi::postNachschreibAnwesenheit(1, []));
    }

    public function testLehrkraftSiehtNurEigeneKlausuren(): void
    {
        $this->alsBenutzer(5, ['lehrkraft']);

        $this->erwarteFehler(fn () => AnwesenheitApi::getAnwesenheit(1), 'Kein Zugriff auf diese Klausur', 403);
        $this->assertSame([1, 5], $this->db->log[0]['params']);
    }

    public function testLehrkraftMitEigenerKlausurDarfLesen(): void
    {
        $this->alsBenutzer(5, ['lehrkraft']);
        $this->db->onScalar('/SELECT 1 FROM klausuren kl\s+JOIN kurse k ON k\.id = kl\.kurs_id\s+WHERE kl\.id = \? AND k\.lehrer_id/', 1);
        $rows = [['kurs_schueler_id' => 1, 'status' => 'anwesend']];
        $this->db->onRows('/FROM kurs_schueler ks/', $rows);

        $this->assertSame($rows, AnwesenheitApi::getAnwesenheit(1));
    }

    public function testStufenleitungUndAdminSindNichtAufEigeneKlausurenBeschraenkt(): void
    {
        foreach (['stufenleitung', 'admin'] as $rolle) {
            $this->alsBenutzer(5, [$rolle]);
            $this->db->log = [];

            AnwesenheitApi::getAnwesenheit(1);

            $this->assertFalse($this->db->wurdeAusgefuehrt('/k\.lehrer_id = \?/'), $rolle);
        }
    }

    // ------------------------------------------------------------------ Eintragen

    private function eintragDaten(bool $vorhanden): void
    {
        $this->db->onScalar('/SELECT 1 FROM kurs_schueler ks\s+JOIN klausuren kl/', 1);
        $this->db->onScalar('/SELECT id FROM anwesenheiten WHERE klausur_id/', $vorhanden ? 55 : false);
    }

    public function testNeuerEintragOhneEntschuldigungsrecht(): void
    {
        $this->alsBenutzer(6, ['lehrkraft']);
        $this->eintragDaten(false);
        $this->db->onScalar('/SELECT 1 FROM klausuren kl\s+JOIN kurse k ON k\.id = kl\.kurs_id\s+WHERE kl\.id = \? AND k\.lehrer_id/', 1);

        $r = AnwesenheitApi::postAnwesenheit(1, [
            ['kurs_schueler_id' => 3, 'status' => 'fehlend', 'kommentar' => ' krank ', 'entschuldigt' => true],
        ]);

        $this->assertSame(['gespeichert' => 1], $r);
        $insert = $this->db->aufrufe('/^INSERT INTO anwesenheiten/')[0];
        $this->assertStringNotContainsString('entschuldigt', $insert['sql'], 'Lehrkräfte dürfen nicht entschuldigen');
        $this->assertSame([1, 3, 'fehlend', 'krank', 6], $insert['params']);
    }

    public function testNeuerEintragDurchStufenleitungMitEntschuldigung(): void
    {
        $this->alsBenutzer(5, ['stufenleitung']);
        $this->eintragDaten(false);

        AnwesenheitApi::postAnwesenheit(1, [['kurs_schueler_id' => 3, 'status' => 'fehlend', 'entschuldigt' => true]]);

        $insert = $this->db->aufrufe('/^INSERT INTO anwesenheiten/')[0];
        $this->assertStringContainsString('entschuldigt', $insert['sql']);
        $this->assertSame([1, 3, 'fehlend', null, 1, 5], $insert['params']);
    }

    public function testEntschuldigungKannAufOffenZurueckgesetztWerden(): void
    {
        $this->alsBenutzer(5, ['admin']);
        $this->eintragDaten(true);

        AnwesenheitApi::postAnwesenheit(1, [['kurs_schueler_id' => 3, 'status' => 'fehlend', 'entschuldigt' => null]]);

        $update = $this->db->aufrufe('/^UPDATE anwesenheiten/')[0];
        $this->assertSame(['fehlend', null, null, 5, 55], $update['params']);
    }

    public function testUnentschuldigtWirdAlsNullGespeichert(): void
    {
        $this->alsBenutzer(5, ['admin']);
        $this->eintragDaten(true);

        AnwesenheitApi::postAnwesenheit(1, [['kurs_schueler_id' => 3, 'status' => 'fehlend', 'entschuldigt' => false]]);

        $this->assertSame(['fehlend', null, 0, 5, 55], $this->db->aufrufe('/^UPDATE anwesenheiten/')[0]['params']);
    }

    public function testBestehenderEintragOhneEntschuldigungswertBehaeltDieEntschuldigung(): void
    {
        $this->alsBenutzer(5, ['stufenleitung']);
        $this->eintragDaten(true);

        AnwesenheitApi::postAnwesenheit(1, [['kurs_schueler_id' => 3, 'status' => 'anwesend']]);

        $update = $this->db->aufrufe('/^UPDATE anwesenheiten/')[0];
        $this->assertStringNotContainsString('entschuldigt', $update['sql']);
        $this->assertSame(['anwesend', null, 5, 55], $update['params']);
    }

    public function testBestehenderEintragWirdMitLehrkraftRechtenAktualisiert(): void
    {
        $this->alsBenutzer(6, ['lehrkraft']);
        $this->eintragDaten(true);
        $this->db->onScalar('/SELECT 1 FROM klausuren kl\s+JOIN kurse k ON k\.id = kl\.kurs_id\s+WHERE kl\.id = \? AND k\.lehrer_id/', 1);

        AnwesenheitApi::postAnwesenheit(1, [['kurs_schueler_id' => 3, 'status' => 'anwesend', 'entschuldigt' => true]]);

        $this->assertStringNotContainsString('entschuldigt', $this->db->aufrufe('/^UPDATE anwesenheiten/')[0]['sql']);
    }

    public function testUngueltigeEintraegeWerdenUebersprungen(): void
    {
        $this->alsBenutzer(5, ['admin']);
        $this->db->onScalar('/SELECT 1 FROM kurs_schueler ks\s+JOIN klausuren kl/', false); // gehört nicht zur Klausur

        $r = AnwesenheitApi::postAnwesenheit(1, [
            ['status' => 'anwesend'],                                   // keine kurs_schueler_id
            ['kurs_schueler_id' => 3, 'status' => 'vielleicht'],        // unbekannter Status
            ['kurs_schueler_id' => 4, 'status' => 'anwesend'],          // fremder Prüfling
        ]);

        $this->assertSame(['gespeichert' => 0], $r);
        $this->assertFalse($this->db->wurdeAusgefuehrt('/^(INSERT|UPDATE)/'));
    }

    // ------------------------------------------------------------------ Entschuldigung

    public function testEntschuldigungNurFuerAdminUndStufenleitung(): void
    {
        $this->alsBenutzer(6, ['lehrkraft']);

        $this->erwarteZugriffVerweigert(fn () => AnwesenheitApi::postEntschuldigung(1, ['entschuldigt' => true]));
    }

    public function testEntschuldigungFuerUnbekanntenEintrag(): void
    {
        $this->alsBenutzer(5, ['stufenleitung']);

        $this->erwarteFehler(fn () => AnwesenheitApi::postEntschuldigung(9, []), 'Anwesenheitseintrag 9 nicht gefunden', 404);
    }

    public function testEntschuldigungSpeichern(): void
    {
        $this->alsBenutzer(5, ['stufenleitung']);
        $this->db->onScalar('/SELECT id FROM anwesenheiten WHERE id/', 9);

        $r = AnwesenheitApi::postEntschuldigung(9, ['entschuldigt' => 1, 'kommentar' => ' Attest ']);

        $this->assertSame(['ok' => true], $r);
        $this->assertSame([true, 'Attest', 5, 9], $this->db->aufrufe('/^UPDATE anwesenheiten/')[0]['params']);
    }

    // ------------------------------------------------------------------ Nachschreib-Anwesenheit

    public function testNachschreibZugriffFuerLehrkraeftePrueftDieVerknuepfteKlausur(): void
    {
        $this->alsBenutzer(6, ['lehrkraft']);

        $this->erwarteFehler(fn () => AnwesenheitApi::getNachschreibAnwesenheit(4), 'Kein Zugriff auf diesen Nachschreibtermin', 403);

        $this->db->onScalar('/FROM nachschreib_zuordnungen nz\s+JOIN klausuren kl ON kl\.id = nz\.klausur_id\s+JOIN kurse k\s+ON k\.id\s+= kl\.kurs_id\s+WHERE nz\.nachschreibtermin_id = \? AND k\.lehrer_id/', 1);
        $rows = [['kurs_schueler_id' => 1]];
        $this->db->onRows('/FROM nachschreib_zuordnungen nz\s+JOIN klausuren kl ON kl\.id = nz\.klausur_id\s+JOIN kurse k\s+ON k\.id\s+= kl\.kurs_id\s+JOIN anwesenheiten a/', $rows);
        $this->assertSame($rows, AnwesenheitApi::getNachschreibAnwesenheit(4));
    }

    public function testNachschreibAnwesenheitLegtEintraegeAnUndAktualisiert(): void
    {
        $this->alsBenutzer(5, ['stufenleitung']);
        $this->db->on('/SELECT id FROM nachschreib_anwesenheiten WHERE/', fn (string $s, array $p) => $p[1] === 3
            ? FakeResult::scalar(70) : FakeResult::leer());

        $r = AnwesenheitApi::postNachschreibAnwesenheit(4, [
            ['kurs_schueler_id' => 3, 'status' => 'anwesend'],                    // vorhanden → Update
            ['kurs_schueler_id' => 4, 'status' => 'fehlend', 'kommentar' => 'x'], // neu → Insert
            ['kurs_schueler_id' => 5, 'status' => 'egal'],                        // ungültig
            ['status' => 'anwesend'],                                             // ohne ID
        ]);

        $this->assertSame(['gespeichert' => 2], $r);
        $this->assertSame(['anwesend', null, 5, 70], $this->db->aufrufe('/^UPDATE nachschreib_anwesenheiten/')[0]['params']);
        $this->assertSame([4, 4, 'fehlend', 'x', 5], $this->db->aufrufe('/^INSERT INTO nachschreib_anwesenheiten/')[0]['params']);
    }

    // ------------------------------------------------------------------ Token-Seiten

    private function tokenGueltig(int $klausurId = 12): void
    {
        $this->db->onRows('/FROM email_benachrichtigungen WHERE token/', [['id' => 3, 'klausur_id' => $klausurId, 'beantwortet_am' => null]]);
    }

    private function pruefllinge(): void
    {
        $this->db->onRows('/FROM kurs_schueler ks\s+JOIN klausuren kl ON kl\.kurs_id = ks\.kurs_id/', [
            ['id' => 1, 'name_roh' => 'Mustermann|Max', 'vorname' => 'Max', 'nachname' => 'Mustermann'],
            ['id' => 2, 'name_roh' => 'Unbekannt|Uwe', 'vorname' => null, 'nachname' => null],
        ]);
    }

    public function testUngueltigeTokenWerdenAbgewiesen(): void
    {
        foreach (['zu-kurz', str_repeat('G', 64), strtoupper(self::TOKEN)] as $token) {
            $html = $this->ausgabe(fn () => AnwesenheitApi::alleDa($token));
            $this->assertStringContainsString('Dieser Link ist ungültig', $html);
        }
        $this->assertFalse($this->db->wurdeAusgefuehrt('/./'), 'ungültige Token erreichen die Datenbank nicht');
    }

    public function testUnbekannterTokenWirdAbgewiesen(): void
    {
        $html = $this->ausgabe(fn () => AnwesenheitApi::eingabeSeite(self::TOKEN));

        $this->assertStringContainsString('ungültig oder abgelaufen', $html);
        $this->assertSame([self::TOKEN], $this->db->log[0]['params']);
    }

    public function testAlleDaTragtAlleAlsAnwesendEinUndMarkiertDieMailAlsBeantwortet(): void
    {
        $this->tokenGueltig();
        $this->pruefllinge();
        $this->db->onScalar('/SELECT id FROM anwesenheiten WHERE klausur_id/', 40); // Prüfling 1 hat schon einen Eintrag

        $html = $this->ausgabe(fn () => AnwesenheitApi::alleDa(self::TOKEN));

        $this->assertStringContainsString('Anwesenheit bestätigt', $html);
        $this->assertStringContainsString('<strong>anwesend</strong>', $html);
        $this->assertCount(2, $this->db->aufrufe('/^UPDATE anwesenheiten/'));
        $this->assertSame(['anwesend', null, 40], $this->db->aufrufe('/^UPDATE anwesenheiten/')[0]['params']);
        $this->assertSame([3], $this->db->aufrufe('/^UPDATE email_benachrichtigungen SET beantwortet_am/')[0]['params']);
    }

    public function testAlleDaLegtFehlendeEintraegeNeuAn(): void
    {
        $this->tokenGueltig();
        $this->pruefllinge();

        $this->ausgabe(fn () => AnwesenheitApi::alleDa(self::TOKEN));

        $inserts = $this->db->aufrufe('/^INSERT INTO anwesenheiten/');
        $this->assertSame([[12, 1, 'anwesend', null, null], [12, 2, 'anwesend', null, null]], array_column($inserts, 'params'));
    }

    public function testEingabeSeiteListetAllePruefllinge(): void
    {
        $this->tokenGueltig();
        $this->pruefllinge();
        $this->db->onRows('/FROM klausuren kl\s+JOIN kurse k ON k\.id = kl\.kurs_id\s+WHERE kl\.id = \?/', [
            ['termin_datum' => '2026-01-31', 'kurs_anzeigename' => 'Q2 <Sport> GK 1'],
        ]);

        $html = $this->ausgabe(fn () => AnwesenheitApi::eingabeSeite(self::TOKEN));

        $this->assertStringContainsString('Anwesenheit eintragen', $html);
        $this->assertStringContainsString('Q2 &lt;Sport&gt; GK 1', $html);
        $this->assertStringContainsString('31.01.2026', $html);
        $this->assertStringContainsString('Mustermann, Max', $html);
        $this->assertStringContainsString('Unbekannt, Uwe', $html, 'ungeklärte Namen aus GoMST werden lesbar dargestellt');
        $this->assertStringContainsString('name="fehlend[]" value="1"', $html);
        $this->assertStringContainsString('name="kommentar_2"', $html);
        $this->assertStringContainsString('action="/anwesenheit/token-eintrag"', $html);
        $this->assertStringContainsString('name="token" value="' . self::TOKEN . '"', $html);
    }

    public function testEingabeSeiteOhneDatum(): void
    {
        $this->tokenGueltig();
        $this->db->onRows('/FROM klausuren kl\s+JOIN kurse k ON k\.id = kl\.kurs_id\s+WHERE kl\.id = \?/', [
            ['termin_datum' => null, 'kurs_anzeigename' => 'X'],
        ]);

        $this->assertStringContainsString('kein Datum', $this->ausgabe(fn () => AnwesenheitApi::eingabeSeite(self::TOKEN)));
    }

    public function testEingabeSeiteZuGeloeschterKlausur(): void
    {
        $this->tokenGueltig();

        $this->assertStringContainsString('Klausur nicht gefunden', $this->ausgabe(fn () => AnwesenheitApi::eingabeSeite(self::TOKEN)));
    }

    public function testTokenEintragOhneToken(): void
    {
        $html = $this->ausgabe(fn () => AnwesenheitApi::tokenEintrag());

        $this->assertStringContainsString('Kein Token übermittelt', $html);
    }

    public function testTokenEintragSpeichertFehlendeUndAnwesende(): void
    {
        $this->tokenGueltig();
        $this->pruefllinge();
        $_POST = ['token' => self::TOKEN, 'fehlend' => ['2'], 'kommentar_2' => ' erkrankt ', 'kommentar_1' => ''];

        $html = $this->ausgabe(fn () => AnwesenheitApi::tokenEintrag());

        $this->assertStringContainsString('erfolgreich gespeichert', $html);
        $inserts = array_column($this->db->aufrufe('/^INSERT INTO anwesenheiten/'), 'params');
        $this->assertSame([[12, 1, 'anwesend', null, null], [12, 2, 'fehlend', 'erkrankt', null]], $inserts);
        $this->assertSame([3], $this->db->aufrufe('/^UPDATE email_benachrichtigungen SET beantwortet_am/')[0]['params']);
    }

    public function testTokenEintragMitUngueltigemToken(): void
    {
        $_POST = ['token' => 'abc'];

        $this->assertStringContainsString('Dieser Link ist ungültig', $this->ausgabe(fn () => AnwesenheitApi::tokenEintrag()));
        $this->assertFalse($this->db->wurdeAusgefuehrt('/^(INSERT|UPDATE)/'));
    }

    public function testTokenSeitenSindGueltigesHtmlMitEscapetemTitel(): void
    {
        $html = $this->ausgabe(fn () => AnwesenheitApi::alleDa('x'));

        $this->assertStringStartsWith('<!DOCTYPE html>', trim($html));
        $this->assertStringContainsString('<title>Ungültiger Link – Klausurplan</title>', $html);
        $this->assertStringContainsString('/assets/app.css', $html);
    }
}
