<?php
/** @var array $rollen */
$benutzer = \Klausurplan\Auth\Session::getBenutzer();
$vorname  = htmlspecialchars($benutzer['vorname'] ?? '');
$nachname = htmlspecialchars($benutzer['nachname'] ?? '');
$rollen   = $benutzer['rollen'] ?? [];
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Klausurplan</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
    <header>
        <h1>Klausurplan</h1>
        <span class="nutzer"><?= $vorname ?> <?= $nachname ?></span>
    </header>
    <nav id="nav"></nav>
    <main id="app">
        <p>Wird geladen…</p>
    </main>
    <script>
        window.KLAUSURPLAN_ROLLEN = <?= json_encode($rollen, JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <script src="/assets/app.js"></script>
</body>
</html>
