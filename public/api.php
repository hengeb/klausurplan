<?php

declare(strict_types=1);

use Klausurplan\Auth\Session;
use Klausurplan\Api\Router;
use Klausurplan\Api\MeController;
use Klausurplan\Api\AdminApi;
use Klausurplan\Api\StufenleitungApi;

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

$router->put('/admin/faecher/{kuerzel}', function (array $p): array {
    $body = Router::jsonBody();
    $bezeichnung = trim($body['bezeichnung'] ?? '');
    if (empty($bezeichnung)) {
        http_response_code(400);
        return ['fehler' => 'bezeichnung fehlt'];
    }
    return AdminApi::updateFach($p['kuerzel'], $bezeichnung);
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
$router->get('/stufenleitung/halbjahre', function (): array {
    return StufenleitungApi::getHalbjahre();
}, 'admin', 'stufenleitung');

$router->get('/stufenleitung/halbjahre/{id}/kurse', function (array $p): array {
    return StufenleitungApi::getKurse((int) $p['id']);
}, 'admin', 'stufenleitung');

// ------------------------------------------------------------------

$router->dispatch();
