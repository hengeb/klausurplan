<?php

/**
 * Cron-Script: Anwesenheits-E-Mails senden.
 * Empfohlener Cron-Eintrag: 0 * * * * php /var/www/klausurplan/src/Cron/erinnerungen_senden.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Klausurplan\Models\Database;
use Klausurplan\Mail\Mailer;
use Klausurplan\Mail\EmailTemplates;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->load();

$db = Database::getInstance();

// Alle vergangenen Klausuren mit bekannter Lehrkraft-E-Mail
$stmt = $db->query(
    "SELECT kl.id            AS klausur_id,
            kl.termin_datum,
            k.anzeigename    AS kurs_anzeigename,
            b.id             AS lehrer_id,
            b.email          AS lehrer_email,
            b.vorname        AS lehrer_vorname,
            b.nachname       AS lehrer_nachname
     FROM klausuren kl
     JOIN kurse k    ON k.id = kl.kurs_id
     JOIN benutzer b ON b.id = k.lehrer_id
     WHERE kl.termin_datum    IS NOT NULL
       AND kl.termin_uhrzeit  IS NOT NULL
       AND TIMESTAMP(kl.termin_datum, kl.termin_uhrzeit) < NOW()
       AND b.email IS NOT NULL
       AND b.email <> ''"
);
$klausuren = $stmt->fetchAll();

$gesendet = 0;
$fehler   = 0;

foreach ($klausuren as $kl) {
    $klausurId = (int) $kl['klausur_id'];

    // Historie dieser Klausur laden
    $history = $db->prepare(
        "SELECT typ, gesendet_am, beantwortet_am
         FROM email_benachrichtigungen
         WHERE klausur_id = ?
         ORDER BY gesendet_am ASC"
    );
    $history->execute([$klausurId]);
    $rows = $history->fetchAll();

    $erstmeldungen = array_filter($rows, fn($r) => $r['typ'] === 'erstmeldung');
    $erinnerungen  = array_filter($rows, fn($r) => $r['typ'] === 'erinnerung');

    $typ = null;

    if (count($erstmeldungen) === 0) {
        $typ = 'erstmeldung';
    } elseif (count($erinnerungen) === 0) {
        $erste          = array_values($erstmeldungen)[0];
        $hatGeantwortet = $erste['beantwortet_am'] !== null;
        $alterSekunden  = time() - strtotime($erste['gesendet_am']);

        if (!$hatGeantwortet && $alterSekunden >= 7 * 86_400) {
            $typ = 'erinnerung';
        }
    }

    if ($typ === null) {
        continue;
    }

    $token = bin2hex(random_bytes(32));

    try {
        $klausurDaten = [
            'kurs_anzeigename' => $kl['kurs_anzeigename'],
            'termin_datum'     => $kl['termin_datum'],
        ];

        $htmlBody = match ($typ) {
            'erstmeldung' => EmailTemplates::erstmeldung($klausurDaten, $token),
            'erinnerung'  => EmailTemplates::erinnerung($klausurDaten, $token),
        };

        $datumStr       = date('d.m.Y', strtotime($kl['termin_datum']));
        $kursAnzeige    = $kl['kurs_anzeigename'];
        $betreff        = match ($typ) {
            'erstmeldung' => "Anwesenheit Klausur {$kursAnzeige} am {$datumStr}",
            'erinnerung'  => "[Erinnerung] Anwesenheit Klausur {$kursAnzeige} am {$datumStr}",
        };

        $empfaengerName = trim($kl['lehrer_vorname'] . ' ' . $kl['lehrer_nachname']);

        // Erst in DB eintragen, dann senden (Token muss existieren bevor Link geöffnet wird)
        $db->prepare(
            "INSERT INTO email_benachrichtigungen
             (klausur_id, empfaenger_id, typ, token, gesendet_am)
             VALUES (?, ?, ?, ?, NOW())"
        )->execute([$klausurId, $kl['lehrer_id'], $typ, $token]);

        Mailer::send($kl['lehrer_email'], $empfaengerName, $betreff, $htmlBody);

        $gesendet++;
        echo date('[H:i:s]') . " OK  ($typ): {$kursAnzeige} → {$kl['lehrer_email']}\n";

    } catch (Throwable $e) {
        $fehler++;
        echo date('[H:i:s]') . " ERR ($typ): {$kl['kurs_anzeigename']} → {$e->getMessage()}\n";
    }
}

// ------------------------------------------------------------------
// Übersicht für die Stufenleitung (nur Klausuren der eigenen Stufe(n))
// ------------------------------------------------------------------
// Wenn eine Woche nach dem Klausurtermin noch keine Anwesenheit erfasst ist, bekommt die
// Stufenleitung einmalig pro Klausur eine Sammelmail. Die Fachlehrkraft wird dadurch
// nicht zusätzlich angeschrieben.

$stufenleitungen = $db->query(
    "SELECT DISTINCT b.id, b.email, b.vorname, b.nachname
     FROM benutzer b
     JOIN rollen r           ON r.benutzer_id = b.id AND r.rolle = 'stufenleitung'
     JOIN stufenleitungen sl ON sl.benutzer_id = b.id
     WHERE b.email IS NOT NULL AND b.email <> ''"
)->fetchAll();

$offen = $db->prepare(
    "SELECT kl.id, kl.klausur_nr, kl.termin_datum,
            k.anzeigename AS kurs_anzeigename,
            s.name AS stufe, s.schuljahr,
            TRIM(CONCAT(COALESCE(lb.vorname, ''), ' ', COALESCE(lb.nachname, ''))) AS lehrkraft
     FROM klausuren kl
     JOIN kurse k     ON k.id = kl.kurs_id
     JOIN halbjahre h ON h.id = k.halbjahr_id
     JOIN stufen s    ON s.id = h.stufe_id
     JOIN stufenleitungen sl ON sl.stufe_id = s.id AND sl.benutzer_id = ?
     LEFT JOIN benutzer lb   ON lb.id = k.lehrer_id
     WHERE kl.termin_datum IS NOT NULL
       AND TIMESTAMP(kl.termin_datum, COALESCE(kl.termin_uhrzeit, '23:59:59')) < NOW() - INTERVAL 7 DAY
       AND EXISTS (SELECT 1 FROM kurs_schueler ks WHERE ks.kurs_id = k.id)
       AND NOT EXISTS (SELECT 1 FROM anwesenheiten a
                       WHERE a.klausur_id = kl.id AND a.status <> 'ausstehend')
       AND NOT EXISTS (SELECT 1 FROM stufenleitung_erinnerungen e
                       WHERE e.klausur_id = kl.id AND e.benutzer_id = sl.benutzer_id)
     ORDER BY s.schuljahr DESC, s.name, kl.termin_datum, k.anzeigename"
);

$slGesendet = 0;

foreach ($stufenleitungen as $sl) {
    $offen->execute([$sl['id']]);
    $klausurenOffen = $offen->fetchAll();
    if (empty($klausurenOffen)) {
        continue;
    }

    try {
        Mailer::send(
            $sl['email'],
            trim($sl['vorname'] . ' ' . $sl['nachname']),
            'Klausurplan: Anwesenheit noch nicht eingetragen',
            EmailTemplates::stufenleitungUebersicht($klausurenOffen),
        );

        $markieren = $db->prepare(
            'INSERT IGNORE INTO stufenleitung_erinnerungen (klausur_id, benutzer_id) VALUES (?, ?)'
        );
        foreach ($klausurenOffen as $kl) {
            $markieren->execute([$kl['id'], $sl['id']]);
        }

        $slGesendet++;
        echo date('[H:i:s]') . ' OK  (Stufenleitung): ' . count($klausurenOffen) . " Klausur(en) → {$sl['email']}\n";

    } catch (Throwable $e) {
        $fehler++;
        echo date('[H:i:s]') . " ERR (Stufenleitung): {$sl['email']} → {$e->getMessage()}\n";
    }
}

echo "\nFertig: {$gesendet} gesendet, {$slGesendet} Übersicht(en) an Stufenleitungen, {$fehler} Fehler.\n";
