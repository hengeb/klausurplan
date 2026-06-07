#!/usr/bin/env php
<?php

declare(strict_types=1);

// CLI-Skript: RSA-Schlüsselpaar für LTI 1.3 generieren.
// Aufruf: php bin/generate-lti-key.php
//
// Der private Schlüssel wird in LTI_PRIVATE_KEY_FILE gespeichert.
// Der öffentliche Schlüssel wird daneben als .pub abgelegt (nur zur Info).

use ceLTIc\LTI\Jwt\FirebaseClient;

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$keyFileRaw = $_ENV['LTI_PRIVATE_KEY_FILE'] ?? null;

if (empty($keyFileRaw)) {
    fwrite(STDERR, "Fehler: LTI_PRIVATE_KEY_FILE ist nicht in .env gesetzt.\n");
    exit(1);
}

// Relative Pfade relativ zum Projektverzeichnis auflösen
$keyFile = str_starts_with($keyFileRaw, '/') ? $keyFileRaw : dirname(__DIR__) . '/' . $keyFileRaw;

if (file_exists($keyFile)) {
    fwrite(STDERR, "Warnung: $keyFile existiert bereits.\n");
    fwrite(STDERR, "Überschreiben? (ja/nein): ");
    $antwort = trim(fgets(STDIN));
    if (strtolower($antwort) !== 'ja') {
        echo "Abgebrochen.\n";
        exit(0);
    }
}

$verzeichnis = dirname($keyFile);
if (!is_dir($verzeichnis) && !mkdir($verzeichnis, 0700, true)) {
    fwrite(STDERR, "Fehler: Verzeichnis $verzeichnis konnte nicht angelegt werden.\n");
    exit(1);
}

echo "Generiere RSA-2048-Schlüsselpaar...\n";

$privateKey = FirebaseClient::generateKey('RS256');
if ($privateKey === null) {
    fwrite(STDERR, "Fehler: Schlüsselgenerierung fehlgeschlagen (OpenSSL verfügbar?).\n");
    exit(1);
}

$publicKey = FirebaseClient::getPublicKey($privateKey);

file_put_contents($keyFile, $privateKey);
chmod($keyFile, 0600);

$pubFile = $keyFile . '.pub';
file_put_contents($pubFile, $publicKey);

echo "Privater Schlüssel: $keyFile\n";
echo "Öffentlicher Schlüssel: $pubFile\n";
echo "\nFertig. LTI_PRIVATE_KEY_FILE in .env zeigt auf: $keyFile\n";
