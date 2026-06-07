<?php

declare(strict_types=1);

// JWKS-Endpunkt – gibt den öffentlichen Schlüssel des Tools als JSON Web Key Set zurück.
// Moodle ruft diese URL ab, um die Signaturen des Tools zu verifizieren.

use ceLTIc\LTI\Jwt\FirebaseClient;
use Klausurplan\Auth\LtiHandler;

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$keyFile = LtiHandler::resolveKeyPath($_ENV['LTI_PRIVATE_KEY_FILE'] ?? null);

if ($keyFile === null) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Private key not configured']);
    exit;
}

$privateKey = file_get_contents($keyFile);
$kid        = $_ENV['LTI_KID'] ?? 'klausurplan-key-1';

$jwks = FirebaseClient::getJWKS($privateKey, 'RS256', $kid);

header('Content-Type: application/json');
header('Cache-Control: public, max-age=3600');
echo json_encode($jwks, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
