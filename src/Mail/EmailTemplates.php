<?php

declare(strict_types=1);

namespace Klausurplan\Mail;

class EmailTemplates
{
    /**
     * Erstmeldung: Lehrkraft wird gebeten, die Anwesenheit einzutragen.
     *
     * @param array{kurs_anzeigename: string, termin_datum: ?string} $klausur
     */
    public static function erstmeldung(array $klausur, string $token): string
    {
        $kursname = htmlspecialchars($klausur['kurs_anzeigename'] ?? '');
        $datum    = self::formatDatum($klausur['termin_datum'] ?? null);
        [$urlAlleDa, $urlEingabe] = self::tokenUrls($token);

        return self::layout(
            "Anwesenheit eintragen – {$kursname}",
            "<p>Bitte tragen Sie die Anwesenheit für Ihre Klausur ein.</p>
            <p><strong>Kurs:</strong> {$kursname}<br><strong>Datum:</strong> {$datum}</p>" .
            self::buttons($urlAlleDa, $urlEingabe)
        );
    }

    /**
     * Erinnerungsmail, wenn 7 Tage nach Erstmeldung noch keine Antwort eingegangen ist.
     *
     * @param array{kurs_anzeigename: string, termin_datum: ?string} $klausur
     */
    public static function erinnerung(array $klausur, string $token): string
    {
        $kursname = htmlspecialchars($klausur['kurs_anzeigename'] ?? '');
        $datum    = self::formatDatum($klausur['termin_datum'] ?? null);
        [$urlAlleDa, $urlEingabe] = self::tokenUrls($token);

        return self::layout(
            "Erinnerung: Anwesenheit eintragen – {$kursname}",
            "<p><strong>Erinnerung:</strong> Die Anwesenheit für Ihre folgende Klausur wurde noch nicht eingetragen.</p>
            <p><strong>Kurs:</strong> {$kursname}<br><strong>Datum:</strong> {$datum}</p>" .
            self::buttons($urlAlleDa, $urlEingabe)
        );
    }

    // ------------------------------------------------------------------

    private static function formatDatum(?string $datum): string
    {
        return $datum ? date('d.m.Y', (int) strtotime($datum)) : '–';
    }

    /** @return array{string, string} [urlAlleDa, urlEingabe] */
    private static function tokenUrls(string $token): array
    {
        $appUrl = rtrim($_ENV['APP_URL'] ?? '', '/');
        $t      = urlencode($token);
        return [
            "{$appUrl}/anwesenheit/alle-da?token={$t}",
            "{$appUrl}/anwesenheit/eingabe?token={$t}",
        ];
    }

    private static function buttons(string $urlAlleDa, string $urlEingabe): string
    {
        $urlAlleDa  = htmlspecialchars($urlAlleDa);
        $urlEingabe = htmlspecialchars($urlEingabe);
        return <<<HTML
        <p style="margin-top:1.5rem">
            <a href="{$urlAlleDa}"
               style="display:inline-block;padding:.6rem 1.4rem;background:#27ae60;color:#fff;border-radius:4px;text-decoration:none;font-weight:bold;margin-right:.75rem">
                Alle waren anwesend
            </a>
            <a href="{$urlEingabe}"
               style="display:inline-block;padding:.6rem 1.4rem;background:#1a3a5c;color:#fff;border-radius:4px;text-decoration:none;font-weight:bold">
                Jemand hat gefehlt
            </a>
        </p>
        HTML;
    }

    private static function layout(string $titel, string $inhalt): string
    {
        $titelEsc = htmlspecialchars($titel);
        return <<<HTML
        <!DOCTYPE html>
        <html lang="de">
        <head>
            <meta charset="UTF-8">
            <title>{$titelEsc}</title>
        </head>
        <body style="font-family:Arial,sans-serif;color:#333;background:#f5f5f5;margin:0;padding:2rem">
            <div style="max-width:560px;margin:0 auto;background:#fff;border-radius:8px;padding:2rem;border:1px solid #e0e0e0">
                <h2 style="color:#1a3a5c;margin-top:0">{$titelEsc}</h2>
                {$inhalt}
                <hr style="border:none;border-top:1px solid #e0e0e0;margin:2rem 0">
                <p style="font-size:.8rem;color:#888">Diese E-Mail wurde automatisch vom Klausurplan-System versandt. Bitte antworten Sie nicht direkt auf diese Nachricht.</p>
            </div>
        </body>
        </html>
        HTML;
    }
}
