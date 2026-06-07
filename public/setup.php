<?php

declare(strict_types=1);

/**
 * Einmaliger Setup-Assistent (browserbasiert).
 *
 * Zugriff nur wenn SETUP_TOKEN in .env gesetzt ist.
 * URL: /setup.php?token=<SETUP_TOKEN>
 *
 * Nach abgeschlossenem Setup SETUP_TOKEN aus .env entfernen.
 */

use ceLTIc\LTI\Jwt\FirebaseClient;
use ceLTIc\LTI\Platform;
use ceLTIc\LTI\DataConnector\DataConnector;
use ceLTIc\LTI\Enum\LtiVersion;
use Klausurplan\Auth\LtiHandler;
use Klausurplan\Models\Database;

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// --- Token-Schutz ---
$setupToken = $_ENV['SETUP_TOKEN'] ?? '';
if (empty($setupToken)) {
    http_response_code(404);
    exit('Nicht gefunden.');
}
$tokenParam = $_GET['token'] ?? $_POST['token'] ?? '';
if (!hash_equals($setupToken, $tokenParam)) {
    http_response_code(403);
    exit('Ungültiger Token.');
}

$appUrl    = rtrim($_ENV['APP_URL'] ?? '', '/');
$moodleUrl = rtrim($_ENV['MOODLE_URL'] ?? '', '/');
$keyFile   = LtiHandler::resolveKeyPath($_ENV['LTI_PRIVATE_KEY_FILE'] ?? null);
// Für die Anzeige: Pfad wie in .env angegeben (noch nicht aufgelöst)
$keyFileRaw = $_ENV['LTI_PRIVATE_KEY_FILE'] ?? '';
// Absoluten Zielpfad für das Schreiben berechnen (auch wenn Datei noch nicht existiert)
$keyFilePath = (str_starts_with($keyFileRaw, '/'))
    ? $keyFileRaw
    : dirname(__DIR__) . '/' . $keyFileRaw;

$meldung = '';
$fehler  = '';
$aktion  = $_POST['aktion'] ?? '';

// --- Aktionen verarbeiten ---
if ($aktion === 'schluessel_generieren') {
    if (file_exists($keyFilePath) && empty($_POST['ueberschreiben'])) {
        $fehler = 'Schlüssel existiert bereits. Checkbox aktivieren um zu überschreiben.';
    } else {
        $verzeichnis = dirname($keyFilePath);
        if (!is_dir($verzeichnis) && !mkdir($verzeichnis, 0700, true)) {
            $fehler = "Verzeichnis $verzeichnis konnte nicht angelegt werden.";
        } else {
            $privateKey = FirebaseClient::generateKey('RS256');
            if ($privateKey === null) {
                $fehler = 'Schlüsselgenerierung fehlgeschlagen (OpenSSL verfügbar?).';
            } else {
                file_put_contents($keyFilePath, $privateKey);
                chmod($keyFilePath, 0600);
                $meldung = 'RSA-Schlüssel erfolgreich generiert: ' . $keyFileRaw;
                $keyFile = $keyFilePath;
            }
        }
    }
}

if ($aktion === 'plattform_registrieren') {
    $platformId   = trim($_POST['platform_id'] ?? '');
    $clientId     = trim($_POST['client_id'] ?? '');
    $deploymentId = trim($_POST['deployment_id'] ?? '1');
    $keySetUrl    = trim($_POST['key_set_url'] ?? '');
    $authUrl      = trim($_POST['auth_url'] ?? '');
    $tokenUrl     = trim($_POST['token_url'] ?? '');
    $name         = trim($_POST['name'] ?? 'Moodle');

    if (empty($platformId) || empty($clientId) || empty($keySetUrl) || empty($authUrl) || empty($tokenUrl)) {
        $fehler = 'Alle Pflichtfelder müssen ausgefüllt sein.';
    } else {
        try {
            $db        = Database::getInstance();
            $connector = DataConnector::getDataConnector($db);

            $platform                    = new Platform($connector);
            $platform->name              = $name;
            $platform->platformId        = $platformId;
            $platform->clientId          = $clientId;
            $platform->deploymentId      = $deploymentId;
            $platform->jku               = $keySetUrl;
            $platform->authenticationUrl = $authUrl;
            $platform->accessTokenUrl    = $tokenUrl;
            $platform->ltiVersion        = LtiVersion::V1P3;
            $platform->enabled           = true;

            if ($platform->save()) {
                $meldung = 'Plattform erfolgreich registriert. Setup abgeschlossen!';
            } else {
                $fehler = 'Fehler beim Speichern – ist die Plattform bereits registriert?';
            }
        } catch (Throwable $e) {
            $fehler = 'Datenbankfehler: ' . htmlspecialchars($e->getMessage());
        }
    }
}

$schluesselVorhanden = file_exists($keyFilePath);
$jwksUrl = $appUrl . '/lti-jwks.php';
$launchUrl = $appUrl . '/lti-launch.php';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Klausurplan Setup</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: system-ui, sans-serif; max-width: 860px; margin: 2rem auto; padding: 0 1rem; color: #222; }
        h1 { font-size: 1.5rem; border-bottom: 2px solid #1a3a5c; padding-bottom: .5rem; }
        h2 { font-size: 1.1rem; margin-top: 2rem; }
        .schritt { background: #f5f7fa; border: 1px solid #dde; border-radius: 6px; padding: 1.25rem 1.5rem; margin: 1.25rem 0; }
        .schritt h2 { margin-top: 0; }
        .ok    { background: #e6f4ea; border-color: #4caf50; }
        .meldung { background: #e6f4ea; border: 1px solid #4caf50; border-radius: 4px; padding: .75rem 1rem; margin: 1rem 0; }
        .fehler  { background: #fdecea; border: 1px solid #e53935; border-radius: 4px; padding: .75rem 1rem; margin: 1rem 0; }
        label { display: block; margin: .75rem 0 .25rem; font-weight: 500; font-size: .9rem; }
        input[type=text], input[type=url] { width: 100%; padding: .45rem .6rem; border: 1px solid #bbb; border-radius: 4px; font-size: .9rem; }
        button { background: #1a3a5c; color: #fff; border: none; padding: .5rem 1.25rem; border-radius: 4px; cursor: pointer; font-size: .9rem; }
        button:hover { background: #274f7a; }
        code { background: #eee; padding: .1rem .35rem; border-radius: 3px; font-size: .875rem; word-break: break-all; }
        .kopier-zeile { display: flex; gap: .5rem; align-items: center; margin: .4rem 0; }
        .kopier-zeile code { flex: 1; }
        .badge-ok  { color: #2e7d32; font-weight: bold; }
        .badge-neu { color: #c62828; font-weight: bold; }
        .checkbox-zeile { display: flex; align-items: center; gap: .5rem; margin-top: .75rem; font-size: .875rem; }
        .hinweis { font-size: .85rem; color: #555; margin-top: .4rem; }
    </style>
</head>
<body>
<h1>Klausurplan – Setup-Assistent</h1>

<p>Dieser Assistent hilft beim Einrichten des LTI 1.3-Integrations.
Nach Abschluss <strong>SETUP_TOKEN aus <code>.env</code> entfernen</strong>.</p>

<?php if ($meldung): ?>
<div class="meldung">✓ <?= h($meldung) ?></div>
<?php endif; ?>
<?php if ($fehler): ?>
<div class="fehler">✗ <?= h($fehler) ?></div>
<?php endif; ?>

<!-- ============================================================ -->
<!-- Schritt 1: RSA-Schlüssel                                     -->
<!-- ============================================================ -->
<div class="schritt <?= $schluesselVorhanden ? 'ok' : '' ?>">
    <h2>Schritt 1 – RSA-Schlüssel generieren</h2>

    <?php if ($schluesselVorhanden): ?>
    <p><span class="badge-ok">✓ Vorhanden:</span> <code><?= h($keyFileRaw) ?></code></p>
    <?php else: ?>
    <p><span class="badge-neu">✗ Noch kein Schlüssel</span> (<code><?= h($keyFileRaw) ?></code>)</p>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="token" value="<?= h($tokenParam) ?>">
        <input type="hidden" name="aktion" value="schluessel_generieren">
        <?php if ($schluesselVorhanden): ?>
        <div class="checkbox-zeile">
            <input type="checkbox" name="ueberschreiben" id="ueberschreiben">
            <label for="ueberschreiben" style="margin:0">Vorhandenen Schlüssel überschreiben (Achtung: bestehende LTI-Sessions werden ungültig)</label>
        </div>
        <br>
        <?php endif; ?>
        <button type="submit"><?= $schluesselVorhanden ? 'Schlüssel neu generieren' : 'Schlüssel jetzt generieren' ?></button>
    </form>
</div>

<!-- ============================================================ -->
<!-- Schritt 2: Moodle-Formular                                   -->
<!-- ============================================================ -->
<div class="schritt">
    <h2>Schritt 2 – Tool in Moodle registrieren</h2>
    <p>Trage diese Werte im Moodle-Formular <em>„Externes Tool hinzufügen"</em> ein:</p>

    <table style="border-collapse:collapse;width:100%;font-size:.9rem">
        <tr><th style="text-align:left;padding:.4rem .6rem;background:#eef;width:45%">Feld</th><th style="text-align:left;padding:.4rem .6rem;background:#eef">Wert</th></tr>
        <?php foreach ([
            'Name des Tools'          => 'Klausurplan',
            'Tool URL'                => $appUrl . '/',
            'LTI Version'             => 'LTI 1.3',
            'Öffentlicher Schlüsseltyp' => 'Schlüsselsatz-URL',
            'Öffentlicher Schlüsselsatz' => $jwksUrl,
            'Anmelde-URL'             => $launchUrl,
            'Umleitungs-URI(s)'       => $launchUrl,
            'Anwendername übergeben'  => 'Immer',
            'E-Mail übergeben'        => 'Immer',
        ] as $feld => $wert): ?>
        <tr>
            <td style="padding:.4rem .6rem;border-top:1px solid #dde"><?= h($feld) ?></td>
            <td style="padding:.4rem .6rem;border-top:1px solid #dde"><code><?= h($wert) ?></code></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <p class="hinweis">Nach dem Speichern zeigt Moodle unter „Tool-Konfigurationsdetails" die Werte für Schritt 3.</p>
</div>

<!-- ============================================================ -->
<!-- Schritt 3: Plattform registrieren                            -->
<!-- ============================================================ -->
<div class="schritt">
    <h2>Schritt 3 – Moodle-Plattform in der Datenbank eintragen</h2>
    <p>Diese Werte findest du in Moodle unter
    <em>Website-Administration → Plugins → Aktivitäten → Externes Tool → [Tool] → „Konfigurationsdetails anzeigen"</em>.</p>

    <form method="post">
        <input type="hidden" name="token" value="<?= h($tokenParam) ?>">
        <input type="hidden" name="aktion" value="plattform_registrieren">

<?php
$def = [
    'platform_id' => $moodleUrl,
    'key_set_url' => $moodleUrl ? $moodleUrl . '/mod/lti/certs.php' : '',
    'token_url'   => $moodleUrl ? $moodleUrl . '/mod/lti/token.php' : '',
    'auth_url'    => $moodleUrl ? $moodleUrl . '/mod/lti/auth.php'  : '',
];
function val(string $name, array $def): string {
    return htmlspecialchars($_POST[$name] ?? $def[$name] ?? '', ENT_QUOTES);
}
?>
        <label>Plattform-ID (Issuer) *</label>
        <input type="url" name="platform_id" required
               value="<?= val('platform_id', $def) ?>">

        <label>Client-ID *</label>
        <input type="text" name="client_id" required placeholder="abc123"
               value="<?= h($_POST['client_id'] ?? '') ?>">

        <label>Deployment-ID *</label>
        <input type="text" name="deployment_id" required value="<?= h($_POST['deployment_id'] ?? '1') ?>">

        <label>Platform Keyset URL *</label>
        <input type="url" name="key_set_url" required
               value="<?= val('key_set_url', $def) ?>">

        <label>Access-Token-URL *</label>
        <input type="url" name="token_url" required
               value="<?= val('token_url', $def) ?>">

        <label>Authentifizierungs-URL *</label>
        <input type="url" name="auth_url" required
               value="<?= val('auth_url', $def) ?>">

        <label>Anzeigename</label>
        <input type="text" name="name" value="<?= h($_POST['name'] ?? 'Moodle') ?>">

        <br>
        <button type="submit">Plattform registrieren</button>
    </form>
</div>

<!-- ============================================================ -->
<!-- Schritt 4: Abschluss                                         -->
<!-- ============================================================ -->
<div class="schritt">
    <h2>Schritt 4 – Setup abschließen</h2>
    <p>Entferne oder kommentiere <code>SETUP_TOKEN</code> in der <code>.env</code> –
    danach ist diese Seite nicht mehr erreichbar.</p>
    <pre style="background:#eee;padding:.75rem;border-radius:4px;font-size:.85rem"># SETUP_TOKEN=...</pre>
</div>

</body>
</html>
