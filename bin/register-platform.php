#!/usr/bin/env php
<?php

declare(strict_types=1);

// CLI-Skript: Moodle als LTI 1.3-Plattform registrieren.
// Aufruf: php bin/register-platform.php
//
// Werte erhält man aus Moodle, nachdem das externe Tool dort gespeichert wurde
// (Abschnitt "Tool-Konfigurationsdetails" auf der Moodle-Einstellungsseite).

use ceLTIc\LTI\Platform;
use ceLTIc\LTI\DataConnector\DataConnector;
use ceLTIc\LTI\Enum\LtiVersion;
use Klausurplan\Models\Database;

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

echo "=== Moodle-Plattform für LTI 1.3 registrieren ===\n\n";
echo "Diese Werte findest du in Moodle unter:\n";
echo "Website-Administration → Plugins → Aktivitäten → Externes Tool\n";
echo "→ Dein Tool → 'Tool-Konfigurationsdetails anzeigen'\n\n";

function eingabe(string $bezeichnung, string $vorschlag = ''): string
{
    $hinweis = $vorschlag ? " [$vorschlag]" : '';
    fwrite(STDOUT, "$bezeichnung$hinweis: ");
    $wert = trim(fgets(STDIN));
    return $wert !== '' ? $wert : $vorschlag;
}

$platformId    = eingabe('Platform-ID (Issuer, z.B. https://moodle.schule.de)');
$clientId      = eingabe('Client-ID');
$deploymentId  = eingabe('Deployment-ID', '1');
$keySetUrl     = eingabe('Platform Keyset URL (z.B. https://moodle.schule.de/mod/lti/certs.php)');
$authUrl       = eingabe('Authentifizierungs-URL (z.B. https://moodle.schule.de/mod/lti/auth.php)');
$tokenUrl      = eingabe('Access-Token-URL (z.B. https://moodle.schule.de/mod/lti/token.php)');
$name          = eingabe('Anzeigename', 'Moodle');

if (empty($platformId) || empty($clientId) || empty($keySetUrl) || empty($authUrl) || empty($tokenUrl)) {
    fwrite(STDERR, "\nFehler: Alle Pflichtfelder müssen ausgefüllt sein.\n");
    exit(1);
}

$db        = Database::getInstance();
$connector = DataConnector::getDataConnector($db, 'lti2_');

$platform                 = new Platform($connector);
$platform->name           = $name;
$platform->platformId     = $platformId;
$platform->clientId       = $clientId;
$platform->deploymentId   = $deploymentId;
$platform->jku            = $keySetUrl;
$platform->authenticationUrl = $authUrl;
$platform->accessTokenUrl = $tokenUrl;
$platform->ltiVersion     = LtiVersion::V1P3;
$platform->enabled        = true;

if ($platform->save()) {
    echo "\nPlattform erfolgreich registriert.\n";
} else {
    fwrite(STDERR, "\nFehler beim Speichern. Ist die Plattform bereits registriert?\n");
    exit(1);
}
