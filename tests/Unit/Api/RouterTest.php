<?php

declare(strict_types=1);

namespace Klausurplan\Tests\Unit\Api;

use Klausurplan\Api\Router;
use Klausurplan\Tests\Support\TestCase;
use RuntimeException;

final class RouterTest extends TestCase
{
    /** @return array{0: mixed, 1: int} dekodierte Antwort und HTTP-Status */
    private function dispatche(string $methode, string $uri, callable $registrieren): array
    {
        $_SERVER['REQUEST_METHOD'] = $methode;
        $_SERVER['REQUEST_URI']    = $uri;
        $router = new Router();
        $registrieren($router);

        $json = $this->ausgabe(fn () => $router->dispatch());
        return [json_decode($json, true), http_response_code()];
    }

    public function testLiefertDieAntwortDesHandlersAlsJson(): void
    {
        [$antwort, $status] = $this->dispatche('GET', '/api/ping', function (Router $r): void {
            $r->get('/ping', fn (): array => ['ok' => true, 'text' => 'Grüße']);
        });

        $this->assertSame(200, $status);
        $this->assertSame(['ok' => true, 'text' => 'Grüße'], $antwort);
    }

    public function testPfadParameterWerdenUebergeben(): void
    {
        [$antwort] = $this->dispatche('GET', '/api/klausuren/42/anwesenheit/7?x=1', function (Router $r): void {
            $r->get('/klausuren/{id}/anwesenheit/{schueler_id}', fn (array $p): array => $p);
        });

        $this->assertSame(['id' => '42', 'schueler_id' => '7'], $antwort);
    }

    public function testAlleHttpMethodenWerdenUnterschieden(): void
    {
        foreach (['GET' => 'get', 'POST' => 'post', 'PUT' => 'put', 'DELETE' => 'delete'] as $methode => $registrierung) {
            [$antwort] = $this->dispatche($methode, '/api/x', function (Router $r) use ($registrierung, $methode): void {
                foreach (['get', 'post', 'put', 'delete'] as $m) {
                    $r->$m('/x', fn (): array => ['methode' => $m]);
                }
            });
            $this->assertSame(['methode' => $registrierung], $antwort, $methode);
        }
    }

    public function testFesteRouteVorParameterRoute(): void
    {
        [$antwort] = $this->dispatche('GET', '/api/klausuren/vorlage', function (Router $r): void {
            $r->get('/klausuren/vorlage', fn (): array => ['route' => 'vorlage']);
            $r->get('/klausuren/{id}', fn (): array => ['route' => 'id']);
        });

        $this->assertSame(['route' => 'vorlage'], $antwort);
    }

    public function testUnbekannterPfadLiefert404(): void
    {
        [$antwort, $status] = $this->dispatche('GET', '/api/gibt-es-nicht', function (Router $r): void {
            $r->get('/ping', fn (): array => []);
        });

        $this->assertSame(404, $status);
        $this->assertSame('Endpunkt nicht gefunden', $antwort['fehler']);
        $this->assertSame('/gibt-es-nicht', $antwort['pfad']);
    }

    public function testFalscheMethodeLiefert404(): void
    {
        [, $status] = $this->dispatche('POST', '/api/ping', function (Router $r): void {
            $r->get('/ping', fn (): array => []);
        });

        $this->assertSame(404, $status);
    }

    public function testRouteMussVollstaendigPassen(): void
    {
        [, $status] = $this->dispatche('GET', '/api/ping/zu-viel', function (Router $r): void {
            $r->get('/ping', fn (): array => []);
        });

        $this->assertSame(404, $status);
    }

    public function testPfadOhneApiPraefix(): void
    {
        [$antwort] = $this->dispatche('GET', '/ping', function (Router $r): void {
            $r->get('/ping', fn (): array => ['ok' => 1]);
        });

        $this->assertSame(['ok' => 1], $antwort);
    }

    public function testNurApiPraefixIstWurzel(): void
    {
        [$antwort] = $this->dispatche('GET', '/api', function (Router $r): void {
            $r->get('/', fn (): array => ['wurzel' => true]);
        });

        $this->assertSame(['wurzel' => true], $antwort);
    }

    public function testRollenpruefungVerweigertOhneRolle(): void
    {
        $this->alsBenutzer(1, ['schueler']);

        [$antwort, $status] = $this->dispatche('GET', '/api/admin', function (Router $r): void {
            $r->get('/admin', fn (): array => ['geheim' => true], 'admin');
        });

        $this->assertSame(403, $status);
        $this->assertSame(['fehler' => 'Keine Berechtigung'], $antwort);
    }

    public function testRollenpruefungLaesstBerechtigteDurch(): void
    {
        $this->alsBenutzer(1, ['stufenleitung']);

        [$antwort] = $this->dispatche('GET', '/api/sl', function (Router $r): void {
            $r->get('/sl', fn (): array => ['ok' => 1], 'admin', 'stufenleitung');
        });

        $this->assertSame(['ok' => 1], $antwort);
    }

    public function testRouteOhneRollenIstOhneAnmeldungErreichbar(): void
    {
        [$antwort] = $this->dispatche('GET', '/api/offen', function (Router $r): void {
            $r->get('/offen', fn (): array => ['ok' => 1]);
        });

        $this->assertSame(['ok' => 1], $antwort);
    }

    public function testRuntimeExceptionWirdZu400MitMeldung(): void
    {
        [$antwort, $status] = $this->dispatche('POST', '/api/x', function (Router $r): void {
            $r->post('/x', function (): array {
                throw new RuntimeException('Das ging schief.');
            });
        });

        $this->assertSame(400, $status);
        $this->assertSame(['fehler' => 'Das ging schief.'], $antwort);
    }

    public function testDerVomHandlerGesetzteFehlerstatusBleibtBeiRuntimeExceptionErhalten(): void
    {
        foreach ([403, 404, 409, 422] as $gesetzt) {
            [$antwort, $status] = $this->dispatche('GET', '/api/x', function (Router $r) use ($gesetzt): void {
                $r->get('/x', function () use ($gesetzt): array {
                    http_response_code($gesetzt);
                    throw new RuntimeException('nicht möglich');
                });
            });

            $this->assertSame($gesetzt, $status);
            $this->assertSame(['fehler' => 'nicht möglich'], $antwort);
        }
    }

    public function testUnerwarteteFehlerVerratenKeineDetails(): void
    {
        // Die Details gehen ausschließlich in das Error-Log (PHPUnit fängt es als Testausgabe ab)
        $this->expectOutputRegex('/API-Fehler: geheime Interna/');

        [$antwort, $status] = $this->dispatche('GET', '/api/x', function (Router $r): void {
            $r->get('/x', function (): array {
                throw new \LogicException('geheime Interna: Passwort=xyz');
            });
        });

        $this->assertSame(500, $status);
        $this->assertSame(['fehler' => 'Interner Serverfehler'], $antwort);
    }

    public function testNichtSerialisierbaresErgebnisLiefert500(): void
    {
        [$antwort, $status] = $this->dispatche('GET', '/api/x', function (Router $r): void {
            $r->get('/x', fn (): array => ['kaputt' => NAN]);
        });

        $this->assertSame(500, $status);
        $this->assertSame('JSON-Serialisierungsfehler', $antwort['fehler']);
    }

    public function testJsonBodyOhneEingabeIstLeeresArray(): void
    {
        $this->assertSame([], Router::jsonBody());
        $this->assertSame([], Router::jsonBody(''));
        $this->assertSame([], Router::jsonBody('null'));
    }

    public function testJsonBodyWirdDekodiert(): void
    {
        $this->assertSame(['a' => 1, 'b' => ['x', 'ü']], Router::jsonBody('{"a":1,"b":["x","ü"]}'));
        $this->assertSame([[1], [2]], Router::jsonBody('[[1],[2]]'), 'auch Listen (z.B. Excel-Import)');
    }

    /** @return array<string, array{string}> */
    public static function ungueltigeBodies(): array
    {
        return ['kaputt' => ['{"a":'], 'Text' => ['hallo'], 'Zahl' => ['5'], 'Zeichenkette' => ['"x"'], 'true' => ['true']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ungueltigeBodies')]
    public function testUngueltigerJsonBodyBeendetMit400(string $roh): void
    {
        $json = $this->ausgabe(fn () => Router::jsonBody($roh));

        $this->assertSame(400, http_response_code());
        $this->assertSame(['fehler' => 'Ungültiger JSON-Body'], json_decode($json, true));
    }
}
