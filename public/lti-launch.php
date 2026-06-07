<?php

declare(strict_types=1);

// LTI 1.3 Launch-Endpunkt
// Dieser Endpunkt wird von Moodle aufgerufen.

use Klausurplan\Auth\LtiHandler;
use Klausurplan\Auth\Session;

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

Session::start();

try {
    $tool = new LtiHandler();
    $tool->handleRequest();
} catch (Throwable $e) {
    http_response_code(500);
    error_log('LTI-Launch-Fehler: ' . $e->getMessage());
    echo '<!DOCTYPE html><html><body><p>Fehler beim LTI-Launch. Bitte kontaktieren Sie den Administrator.</p></body></html>';
}
