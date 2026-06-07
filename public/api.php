<?php

declare(strict_types=1);

use Klausurplan\Auth\Session;
use Klausurplan\Api\Router;
use Klausurplan\Api\MeController;

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

Session::start();

$router = new Router();

// --- /api/me ---
$router->get('/me', function (): array {
    Session::requireAuth();
    return MeController::handle();
});

// --- Weitere Endpunkte werden in späteren Phasen ergänzt ---

$router->dispatch();
