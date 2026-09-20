<?php

/**
 * Minimaler SMTP-Server für Tests: nimmt jede Mail an und hängt sie an eine Datei an.
 * Aufruf: php smtp_senke.php <port> <ausgabedatei>
 * Unterstützt EHLO, AUTH (LOGIN/PLAIN), MAIL, RCPT, DATA, QUIT – kein TLS.
 */

declare(strict_types=1);

[, $port, $datei] = $argv;

$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "SMTP-Senke: $errstr\n");
    exit(1);
}

while (true) {
    $verbindung = @stream_socket_accept($server, 3600);
    if ($verbindung === false) {
        continue;
    }

    $antwort = static fn (string $zeile) => fwrite($verbindung, $zeile . "\r\n");
    $antwort('220 senke bereit');
    $daten = false;
    $mail  = '';

    while (($zeile = fgets($verbindung)) !== false) {
        if ($daten) {
            if ($zeile === ".\r\n") {
                file_put_contents($datei, $mail . "\n=====MAIL-ENDE=====\n", FILE_APPEND | LOCK_EX);
                $mail  = '';
                $daten = false;
                $antwort('250 OK');
            } else {
                $mail .= str_starts_with($zeile, '..') ? substr($zeile, 1) : $zeile;
            }
            continue;
        }

        $befehl = strtoupper(trim($zeile));
        if (str_starts_with($befehl, 'EHLO') || str_starts_with($befehl, 'HELO')) {
            fwrite($verbindung, "250-senke\r\n250-AUTH LOGIN PLAIN\r\n250 8BITMIME\r\n");
        } elseif (str_starts_with($befehl, 'AUTH LOGIN')) {
            $antwort('334 VXNlcm5hbWU6');
            fgets($verbindung);
            $antwort('334 UGFzc3dvcmQ6');
            fgets($verbindung);
            $antwort('235 ok');
        } elseif (str_starts_with($befehl, 'AUTH')) {
            $antwort('235 ok');
        } elseif (str_starts_with($befehl, 'DATA')) {
            $daten = true;
            $antwort('354 los');
        } elseif (str_starts_with($befehl, 'QUIT')) {
            $antwort('221 tschüss');
            break;
        } else {
            $antwort('250 OK');
        }
    }
    fclose($verbindung);
}
