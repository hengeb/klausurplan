<?php

declare(strict_types=1);

namespace Klausurplan\Tests\Unit\Auth;

use Klausurplan\Auth\MoodleApi;
use Klausurplan\Tests\Support\FakeResult;
use Klausurplan\Tests\Support\MoodleApiMitNutzern;
use Klausurplan\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;

/** MoodleApi mit vorgegebenen HTTP-Antworten. */
final class MoodleApiMitAntworten extends MoodleApi
{
    /** @var list<string> */
    public array $urls = [];

    /** @param array<string, array> $antworten Antwort je Wert von criteria[0][value] */
    public function __construct(private readonly array $antworten)
    {
        parent::__construct();
    }

    protected function get(string $url): array
    {
        $this->urls[] = $url;
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        return $this->antworten[$q['criteria']['0']['value']];
    }

    public function nutzer(): array
    {
        return $this->alleNutzer();
    }
}

final class MoodleApiTest extends TestCase
{
    private array $altEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->altEnv = $_ENV;
        $_ENV['MOODLE_URL']       = 'https://moodle.example/';
        $_ENV['MOODLE_API_TOKEN'] = 'geheim';
    }

    protected function tearDown(): void
    {
        $_ENV = $this->altEnv;
        parent::tearDown();
    }

    private function lehrkraft(int $id, string $vor, string $nach, string $email = 'lehrer@example.org'): array
    {
        return ['id' => $id, 'firstname' => $vor, 'lastname' => $nach, 'email' => $email,
                'customfields' => [['shortname' => 'klasse', 'value' => 'Lehrkraft']]];
    }

    private function schueler(int $id, string $vor, string $nach, string $klasse = 'Q2 Kurs 3'): array
    {
        return ['id' => $id, 'firstname' => $vor, 'lastname' => $nach, 'email' => 'privat@example.org',
                'customfields' => [['shortname' => 'klasse', 'value' => $klasse]]];
    }

    // ------------------------------------------------------------------ Konfiguration

    public function testOhneKonfigurationWirdNichtGearbeitet(): void
    {
        unset($_ENV['MOODLE_API_TOKEN']);

        $this->erwarteFehler(fn () => new MoodleApi(), 'MOODLE_URL oder MOODLE_API_TOKEN nicht konfiguriert');
    }

    // ------------------------------------------------------------------ Hilfsfunktionen

    /** @return array<string, array{string, ?string}> */
    public static function kuerzelFaelle(): array
    {
        return [
            'Klammer am Ende'       => ['Gebauer (SZ)', 'SZ'],
            'Kleinbuchstaben'       => ['Meier (ab)', 'ab'],
            'Umlaute'               => ['Öztürk (ÄÖ)', 'ÄÖ'],
            'Leerraum außen'        => ['  Gebauer (GB)  ', 'GB'],
            'kein Kürzel'           => ['Gebauer', null],
            'Klammer nicht am Ende' => ['Gebauer (SZ) Jr.', null],
            'Ziffern'               => ['Gebauer (Q2)', null],
            'zu lang (>10)'         => ['Gebauer (ABCDEFGHIJK)', null],
            'leer'                  => ['', null],
        ];
    }

    #[DataProvider('kuerzelFaelle')]
    public function testExtraktKuerzel(string $nachname, ?string $erwartet): void
    {
        $this->assertSame($erwartet, MoodleApi::extraktKuerzel($nachname));
    }

    private function privat(string $methode, array $arg): mixed
    {
        return (new ReflectionMethod(MoodleApi::class, $methode))->invoke(null, $arg);
    }

    public function testIstLehrkraft(): void
    {
        $this->assertTrue($this->privat('istLehrkraft', $this->lehrkraft(1, 'A', 'B')));
        $this->assertFalse($this->privat('istLehrkraft', $this->schueler(1, 'A', 'B')));
        $this->assertFalse($this->privat('istLehrkraft', ['customfields' => []]));
        $this->assertFalse($this->privat('istLehrkraft', []));
    }

    /** @return array<string, array{array, ?string}> */
    public static function stufenFaelle(): array
    {
        $klasse = static fn (string $v): array => ['customfields' => [['shortname' => 'klasse', 'value' => $v]]];
        return [
            'Q1 mit Kurs'   => [$klasse('Q1 Kurs 3'), 'Q1'],
            'nur Stufe'     => [$klasse('EF'), 'EF'],
            'Klassenstufe'  => [$klasse('9a'), '9a'],
            'leer'          => [$klasse('  '), null],
            'Sonderzeichen' => [$klasse('-x'), null],
            'anderes Feld'  => [['customfields' => [['shortname' => 'x', 'value' => 'Q1']]], null],
            'keine Felder'  => [[], null],
        ];
    }

    #[DataProvider('stufenFaelle')]
    public function testExtraktStufe(array $nutzer, ?string $erwartet): void
    {
        $this->assertSame($erwartet, $this->privat('extraktStufe', $nutzer));
    }

    // ------------------------------------------------------------------ Abruf

    public function testAlleNutzerFragtLdapUndManualAbUndDedupliziertNachId(): void
    {
        $api = new MoodleApiMitAntworten([
            'ldap'   => ['users' => [['id' => 1, 'firstname' => 'A'], ['id' => 2, 'firstname' => 'B']]],
            'manual' => ['users' => [['id' => 2, 'firstname' => 'B2'], ['id' => 3, 'firstname' => 'C']]],
        ]);

        $nutzer = $api->nutzer();

        $this->assertSame([1, 2, 3], array_column($nutzer, 'id'));
        $this->assertCount(2, $api->urls);
        $this->assertStringStartsWith('https://moodle.example/webservice/rest/server.php?', $api->urls[0]);
        $this->assertStringContainsString('wstoken=geheim', $api->urls[0]);
        $this->assertStringContainsString('wsfunction=core_user_get_users', $api->urls[0]);
    }

    public function testAlleNutzerMeldetMoodleFehler(): void
    {
        $api = new MoodleApiMitAntworten([
            'ldap' => ['exception' => 'webservice_access_exception', 'message' => 'Zugriff verweigert'],
            'manual' => ['users' => []],
        ]);

        $this->erwarteFehler(fn () => $api->nutzer(), 'Moodle API: Zugriff verweigert');
    }

    public function testAlleNutzerOhneUsersFeld(): void
    {
        $api = new MoodleApiMitAntworten(['ldap' => [], 'manual' => []]);

        $this->assertSame([], $api->nutzer());
    }

    public function testGetMeldetUnerreichbareServer(): void
    {
        $_ENV['MOODLE_URL'] = 'http://127.0.0.1:1';
        $api = new class extends MoodleApi {
            public function abruf(): array
            {
                return $this->get('http://127.0.0.1:1/x');
            }
        };

        $this->erwarteFehler(fn () => $api->abruf(), 'Moodle API nicht erreichbar');
    }

    // ------------------------------------------------------------------ Synchronisation

    /** DB-Verhalten: vorhandene Benutzer per moodle_id, ansonsten neu anlegen. */
    private function datenbankMitBenutzern(array $vorhanden): void
    {
        $this->db->on('/SELECT id, vorname, nachname, email, kuerzel, stufe FROM benutzer WHERE moodle_id/',
            fn (string $sql, array $p) => isset($vorhanden[$p[0]]) ? FakeResult::rows([$vorhanden[$p[0]]]) : FakeResult::leer());
    }

    public function testSyncLegtNeueLehrkraefteUndSchuelerAnMitBasisrolle(): void
    {
        $this->datenbankMitBenutzern([]);
        $this->db->on('/^INSERT INTO benutzer/', FakeResult::insert(41));

        $ergebnis = (new MoodleApiMitNutzern([
            $this->lehrkraft(10, 'Anna', 'Gebauer (SZ)'),
            $this->schueler(11, 'Max', 'Mustermann'),
        ]))->sync();

        $this->assertSame(['neu' => 2, 'aktualisiert' => 0, 'geloescht' => 0, 'gesamt' => 2], $ergebnis);

        [$lehrer, $schueler] = $this->db->aufrufe('/^INSERT INTO benutzer/');
        // moodle_id, vorname, nachname, email, kuerzel, stufe
        $this->assertSame(['10', 'Anna', 'Gebauer (SZ)', 'lehrer@example.org', 'SZ', null], $lehrer['params']);
        $this->assertSame(['11', 'Max', 'Mustermann', null, null, 'Q2'], $schueler['params'], 'Schüler: keine E-Mail, kein Kürzel, aber Stufe');

        $rollen = array_column($this->db->aufrufe('/INSERT IGNORE INTO rollen/'), 'params');
        $this->assertSame([[41, 'lehrkraft'], [41, 'schueler']], $rollen);
    }

    public function testSyncUeberspringtNutzerOhneNamen(): void
    {
        $this->datenbankMitBenutzern([]);

        $ergebnis = (new MoodleApiMitNutzern([
            $this->schueler(1, '', 'Nachname'),
            $this->schueler(2, 'Vorname', ''),
        ]))->sync();

        $this->assertSame(0, $ergebnis['neu']);
        $this->assertSame(2, $ergebnis['gesamt']);
        $this->assertFalse($this->db->wurdeAusgefuehrt('/^INSERT INTO benutzer/'));
        $this->assertSame([], $this->db->aufrufe('/DELETE FROM benutzer/'), 'ohne gesehene Nutzer wird nichts gelöscht');
    }

    public function testSyncAktualisiertGeaenderteNutzer(): void
    {
        $this->datenbankMitBenutzern(['10' => [
            'id' => 5, 'vorname' => 'Anna', 'nachname' => 'Alt', 'email' => 'alt@example.org', 'kuerzel' => 'SZ', 'stufe' => null,
        ]]);

        $ergebnis = (new MoodleApiMitNutzern([$this->lehrkraft(10, 'Anna', 'Gebauer (SZ)')]))->sync();

        $this->assertSame(1, $ergebnis['aktualisiert']);
        $update = $this->db->aufrufe('/^UPDATE benutzer/')[0];
        $this->assertSame(['Anna', 'Gebauer (SZ)', 'lehrer@example.org', 'SZ', 'SZ', null, '10'], $update['params']);
    }

    public function testSyncLaesstUnveraenderteNutzerInRuhe(): void
    {
        $this->datenbankMitBenutzern(['11' => [
            'id' => 6, 'vorname' => 'Max', 'nachname' => 'Mustermann', 'email' => null, 'kuerzel' => null, 'stufe' => 'Q2',
        ]]);

        $ergebnis = (new MoodleApiMitNutzern([$this->schueler(11, 'Max', 'Mustermann')]))->sync();

        $this->assertSame(0, $ergebnis['aktualisiert']);
        $this->assertFalse($this->db->wurdeAusgefuehrt('/^UPDATE benutzer/'));
        $this->assertFalse($this->db->wurdeAusgefuehrt('/rollen/'), 'Schüler-Rollen werden nicht angefasst');
    }

    public function testSyncKorrigiertFaelschlichAlsSchuelerAngelegteLehrkraefte(): void
    {
        $this->datenbankMitBenutzern(['10' => [
            'id' => 5, 'vorname' => 'Anna', 'nachname' => 'Gebauer (SZ)', 'email' => 'lehrer@example.org', 'kuerzel' => 'SZ', 'stufe' => null,
        ]]);

        (new MoodleApiMitNutzern([$this->lehrkraft(10, 'Anna', 'Gebauer (SZ)')]))->sync();

        $this->assertSame([[5, 'lehrkraft']], array_column($this->db->aufrufe('/INSERT IGNORE INTO rollen/'), 'params'));
        $this->assertSame([[5, 'schueler']], array_column($this->db->aufrufe('/^DELETE FROM rollen/'), 'params'));
    }

    public function testSyncLoeschtVeralteteNutzerNurWennSieNichtReferenziertSindUndKeineExternenLehrkraefte(): void
    {
        $this->datenbankMitBenutzern([]);
        $this->db->on('/^DELETE FROM benutzer/', FakeResult::count(3));

        $ergebnis = (new MoodleApiMitNutzern([$this->schueler(11, 'Max', 'Mustermann')]))->sync();

        $this->assertSame(3, $ergebnis['geloescht']);
        $loeschen = $this->db->aufrufe('/^DELETE FROM benutzer/')[0];
        $this->assertSame(['11'], $loeschen['params'], 'nur Nutzer, die Moodle nicht mehr liefert');
        $this->assertStringContainsString('extern = 0', $loeschen['sql'], 'externe Lehrkräfte sind geschützt');
        $this->assertStringContainsString('kurs_schueler', $loeschen['sql']);
        $this->assertStringContainsString('FROM kurse', $loeschen['sql']);
    }

    public function testSyncBereinigtDoppelteKuerzel(): void
    {
        $this->datenbankMitBenutzern([]);
        $this->db->onRows('/HAVING COUNT/', [['kuerzel' => 'SZ']]);
        $this->db->onRows('/WHERE kuerzel = \?/', [['id' => 9], ['id' => 4], ['id' => 2]]);

        (new MoodleApiMitNutzern([]))->sync();

        $update = $this->db->aufrufe('/SET kuerzel = NULL/')[0];
        $this->assertSame([4, 2], $update['params'], 'die Person mit der größten Moodle-ID (zuerst geliefert) behält das Kürzel');
        $this->assertStringContainsString('extern = 0', $this->db->aufrufe('/HAVING COUNT/')[0]['sql']);
    }
}
