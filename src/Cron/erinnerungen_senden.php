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

echo "\nFertig: {$gesendet} gesendet, {$fehler} Fehler.\n";
