<?php

/**
 * Router-Skript für `php -S`: täuscht die Moodle-REST-API vor (core_user_get_users).
 * Das Verhalten steuert der Token (wstoken): "ok", "http500", "exception", "kaputt".
 */

declare(strict_types=1);

$token = $_GET['wstoken'] ?? '';
$auth  = $_GET['criteria'][0]['value'] ?? '';

header('Content-Type: application/json');

if ($token === 'http500') {
    http_response_code(500);
    echo '{}';
    return;
}
if ($token === 'exception') {
    echo json_encode(['exception' => 'webservice_access_exception', 'message' => 'Zugriff verweigert']);
    return;
}
if ($token === 'kaputt') {
    echo 'das ist kein JSON';
    return;
}

$lehrkraft = ['id' => 1, 'firstname' => 'Anna', 'lastname' => 'Gebauer (SZ)', 'email' => 'anna@example.org',
              'customfields' => [['shortname' => 'klasse', 'value' => 'Lehrkraft']]];
$schueler  = ['id' => 2, 'firstname' => 'Max', 'lastname' => 'Mustermann', 'email' => 'privat@example.org',
              'customfields' => [['shortname' => 'klasse', 'value' => 'Q2 Kurs 1']]];

echo json_encode(['users' => $auth === 'ldap' ? [$lehrkraft, $schueler] : [$schueler]]);
