<?php

declare(strict_types=1);

use Klausurplan\Auth\Session;

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

Session::start();

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
