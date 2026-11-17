<?php

declare(strict_types=1);

namespace Klausurplan\Mail;

/**
 * HTML-Vorlagen für die Anwesenheits-Mails.
 *
 * Bewusst „altmodisch“ gebaut, damit Outlook (Desktop, Word-Rendering) sie sauber darstellt:
 * Layout über Tabellen mit festen Breiten, Farben zusätzlich als bgcolor-Attribut, nur px-Werte,
 * Inline-Styles an jeder Zelle, Buttons als Tabellenzellen (Outlook ignoriert padding,
 * border-radius und display:inline-block an Links sowie max-width und rem).
 */
class EmailTemplates
{
    private const SCHRIFT = 'font-family:Arial,Helvetica,sans-serif;';

    /**
     * Erstmeldung: Lehrkraft wird gebeten, die Anwesenheit einzutragen.
     *
     * @param array{kurs_anzeigename: string, termin_datum: ?string} $klausur
     */
    public static function erstmeldung(array $klausur, string $token): string
    {
        $kursname = htmlspecialchars($klausur['kurs_anzeigename'] ?? '');
        $datum    = self::formatDatum($klausur['termin_datum'] ?? null);

        return self::layout(
            "Anwesenheit eintragen – {$kursname}",
            self::absatz('Bitte trage die Anwesenheit für deine Klausur ein.') .
            self::klausurInfo($kursname, $datum) .
            self::eintragenButton($token)
        );
    }

    /**
     * Erinnerungsmail: Solange nach der Erstmeldung noch keine Anwesenheit erfasst ist,
     * schickt der Cronjob diese Mail täglich erneut.
     *
     * @param array{kurs_anzeigename: string, termin_datum: ?string} $klausur
     */
    public static function erinnerung(array $klausur, string $token): string
    {
        $kursname = htmlspecialchars($klausur['kurs_anzeigename'] ?? '');
        $datum    = self::formatDatum($klausur['termin_datum'] ?? null);

        return self::layout(
            "Erinnerung: Anwesenheit eintragen – {$kursname}",
            self::absatz('<strong>Erinnerung:</strong> Die Anwesenheit für deine folgende Klausur wurde noch nicht eingetragen.') .
            self::klausurInfo($kursname, $datum) .
            self::eintragenButton($token)
        );
    }

    // ------------------------------------------------------------------

    private static function formatDatum(?string $datum): string
    {
        return $datum ? date('d.m.Y', (int) strtotime($datum)) : '–';
    }

    /**
     * Ein einziger Link zur Eingabeseite. Dort wählt die Lehrkraft „Alle waren anwesend“ oder gibt
     * Fehlende einzeln an – gespeichert wird erst dort per POST, damit Link-Scanner von
     * Mail-Sicherheitsfiltern nichts auslösen können.
     */
    private static function eintragenUrl(string $token): string
    {
        return rtrim($_ENV['APP_URL'] ?? '', '/') . '/anwesenheit/eingabe?token=' . urlencode($token);
    }

    private static function absatz(string $html): string
    {
        return '<p style="margin:0 0 16px 0;' . self::SCHRIFT . 'font-size:15px;line-height:22px;color:#333333;">'
            . $html . '</p>';
    }

    /** Kurs und Datum als kleine, hinterlegte Tabelle. */
    private static function klausurInfo(string $kursname, string $datum): string
    {
        $zelle = 'style="padding:4px 12px 4px 0;' . self::SCHRIFT . 'font-size:15px;line-height:22px;color:#333333;"';
        return <<<HTML
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#f2f5f8" style="background-color:#f2f5f8;margin:0 0 24px 0;">
            <tr><td style="padding:12px 16px;">
                <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                    <tr><td {$zelle}><strong>Kurs:</strong></td><td {$zelle}>{$kursname}</td></tr>
                    <tr><td {$zelle}><strong>Datum:</strong></td><td {$zelle}>{$datum}</td></tr>
                </table>
            </td></tr>
        </table>
        HTML;
    }

    /** Ein Button als Tabellenzelle (in Outlook die einzige zuverlässige Variante). */
    private static function button(string $url, string $text, string $farbe): string
    {
        $url     = htmlspecialchars($url);
        $schrift = self::SCHRIFT;
        return <<<HTML
        <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 12px 0;">
            <tr><td align="center" bgcolor="{$farbe}" style="background-color:{$farbe};padding:12px 24px;">
                <a href="{$url}" style="{$schrift}font-size:15px;font-weight:bold;line-height:20px;color:#ffffff;text-decoration:none;">{$text}</a>
            </td></tr>
        </table>
        HTML;
    }

    private static function eintragenButton(string $token): string
    {
        return self::button(self::eintragenUrl($token), 'Anwesenheit eintragen', '#1a3a5c')
            . self::absatz('<span style="font-size:13px;color:#666666;">Dort kannst du mit einem Klick bestätigen, '
                . 'dass alle anwesend waren, oder Fehlende einzeln angeben.</span>');
    }

    /** $titelEsc muss bereits HTML-escaped sein (enthält den escapten Kursnamen). */
    private static function layout(string $titelEsc, string $inhalt): string
    {
        $schrift = self::SCHRIFT;
        return <<<HTML
        <!DOCTYPE html>
        <html lang="de">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>{$titelEsc}</title>
        </head>
        <body style="margin:0;padding:0;background-color:#f5f5f5;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#f5f5f5" style="background-color:#f5f5f5;">
                <tr><td align="center" style="padding:24px 12px;">
                    <table role="presentation" width="560" cellspacing="0" cellpadding="0" border="0" bgcolor="#ffffff" style="width:560px;background-color:#ffffff;border:1px solid #e0e0e0;">
                        <tr><td style="padding:28px 32px 8px 32px;">
                            <h2 style="margin:0 0 20px 0;{$schrift}font-size:20px;line-height:26px;font-weight:bold;color:#1a3a5c;">{$titelEsc}</h2>
                            {$inhalt}
                        </td></tr>
                        <tr><td style="padding:16px 32px 24px 32px;border-top:1px solid #e0e0e0;{$schrift}font-size:12px;line-height:18px;color:#888888;">
                            Diese E-Mail wurde automatisch vom Klausurplan-System versandt. Bitte antworte nicht direkt auf diese Nachricht.
                        </td></tr>
                    </table>
                </td></tr>
            </table>
        </body>
        </html>
        HTML;
    }
}
