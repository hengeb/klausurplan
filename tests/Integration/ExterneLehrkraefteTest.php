<?php

declare(strict_types=1);

namespace Klausurplan\Tests\Integration;

use Klausurplan\Api\AdminApi;
use Klausurplan\Api\AnwesenheitApi;
use Klausurplan\Api\StufenleitungApi;
use Klausurplan\Tests\Support\IntegrationTestCase;
use Klausurplan\Tests\Support\MoodleApiMitNutzern;
use Klausurplan\Tests\Support\SmtpSenke;

/** Lehrkräfte ohne Moodle-Konto: verwalten, per Mail-Link Anwesenheit eintragen, vor Moodle-Sync geschützt. */
final class ExterneLehrkraefteTest extends IntegrationTestCase
{
    private array $altEnv;
    private int $sl;

    protected function setUp(): void
    {
        $this->altEnv = $_ENV;
        parent::setUp();
        $_ENV['MOODLE_URL'] = 'https://moodle.example';
        $_ENV['MOODLE_API_TOKEN'] = 'x';
        $this->sl = $this->fx->benutzer('Sarah', 'Leitung', ['stufenleitung']);
        $this->alsBenutzer($this->sl, ['stufenleitung']); // nur Stufenleitung, keine Admin-Rolle, keine Stufe
    }

    protected function tearDown(): void
    {
        $_ENV = $this->altEnv;
        parent::tearDown();
    }

    private function extern(array $abweichung = []): array
    {
        return $abweichung + ['vorname' => 'Ex', 'nachname' => 'Tern', 'kuerzel' => 'XT', 'email' => 'ex@schule2.example'];
    }

    public function testStufenleitungOhneAdminrolleLegtAnBearbeitetUndLoescht(): void
    {
        $r = StufenleitungApi::addExterneLehrkraft($this->extern());

        $zeile = $this->fx->zeilen('SELECT * FROM benutzer WHERE id = ?', [$r['id']])[0];
        $this->assertSame(1, (int) $zeile['extern']);
        $this->assertStringStartsWith('extern:', $zeile['moodle_id']);
        $this->assertSame('XT', $zeile['kuerzel']);
        $this->assertSame(1, $this->fx->zaehle("SELECT COUNT(*) FROM rollen WHERE benutzer_id = ? AND rolle = 'lehrkraft'", [$r['id']]));

        StufenleitungApi::updateExterneLehrkraft($r['id'], $this->extern(['nachname' => 'Neu', 'email' => 'neu@schule2.example']));
        $this->assertSame('Neu', $this->fx->wert('SELECT nachname FROM benutzer WHERE id = ?', [$r['id']]));

        StufenleitungApi::deleteExterneLehrkraft($r['id']);
        $this->assertSame(0, $this->fx->zaehle('SELECT COUNT(*) FROM benutzer WHERE id = ?', [$r['id']]));
        $this->assertSame(0, $this->fx->zaehle('SELECT COUNT(*) FROM rollen WHERE benutzer_id = ?', [$r['id']]), 'Rollen kaskadieren');
    }

    public function testAnlegenOrdnetKurseMitDemKuerzelSofortZu(): void
    {
        $hj = $this->fx->halbjahr($this->fx->stufe('Q2'));
        $kurs = $this->fx->kurs($hj, 'D_Q2_GK1_XT', null, 'XT');

        $r = StufenleitungApi::addExterneLehrkraft($this->extern());

        $this->assertSame(1, $r['zugeordnete_kurse']);
        $this->assertSame($r['id'], (int) $this->fx->wert('SELECT lehrer_id FROM kurse WHERE id = ?', [$kurs]));
        $this->assertSame([$r['id']], array_map('intval', array_column(StufenleitungApi::getZuordnungen()['externe_lehrkraefte'], 'id')));
        $this->assertSame(1, (int) StufenleitungApi::getZuordnungen()['externe_lehrkraefte'][0]['anzahl_kurse']);
    }

    public function testLoeschenLaesstKurseOhneLehrkraftZurueck(): void
    {
        $hj = $this->fx->halbjahr($this->fx->stufe('Q2'));
        $kurs = $this->fx->kurs($hj, 'D_Q2_GK1_XT', null, 'XT');
        $r = StufenleitungApi::addExterneLehrkraft($this->extern());

        StufenleitungApi::deleteExterneLehrkraft($r['id']);

        $this->assertNull($this->fx->wert('SELECT lehrer_id FROM kurse WHERE id = ?', [$kurs]));
        $this->assertSame(0, $this->fx->zaehle('SELECT COUNT(*) FROM lehrer_zuordnungen'), 'Zuordnung kaskadiert');
    }

    public function testDoppeltesKuerzelWirdAbgelehnt(): void
    {
        $this->fx->benutzer('Anna', 'Lehrer (SZ)', ['lehrkraft'], 'SZ');

        $this->erwarteFehler(fn () => StufenleitungApi::addExterneLehrkraft($this->extern(['kuerzel' => 'sz'])), 'bereits von einer anderen Person', 409);
        StufenleitungApi::addExterneLehrkraft($this->extern());
        $this->erwarteFehler(fn () => StufenleitungApi::addExterneLehrkraft($this->extern(['vorname' => 'Zwei'])), 'bereits', 409);
    }

    public function testMoodleKontenSindUeberDieExternApiGeschuetzt(): void
    {
        $anna = $this->fx->benutzer('Anna', 'Lehrer', ['lehrkraft'], 'SZ');

        $this->erwarteFehler(fn () => StufenleitungApi::updateExterneLehrkraft($anna, $this->extern()), 'nicht gefunden', 404);
        $this->erwarteFehler(fn () => StufenleitungApi::deleteExterneLehrkraft($anna), 'nicht gefunden', 404);
        $this->assertSame(1, $this->fx->zaehle('SELECT COUNT(*) FROM benutzer WHERE id = ?', [$anna]));
    }

    public function testMoodleSyncLoeschtExterneNichtUndVerdraengtIhrKuerzelNicht(): void
    {
        $r = StufenleitungApi::addExterneLehrkraft($this->extern());
        $gone = $this->fx->benutzer('Weg', 'Gegangen'); // wird von Moodle nicht mehr geliefert

        $ergebnis = (new MoodleApiMitNutzern([
            ['id' => 900, 'firstname' => 'Neu', 'lastname' => 'Kollege (XT)', 'email' => 'neu@x.de',
             'customfields' => [['shortname' => 'klasse', 'value' => 'Lehrkraft']]],
        ]))->sync();

        $this->assertSame(1, $this->fx->zaehle('SELECT COUNT(*) FROM benutzer WHERE id = ?', [$r['id']]), 'externe Lehrkraft bleibt');
        $this->assertSame('XT', $this->fx->wert('SELECT kuerzel FROM benutzer WHERE id = ?', [$r['id']]), 'Kürzel bleibt erhalten');
        $this->assertSame(0, $this->fx->zaehle('SELECT COUNT(*) FROM benutzer WHERE id = ?', [$gone]), 'veraltete Moodle-Konten werden weiter gelöscht');
        $this->assertSame(1, $ergebnis['neu']);
    }

    public function testAdminlisteKenntzeichnetExterne(): void
    {
        $r = StufenleitungApi::addExterneLehrkraft($this->extern());
        $this->alsBenutzer(1, ['admin']);

        $liste = array_column(AdminApi::getBenutzer(), null, 'id');

        $this->assertSame(1, (int) $liste[$r['id']]['extern']);
        $this->assertSame(['lehrkraft'], $liste[$r['id']]['rollen']);
    }

    public function testLehrkraftAuswahlUndKurszeilenNennenExterneKennzeichen(): void
    {
        $r = StufenleitungApi::addExterneLehrkraft($this->extern());
        $hj = $this->fx->halbjahr($this->fx->stufe('Q2'));

        $kurs = StufenleitungApi::addKurs($hj, ['bezeichnung' => 'D_Q2_GK1_XT', 'lehrer_id' => $r['id']]);

        $this->assertSame(1, $kurs['lehrer_extern']);
        $this->assertContains($r['id'], array_map(fn ($l) => (int) $l['id'], array_filter(StufenleitungApi::getLehrkraefte(), fn ($l) => (int) $l['extern'] === 1)));
        $this->assertSame(1, (int) StufenleitungApi::getKurse($hj)[0]['lehrer_extern']);
    }

    // ------------------------------------------------------------------ Mail und Token-Link

    public function testAnwesenheitsMailGehtAnDieExterneLehrkraftUndDerLinkFunktioniertOhneLogin(): void
    {
        $senke = new SmtpSenke();
        foreach ($senke->umgebung() as $k => $v) {
            $_ENV[$k] = $v;
        }
        $r = StufenleitungApi::addExterneLehrkraft($this->extern());
        $stufe = $this->fx->stufe('Q2');
        $kurs = $this->fx->kurs($this->fx->halbjahr($stufe), 'D_Q2_GK1_XT', $r['id'], 'XT', 'GK', 'Q2 Deutsch GK 1 XT');
        $this->fx->kursSchueler($kurs, 'Mustermann|Max');
        $this->fx->kursSchueler($kurs, 'Muster|Maria');
        $klausur = $this->fx->klausur($kurs, '2026-01-31', '08:00');

        $antwort = StufenleitungApi::emailAusloesen($klausur);

        $this->assertSame(['gesendet' => true, 'empfaenger' => 'ex@schule2.example'], $antwort);
        $mails = $senke->mails();
        $this->assertCount(1, $mails);
        $this->assertStringContainsString('ex@schule2.example', $mails[0]['an']);
        $this->assertSame('Anwesenheit Klausur Q2 Deutsch GK 1 XT am 31.01.2026', $mails[0]['betreff']);

        // Token-Link (ohne Anmeldung): alle anwesend
        $token = (string) $this->fx->wert('SELECT token FROM email_benachrichtigungen WHERE klausur_id = ?', [$klausur]);
        $this->assertStringContainsString("token={$token}", $mails[0]['text']);
        $_SESSION = [];
        $html = $this->ausgabe(fn () => AnwesenheitApi::alleDa($token));

        $this->assertStringContainsString('Anwesenheit bestätigt', $html);
        $this->assertSame(2, $this->fx->zaehle("SELECT COUNT(*) FROM anwesenheiten WHERE klausur_id = ? AND status = 'anwesend'", [$klausur]));
        $this->assertNotNull($this->fx->wert('SELECT beantwortet_am FROM email_benachrichtigungen WHERE token = ?', [$token]));
    }
}
