<?php

declare(strict_types=1);

use Klausurplan\Auth\Session;
use Klausurplan\Api\Router;
use Klausurplan\Api\MeController;
use Klausurplan\Api\AdminApi;
use Klausurplan\Api\StufenleitungApi;
use Klausurplan\Api\LehrkraftApi;
use Klausurplan\Api\AnwesenheitApi;
use Klausurplan\Api\SchuelerApi;

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; font-src 'self'; frame-ancestors 'self' https://moodle.gymnasium-broich.de");

Session::start();

$router = new Router();

// ------------------------------------------------------------------
// Session
// ------------------------------------------------------------------
$router->get('/me', function (): array {
    Session::requireAuth();
    return MeController::handle();
});

// ------------------------------------------------------------------
// Admin – Benutzer*innen
// ------------------------------------------------------------------
$router->get('/admin/benutzer', function (): array {
    return AdminApi::getBenutzer();
}, 'admin');

$router->post('/admin/benutzer/{id}/rollen', function (array $p): array {
    $body   = Router::jsonBody();
    $rollen = $body['rollen'] ?? [];
    if (!is_array($rollen)) {
        http_response_code(400);
        return ['fehler' => 'rollen muss ein Array sein'];
    }
    return AdminApi::setRollen((int) $p['id'], $rollen);
}, 'admin');

// ------------------------------------------------------------------
// Admin – Moodle-Sync
// ------------------------------------------------------------------
$router->post('/admin/moodle-sync', function (): array {
    return AdminApi::moodleSync();
}, 'admin');

// ------------------------------------------------------------------
// Admin – Fächerbezeichnungen
// ------------------------------------------------------------------
$router->get('/admin/faecher', function (): array {
    return AdminApi::getFaecher();
}, 'admin');

$router->post('/admin/faecher', function (): array {
    $body = Router::jsonBody();
    $kuerzel     = trim($body['kuerzel']     ?? '');
    $bezeichnung = trim($body['bezeichnung'] ?? '');
    if (empty($kuerzel) || empty($bezeichnung)) {
        http_response_code(400);
        return ['fehler' => 'kuerzel und bezeichnung erforderlich'];
    }
    return AdminApi::updateFach($kuerzel, $bezeichnung);
}, 'admin');

$router->put('/admin/faecher/{kuerzel}', function (array $p): array {
    $body = Router::jsonBody();
    $bezeichnung = trim($body['bezeichnung'] ?? '');
    if (empty($bezeichnung)) {
        http_response_code(400);
        return ['fehler' => 'bezeichnung fehlt'];
    }
    return AdminApi::updateFach($p['kuerzel'], $bezeichnung);
}, 'admin');

$router->delete('/admin/faecher/{kuerzel}', function (array $p): array {
    return AdminApi::deleteFach($p['kuerzel']);
}, 'admin');

$router->get('/admin/stufen', function (): array {
    return AdminApi::getStufen();
}, 'admin');

$router->get('/admin/benutzer/{id}/stufenleitungen', function (array $p): array {
    return AdminApi::getStufenleitungen((int) $p['id']);
}, 'admin');

$router->post('/admin/benutzer/{id}/stufenleitungen', function (array $p): array {
    $body      = Router::jsonBody();
    $stufenIds = $body['stufen_ids'] ?? [];
    if (!is_array($stufenIds)) {
        http_response_code(400);
        return ['fehler' => 'stufen_ids muss ein Array sein'];
    }
    return AdminApi::setStufenleitungen((int) $p['id'], $stufenIds);
}, 'admin');

// ------------------------------------------------------------------
// Stufenleitung – GoMST-Import
// ------------------------------------------------------------------
// Datei-Upload: multipart/form-data, Feld "datei"
$router->post('/stufenleitung/gomst-import', function (): array {
    return StufenleitungApi::gomstImport();
}, 'admin', 'stufenleitung');

// ------------------------------------------------------------------
// Stufenleitung – Zuordnungen
// ------------------------------------------------------------------
$router->get('/stufenleitung/zuordnungen', function (): array {
    return StufenleitungApi::getZuordnungen();
}, 'admin', 'stufenleitung');

$router->post('/stufenleitung/zuordnungen', function (): array {
    $body = Router::jsonBody();
    return StufenleitungApi::postZuordnung($body);
}, 'admin', 'stufenleitung');

// ------------------------------------------------------------------
// Stufenleitung – Halbjahre & Kurse
// ------------------------------------------------------------------
$router->get('/stufenleitung/lehrkraefte', function (): array {
    return StufenleitungApi::getLehrkraefte();
}, 'admin', 'stufenleitung');

$router->get('/stufenleitung/halbjahre', function (): array {
    return StufenleitungApi::getHalbjahre();
}, 'admin', 'stufenleitung');

$router->get('/stufenleitung/halbjahr-vorschlag', function (): array {
    return StufenleitungApi::getHalbjahrVorschlag();
}, 'admin', 'stufenleitung');

$router->post('/stufenleitung/halbjahre', function (): array {
    return StufenleitungApi::addHalbjahr(Router::jsonBody());
}, 'admin', 'stufenleitung');

$router->get('/stufenleitung/halbjahre/{id}/kurse', function (array $p): array {
    return StufenleitungApi::getKurse((int) $p['id']);
}, 'admin', 'stufenleitung');

$router->post('/stufenleitung/halbjahre/{id}/kurse', function (array $p): array {
    return StufenleitungApi::addKurs((int) $p['id'], Router::jsonBody());
}, 'admin', 'stufenleitung');

$router->delete('/stufenleitung/kurse/{id}', function (array $p): array {
    return StufenleitungApi::deleteKurs((int) $p['id']);
}, 'admin', 'stufenleitung');

// ------------------------------------------------------------------
// Klausuren
// ------------------------------------------------------------------
$router->get('/klausuren', function (): array {
    return LehrkraftApi::getKlausuren();
}, 'admin', 'stufenleitung', 'lehrkraft');

// Vorlage-Download, paste-import und meine-nachschreibtermine VOR {id}, sonst werden sie als ID interpretiert
$router->get('/klausuren/vorlage', function (): never {
    LehrkraftApi::downloadVorlage();
}, 'admin', 'stufenleitung');

$router->get('/klausuren/meine-nachschreibtermine', function (): array {
    return LehrkraftApi::meineNachschreibtermine();
}, 'admin', 'stufenleitung', 'lehrkraft');

$router->post('/klausuren/paste-import', function (): array {
    $zeilen = Router::jsonBody();
    if (!is_array($zeilen)) {
        http_response_code(400);
        return ['fehler' => 'Array erwartet'];
    }
    return LehrkraftApi::postPasteImport($zeilen);
}, 'admin', 'stufenleitung');

$router->post('/klausuren', function (): array {
    return LehrkraftApi::postKlausur(Router::jsonBody());
}, 'admin', 'stufenleitung');

$router->put('/klausuren/{id}', function (array $p): array {
    return LehrkraftApi::putKlausur((int) $p['id'], Router::jsonBody());
}, 'admin', 'stufenleitung');

$router->delete('/klausuren/{id}', function (array $p): array {
    return LehrkraftApi::deleteKlausur((int) $p['id']);
}, 'admin', 'stufenleitung');

// ------------------------------------------------------------------
// Kursliste (für Dropdown)
// ------------------------------------------------------------------
$router->get('/kurse', function (): array {
    return LehrkraftApi::getKurse();
}, 'admin', 'stufenleitung');

// ------------------------------------------------------------------
// Nachschreibtermine
// ------------------------------------------------------------------
$router->get('/nachschreibtermine', function (): array {
    return LehrkraftApi::getNachschreibtermine();
}, 'admin', 'stufenleitung', 'lehrkraft');

$router->post('/nachschreibtermine', function (): array {
    return LehrkraftApi::postNachschreibtermin(Router::jsonBody());
}, 'admin', 'stufenleitung');

$router->put('/nachschreibtermine/{id}', function (array $p): array {
    return LehrkraftApi::putNachschreibtermin((int) $p['id'], Router::jsonBody());
}, 'admin', 'stufenleitung');

$router->post('/nachschreibtermine/{id}/klausuren', function (array $p): array {
    return LehrkraftApi::postNachschreibterminKlausuren((int) $p['id'], Router::jsonBody());
}, 'admin', 'stufenleitung');

$router->delete('/nachschreibtermine/{id}', function (array $p): array {
    return LehrkraftApi::deleteNachschreibtermin((int) $p['id']);
}, 'admin', 'stufenleitung');

// ------------------------------------------------------------------
// Anwesenheit
// ------------------------------------------------------------------
$router->get('/anwesenheit/{klausur_id}', function (array $p): array {
    return AnwesenheitApi::getAnwesenheit((int) $p['klausur_id']);
}, 'admin', 'stufenleitung', 'lehrkraft');

$router->post('/anwesenheit/{klausur_id}', function (array $p): array {
    $eintraege = Router::jsonBody();
    if (!is_array($eintraege)) {
        http_response_code(400);
        return ['fehler' => 'Array erwartet'];
    }
    return AnwesenheitApi::postAnwesenheit((int) $p['klausur_id'], $eintraege);
}, 'admin', 'stufenleitung', 'lehrkraft');

// ------------------------------------------------------------------
// Stufenleitung – Prüflinge eines Kurses (GoMST + manuell)
// ------------------------------------------------------------------
$router->get('/stufenleitung/kurse/{kurs_id}/schueler', function (array $p): array {
    return StufenleitungApi::getKursSchueler((int) $p['kurs_id']);
}, 'admin', 'stufenleitung');

$router->post('/stufenleitung/kurse/{kurs_id}/zusatz-schueler', function (array $p): array {
    return StufenleitungApi::addZusatzSchuelerZuKurs((int) $p['kurs_id'], Router::jsonBody());
}, 'admin', 'stufenleitung');

$router->delete('/stufenleitung/kurse/{kurs_id}/zusatz-schueler/{ks_id}', function (array $p): array {
    return StufenleitungApi::deleteZusatzSchuelerAusKurs((int) $p['kurs_id'], (int) $p['ks_id']);
}, 'admin', 'stufenleitung');

// ------------------------------------------------------------------
// Stufenleitung – Entschuldigung
// ------------------------------------------------------------------
$router->post('/stufenleitung/entschuldigung/{anwesenheit_id}', function (array $p): array {
    return AnwesenheitApi::postEntschuldigung((int) $p['anwesenheit_id'], Router::jsonBody());
}, 'admin', 'stufenleitung');

// ------------------------------------------------------------------
// Stufenleitung – E-Mail manuell auslösen
// ------------------------------------------------------------------
$router->post('/stufenleitung/email-ausloesen/{klausur_id}', function (array $p): array {
    return StufenleitungApi::emailAusloesen((int) $p['klausur_id']);
}, 'admin', 'stufenleitung');

// ------------------------------------------------------------------
// Stufenleitung – Daten löschen
// ------------------------------------------------------------------
$router->delete('/stufenleitung/daten/{halbjahr_id}', function (array $p): array {
    return StufenleitungApi::deleteHalbjahr((int) $p['halbjahr_id']);
}, 'admin', 'stufenleitung');

// ------------------------------------------------------------------
// Nachschreib-Anwesenheit
// ------------------------------------------------------------------
$router->get('/nachschreib-anwesenheit/{id}', function (array $p): array {
    return AnwesenheitApi::getNachschreibAnwesenheit((int) $p['id']);
}, 'admin', 'stufenleitung', 'lehrkraft');

$router->post('/nachschreib-anwesenheit/{id}', function (array $p): array {
    $eintraege = Router::jsonBody();
    if (!is_array($eintraege)) {
        http_response_code(400);
        return ['fehler' => 'Array erwartet'];
    }
    return AnwesenheitApi::postNachschreibAnwesenheit((int) $p['id'], $eintraege);
}, 'admin', 'stufenleitung', 'lehrkraft');

// ------------------------------------------------------------------
// Schüler*innen
// ------------------------------------------------------------------
$router->get('/schueler/meine-klausuren', function (): array {
    return SchuelerApi::meineKlausuren();
}, 'schueler');

$router->get('/schueler/meine-nachschreibtermine', function (): array {
    return SchuelerApi::meineNachschreibtermine();
}, 'schueler');

// ------------------------------------------------------------------

$router->dispatch();
