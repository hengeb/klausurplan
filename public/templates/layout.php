<?php
/** @var array $rollen */
$benutzer   = \Klausurplan\Auth\Session::getBenutzer();
$vorname    = htmlspecialchars($benutzer['vorname'] ?? '');
$nachname   = htmlspecialchars($benutzer['nachname'] ?? '');
$rollen     = $benutzer['rollen'] ?? [];
$nurSchueler = !array_intersect($rollen, ['admin', 'stufenleitung', 'lehrkraft'])
               && in_array('schueler', $rollen, true);

// Daten von GET /api/me gleich mitliefern – spart dem Frontend eine Anfrage beim Seitenaufbau.
// Scheitert das (z.B. DB nicht erreichbar), holt das Frontend sie selbst (null).
try {
    $me = \Klausurplan\Api\MeController::handle();
} catch (\Throwable $e) {
    error_log('layout.php: /me-Daten nicht eingebettet: ' . $e->getMessage());
    $me = null;
}
$jsonImScript = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;

// Änderungszeit als Versionsparameter: nach einem Update laden Browser CSS/JS neu statt aus dem Cache
$assetVersion = static fn (string $datei): int => (int) @filemtime(__DIR__ . '/../assets/' . $datei);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Klausurplan</title>
    <link rel="stylesheet" href="/assets/app.css?v=<?= $assetVersion('app.css') ?>">
</head>
<body>
    <?php if (!$nurSchueler): ?>
    <header>
        <h1>Klausurplan</h1>
        <span class="nutzer"><?= $vorname ?> <?= $nachname ?></span>
    </header>
    <nav id="nav"></nav>
    <?php endif; ?>
    <main id="app">
        <p>Wird geladen…</p>
    </main>
    <script>
        window.KLAUSURPLAN_ROLLEN = <?= json_encode($rollen, JSON_UNESCAPED_UNICODE) ?>;
        window.KLAUSURPLAN_ME_ID  = <?= (int) ($benutzer['id'] ?? 0) ?>;
        window.KLAUSURPLAN_ME     = <?= json_encode($me, $jsonImScript) ?>;
    </script>
    <script src="/assets/app.js?v=<?= $assetVersion('app.js') ?>"></script>
</body>
</html>
