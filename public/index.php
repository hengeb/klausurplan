<?php

declare(strict_types=1);

use Klausurplan\Auth\Session;
use Klausurplan\Api\AnwesenheitApi;

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// ------------------------------------------------------------------
// Token-basierte Seiten – kein Login erforderlich
// ------------------------------------------------------------------
$path  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$token = trim($_GET['token'] ?? '');

// Gespeichert wird nur per POST (Buttons auf der Eingabeseite). Link-Scanner von Mail-Sicherheitsfiltern
// öffnen Links aus Mails automatisch per GET, schicken aber keine Formulare ab.
if ($path === '/anwesenheit/alle-da' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    AnwesenheitApi::alleDa(trim($_POST['token'] ?? ''));
}

// Mail-Link; /alle-da per GET nur noch für bereits verschickte alte Mails → dieselbe Seite
if (($path === '/anwesenheit/eingabe' || $path === '/anwesenheit/alle-da') && $token !== '') {
    AnwesenheitApi::eingabeSeite($token);
}

if ($path === '/anwesenheit/token-eintrag' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    AnwesenheitApi::tokenEintrag();
}

// ------------------------------------------------------------------
// Authentifizierte Seiten
// ------------------------------------------------------------------
Session::start(nurLesen: true);

// CSP senden – überschreibt ggf. eine restriktivere Server-Policy
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; font-src 'self'; frame-ancestors 'self' https://moodle.gymnasium-broich.de");

// Nicht eingeloggte Nutzer landen auf einer Hinweisseite
if (!Session::isAuthenticated()) {
    http_response_code(401);
    include __DIR__ . '/templates/nicht-authentifiziert.html';
    exit;
}

$rollen = Session::getBenutzer()['rollen'] ?? [];

// Rollenbasiertes Routing zur passenden View-Shell
if (in_array('admin', $rollen, true) || in_array('stufenleitung', $rollen, true)) {
    include __DIR__ . '/templates/layout.php';
} elseif (in_array('lehrkraft', $rollen, true)) {
    include __DIR__ . '/templates/layout.php';
} elseif (in_array('schueler', $rollen, true)) {
    include __DIR__ . '/templates/layout.php';
} else {
    http_response_code(403);
    include __DIR__ . '/templates/keine-rolle.html';
}
