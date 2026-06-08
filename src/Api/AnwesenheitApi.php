<?php

declare(strict_types=1);

namespace Klausurplan\Api;

use Klausurplan\Auth\Session;
use Klausurplan\Models\Database;
use RuntimeException;

class AnwesenheitApi
{
    // ------------------------------------------------------------------
    // Authentifizierte API-Endpunkte
    // ------------------------------------------------------------------

    /**
     * Gibt alle Prüflinge einer Klausur mit ihrem Anwesenheitsstatus zurück.
     * Fehlende Einträge erscheinen als 'ausstehend'.
     */
    public static function getAnwesenheit(int $klausurId): array
    {
        Session::requireRolle('admin', 'stufenleitung', 'lehrkraft');
        $db = Database::getInstance();

        self::pruefeKlausurZugriff($db, $klausurId);

        $stmt = $db->prepare(
            "SELECT ks.id                               AS kurs_schueler_id,
                    ks.name_roh,
                    ks.kursart,
                    b.id                                AS benutzer_id,
                    b.vorname,
                    b.nachname,
                    a.id                                AS anwesenheit_id,
                    COALESCE(a.status, 'ausstehend')    AS status,
                    COALESCE(a.entschuldigt, 0)         AS entschuldigt,
                    a.kommentar
             FROM kurs_schueler ks
             JOIN kurse k          ON k.id  = ks.kurs_id
             JOIN klausuren kl     ON kl.id = ?
             LEFT JOIN benutzer b  ON b.id  = ks.schueler_id
             LEFT JOIN anwesenheiten a
                    ON a.kurs_schueler_id = ks.id AND a.klausur_id = ?
             WHERE ks.kurs_id = kl.kurs_id
             ORDER BY COALESCE(b.nachname, ks.name_roh), b.vorname"
        );
        $stmt->execute([$klausurId, $klausurId]);

        return $stmt->fetchAll();
    }

    /**
     * Trägt Anwesenheiten für eine Klausur ein (Bulk-Upsert).
     *
     * Body: [{ kurs_schueler_id: int, status: 'anwesend'|'fehlend'|'ausstehend', kommentar?: string }]
     */
    public static function postAnwesenheit(int $klausurId, array $eintraege): array
    {
        Session::requireRolle('admin', 'stufenleitung', 'lehrkraft');
        $db = Database::getInstance();

        self::pruefeKlausurZugriff($db, $klausurId);

        $benutzer  = Session::getBenutzer();
        $gespeichert = 0;
        $erlaubteStatus = ['anwesend', 'fehlend', 'ausstehend'];

        foreach ($eintraege as $e) {
            $ksId      = (int) ($e['kurs_schueler_id'] ?? 0);
            $status    = $e['status'] ?? 'ausstehend';
            $kommentar = ($e['kommentar'] ?? '') !== '' ? trim($e['kommentar']) : null;

            if ($ksId === 0 || !in_array($status, $erlaubteStatus, true)) {
                continue;
            }

            // Prüfen ob kurs_schueler zur Klausur gehört
            $prüf = $db->prepare(
                'SELECT 1 FROM kurs_schueler ks
                 JOIN klausuren kl ON kl.kurs_id = ks.kurs_id
                 WHERE ks.id = ? AND kl.id = ?'
            );
            $prüf->execute([$ksId, $klausurId]);
            if ($prüf->fetchColumn() === false) {
                continue;
            }

            $vorhandene = $db->prepare(
                'SELECT id FROM anwesenheiten WHERE klausur_id = ? AND kurs_schueler_id = ?'
            );
            $vorhandene->execute([$klausurId, $ksId]);
            $aid = $vorhandene->fetchColumn();

            if ($aid !== false) {
                $db->prepare(
                    'UPDATE anwesenheiten
                     SET status = ?, kommentar = ?, geaendert_von = ?, geaendert_am = NOW()
                     WHERE id = ?'
                )->execute([$status, $kommentar, $benutzer['id'], $aid]);
            } else {
                $db->prepare(
                    'INSERT INTO anwesenheiten
                     (klausur_id, kurs_schueler_id, status, kommentar, erfasst_von, erfasst_am)
                     VALUES (?, ?, ?, ?, ?, NOW())'
                )->execute([$klausurId, $ksId, $status, $kommentar, $benutzer['id']]);
            }
            $gespeichert++;
        }

        return ['gespeichert' => $gespeichert];
    }

    /**
     * Setzt Entschuldigungsstatus für einen einzelnen Anwesenheitseintrag.
     * Nur Admin und Stufenleitung.
     *
     * Body: { entschuldigt: bool, kommentar?: string }
     */
    public static function postEntschuldigung(int $anwesenheitId, array $body): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db = Database::getInstance();

        $stmt = $db->prepare('SELECT id FROM anwesenheiten WHERE id = ?');
        $stmt->execute([$anwesenheitId]);
        if ($stmt->fetchColumn() === false) {
            http_response_code(404);
            throw new RuntimeException("Anwesenheitseintrag $anwesenheitId nicht gefunden.");
        }

        $entschuldigt = (bool) ($body['entschuldigt'] ?? false);
        $kommentar    = ($body['kommentar'] ?? '') !== '' ? trim($body['kommentar']) : null;
        $benutzer     = Session::getBenutzer();

        $db->prepare(
            'UPDATE anwesenheiten
             SET entschuldigt = ?, kommentar = ?, geaendert_von = ?, geaendert_am = NOW()
             WHERE id = ?'
        )->execute([$entschuldigt, $kommentar, $benutzer['id'], $anwesenheitId]);

        return ['ok' => true];
    }

    // ------------------------------------------------------------------
    // Token-basierte Seiten (kein Login erforderlich)
    // ------------------------------------------------------------------

    /**
     * Markiert alle Prüflinge als anwesend und zeigt Bestätigungsseite.
     * Aufruf: GET /anwesenheit/alle-da?token=XXX
     */
    public static function alleDa(string $token): never
    {
        $db = Database::getInstance();

        $benachrichtigung = self::tokenNachschlagen($db, $token);
        $klausurId = (int) $benachrichtigung['klausur_id'];

        // Alle kurs_schueler der Klausur auf 'anwesend' setzen
        $schueler = self::alleSchuelerFuerKlausur($db, $klausurId);
        foreach ($schueler as $ks) {
            self::upsertAnwesenheit($db, $klausurId, (int) $ks['id'], 'anwesend', null, null);
        }

        // Benachrichtigung als beantwortet markieren
        $db->prepare(
            'UPDATE email_benachrichtigungen SET beantwortet_am = NOW() WHERE id = ?'
        )->execute([$benachrichtigung['id']]);

        self::renderTokenSeite(
            'Anwesenheit bestätigt',
            '<p>Vielen Dank – alle Prüflinge wurden als <strong>anwesend</strong> eingetragen.</p>'
        );
    }

    /**
     * Zeigt das Anwesenheits-Eingabeformular (tokenbasiert).
     * Aufruf: GET /anwesenheit/eingabe?token=XXX
     */
    public static function eingabeSeite(string $token): never
    {
        $db = Database::getInstance();

        $benachrichtigung = self::tokenNachschlagen($db, $token);
        $klausurId = (int) $benachrichtigung['klausur_id'];

        $klausur  = self::klausurInfo($db, $klausurId);
        $schueler = self::alleSchuelerFuerKlausur($db, $klausurId);

        $zeilen = '';
        foreach ($schueler as $ks) {
            $name = $ks['nachname'] !== null
                ? htmlspecialchars($ks['nachname'] . ', ' . $ks['vorname'])
                : htmlspecialchars(str_replace('|', ', ', $ks['name_roh']));

            $zeilen .= '<tr>
                <td>' . $name . '</td>
                <td style="text-align:center">
                    <input type="checkbox" name="fehlend[]" value="' . $ks['id'] . '">
                </td>
                <td>
                    <input type="text" name="kommentar_' . $ks['id'] . '"
                           placeholder="Kommentar (optional)" style="width:100%;max-width:260px;padding:.3rem .5rem;border:1px solid #ccc;border-radius:4px">
                </td>
            </tr>';
        }

        $datumStr = $klausur['termin_datum']
            ? date('d.m.Y', strtotime($klausur['termin_datum']))
            : 'kein Datum';

        $inhalt = '
            <p>Kurs: <strong>' . htmlspecialchars($klausur['kurs_anzeigename']) . '</strong>
               &nbsp;|&nbsp; Datum: <strong>' . $datumStr . '</strong></p>
            <p>Bitte setzen Sie einen Haken bei fehlenden Prüflingen:</p>
            <form method="post" action="/anwesenheit/token-eintrag">
                <input type="hidden" name="token" value="' . htmlspecialchars($token) . '">
                <table style="width:100%;border-collapse:collapse">
                    <thead>
                        <tr>
                            <th style="text-align:left;padding:.4rem .6rem;border-bottom:2px solid #ddd">Name</th>
                            <th style="padding:.4rem .6rem;border-bottom:2px solid #ddd">Fehlend</th>
                            <th style="text-align:left;padding:.4rem .6rem;border-bottom:2px solid #ddd">Kommentar</th>
                        </tr>
                    </thead>
                    <tbody>' . $zeilen . '</tbody>
                </table>
                <div style="margin-top:1.5rem">
                    <button type="submit"
                            style="padding:.5rem 1.5rem;background:#1a3a5c;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:1rem">
                        Anwesenheit speichern
                    </button>
                </div>
            </form>';

        self::renderTokenSeite('Anwesenheit eintragen', $inhalt);
    }

    /**
     * Verarbeitet das Token-Formular (POST /anwesenheit/token-eintrag).
     */
    public static function tokenEintrag(): never
    {
        $token = trim($_POST['token'] ?? '');
        if ($token === '') {
            self::renderTokenSeite('Fehler', '<p>Kein Token übermittelt.</p>');
        }

        $db = Database::getInstance();

        $benachrichtigung = self::tokenNachschlagen($db, $token);
        $klausurId = (int) $benachrichtigung['klausur_id'];

        $schueler = self::alleSchuelerFuerKlausur($db, $klausurId);

        // Fehlende Schüler*innen aus Checkbox-Array
        $fehlend = array_map('intval', $_POST['fehlend'] ?? []);

        foreach ($schueler as $ks) {
            $ksId      = (int) $ks['id'];
            $status    = in_array($ksId, $fehlend, true) ? 'fehlend' : 'anwesend';
            $kommentar = trim($_POST['kommentar_' . $ksId] ?? '') ?: null;
            self::upsertAnwesenheit($db, $klausurId, $ksId, $status, $kommentar, null);
        }

        $db->prepare(
            'UPDATE email_benachrichtigungen SET beantwortet_am = NOW() WHERE id = ?'
        )->execute([$benachrichtigung['id']]);

        self::renderTokenSeite(
            'Gespeichert',
            '<p>Die Anwesenheitsdaten wurden erfolgreich gespeichert. Vielen Dank!</p>'
        );
    }

    // ------------------------------------------------------------------
    // Interne Hilfsmethoden
    // ------------------------------------------------------------------

    /** Prüft ob der aktuell eingeloggte Benutzer Zugriff auf die Klausur hat. */
    private static function pruefeKlausurZugriff(\PDO $db, int $klausurId): void
    {
        $benutzer = Session::getBenutzer();
        $rollen   = $benutzer['rollen'] ?? [];

        if (in_array('admin', $rollen, true) || in_array('stufenleitung', $rollen, true)) {
            return;
        }

        // Lehrkraft: nur eigene Klausuren
        $stmt = $db->prepare(
            'SELECT 1 FROM klausuren kl
             JOIN kurse k ON k.id = kl.kurs_id
             WHERE kl.id = ? AND k.lehrer_id = ?'
        );
        $stmt->execute([$klausurId, $benutzer['id']]);
        if ($stmt->fetchColumn() === false) {
            http_response_code(403);
            throw new RuntimeException('Kein Zugriff auf diese Klausur.');
        }
    }

    /** Schlägt ein Token in email_benachrichtigungen nach; bricht ab wenn ungültig. */
    private static function tokenNachschlagen(\PDO $db, string $token): array
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            self::renderTokenSeite('Ungültiger Link', '<p>Dieser Link ist ungültig.</p>');
        }

        $stmt = $db->prepare(
            'SELECT id, klausur_id, beantwortet_am FROM email_benachrichtigungen WHERE token = ?'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        if ($row === false) {
            self::renderTokenSeite('Ungültiger Link', '<p>Dieser Link ist ungültig oder abgelaufen.</p>');
        }

        return $row;
    }

    /** Alle kurs_schueler-Einträge für eine Klausur. */
    private static function alleSchuelerFuerKlausur(\PDO $db, int $klausurId): array
    {
        $stmt = $db->prepare(
            'SELECT ks.id, ks.name_roh,
                    b.vorname, b.nachname
             FROM kurs_schueler ks
             JOIN klausuren kl ON kl.kurs_id = ks.kurs_id
             LEFT JOIN benutzer b ON b.id = ks.schueler_id
             WHERE kl.id = ?
             ORDER BY COALESCE(b.nachname, ks.name_roh), b.vorname'
        );
        $stmt->execute([$klausurId]);
        return $stmt->fetchAll();
    }

    /** Kurs- und Datumsinformationen zu einer Klausur. */
    private static function klausurInfo(\PDO $db, int $klausurId): array
    {
        $stmt = $db->prepare(
            'SELECT kl.termin_datum, k.anzeigename AS kurs_anzeigename
             FROM klausuren kl
             JOIN kurse k ON k.id = kl.kurs_id
             WHERE kl.id = ?'
        );
        $stmt->execute([$klausurId]);
        $row = $stmt->fetch();
        if ($row === false) {
            self::renderTokenSeite('Fehler', '<p>Klausur nicht gefunden.</p>');
        }
        return $row;
    }

    /** Upsert eines Anwesenheitseintrags ohne Session-Bezug (für Token-Seiten). */
    private static function upsertAnwesenheit(
        \PDO    $db,
        int     $klausurId,
        int     $kursSchuelerId,
        string  $status,
        ?string $kommentar,
        ?int    $erfasstVon,
    ): void {
        $vorhandene = $db->prepare(
            'SELECT id FROM anwesenheiten WHERE klausur_id = ? AND kurs_schueler_id = ?'
        );
        $vorhandene->execute([$klausurId, $kursSchuelerId]);
        $aid = $vorhandene->fetchColumn();

        if ($aid !== false) {
            $db->prepare(
                'UPDATE anwesenheiten
                 SET status = ?, kommentar = ?, geaendert_am = NOW()
                 WHERE id = ?'
            )->execute([$status, $kommentar, $aid]);
        } else {
            $db->prepare(
                'INSERT INTO anwesenheiten
                 (klausur_id, kurs_schueler_id, status, kommentar, erfasst_von, erfasst_am)
                 VALUES (?, ?, ?, ?, ?, NOW())'
            )->execute([$klausurId, $kursSchuelerId, $status, $kommentar, $erfasstVon]);
        }
    }

    /** Rendert eine einfache Token-Seite und beendet die Ausführung. */
    private static function renderTokenSeite(string $titel, string $inhalt): never
    {
        $titelEsc = htmlspecialchars($titel);
        echo <<<HTML
        <!DOCTYPE html>
        <html lang="de">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>{$titelEsc} – Klausurplan</title>
            <link rel="stylesheet" href="/assets/app.css">
        </head>
        <body>
            <header>
                <h1>Klausurplan</h1>
            </header>
            <main style="max-width:800px;margin:2rem auto;padding:0 1rem">
                <div class="karte">
                    <h2 style="margin-top:0">{$titelEsc}</h2>
                    {$inhalt}
                </div>
            </main>
        </body>
        </html>
        HTML;
        exit();
    }
}
