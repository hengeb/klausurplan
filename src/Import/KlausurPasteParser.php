<?php

declare(strict_types=1);

namespace Klausurplan\Import;

use RuntimeException;

/**
 * Validiert und normalisiert tab-getrennte Klausurdaten,
 * die der Browser aus einem Excel-Paste gewonnen hat.
 *
 * Erwartet wird ein Array von assoziativen Arrays (Keys = Spaltenüberschriften).
 * Gibt ein Array aus validierten Zeilen und einem Fehler-Array zurück.
 */
class KlausurPasteParser
{
    /** Erlaubte Spaltenüberschriften (case-insensitive) */
    private const PFLICHTFELDER = ['kurs'];
    private const ALLE_FELDER   = ['kurs', 'datum', 'uhrzeit', 'dauer', 'raum'];

    /**
     * Parsed und validiert rohe Paste-Zeilen.
     *
     * @param  array[] $roheZeilen  Jede Zeile: ['Kurs' => '...', 'Datum' => '...', ...]
     * @return array{ zeilen: array[], fehler: array[] }
     */
    public static function parse(array $roheZeilen): array
    {
        $zeilen = [];
        $fehler = [];

        foreach ($roheZeilen as $i => $zeile) {
            // Keys case-insensitiv normalisieren
            $norm = [];
            foreach ($zeile as $k => $v) {
                $norm[strtolower(trim($k))] = trim((string) $v);
            }

            // Pflichtfelder prüfen
            if (empty($norm['kurs'])) {
                $fehler[] = ['zeile' => $i + 1, 'meldung' => 'Kurs fehlt.'];
                continue;
            }

            $datum = self::parseDatum($norm['datum'] ?? '');
            if (($norm['datum'] ?? '') !== '' && $datum === null) {
                $fehler[] = ['zeile' => $i + 1, 'meldung' => 'Ungültiges Datum: "' . $norm['datum'] . '". Erwartet: TT.MM.JJJJ'];
                continue;
            }

            $uhrzeit = self::parseUhrzeit($norm['uhrzeit'] ?? '');
            if (($norm['uhrzeit'] ?? '') !== '' && $uhrzeit === null) {
                $fehler[] = ['zeile' => $i + 1, 'meldung' => 'Ungültige Uhrzeit: "' . $norm['uhrzeit'] . '". Erwartet: HH:MM'];
                continue;
            }

            $dauer = self::parseDauer($norm['dauer'] ?? '');
            if (($norm['dauer'] ?? '') !== '' && $dauer === null) {
                $fehler[] = ['zeile' => $i + 1, 'meldung' => 'Ungültige Dauer: "' . $norm['dauer'] . '". Erwartet: Zahl in Minuten'];
                continue;
            }

            $zeilen[] = [
                'kurs_kuerzel'  => $norm['kurs'],
                'termin_datum'  => $datum,
                'termin_uhrzeit'=> $uhrzeit,
                'dauer_minuten' => $dauer,
                'raum'          => ($norm['raum'] ?? '') !== '' ? $norm['raum'] : null,
            ];
        }

        return ['zeilen' => $zeilen, 'fehler' => $fehler];
    }

    /** TT.MM.JJJJ → JJJJ-MM-TT oder null bei Fehler */
    public static function parseDatum(string $str): ?string
    {
        $str = trim($str);
        if ($str === '') {
            return null;
        }

        if (preg_match('#^(\d{1,2})\.(\d{1,2})\.(\d{4})$#', $str, $m)) {
            $tag   = (int) $m[1];
            $monat = (int) $m[2];
            $jahr  = (int) $m[3];

            if (!checkdate($monat, $tag, $jahr)) {
                return null;
            }

            return sprintf('%04d-%02d-%02d', $jahr, $monat, $tag);
        }

        return null;
    }

    /** H:MM oder HH:MM → HH:MM:00 oder null */
    public static function parseUhrzeit(string $str): ?string
    {
        $str = trim($str);
        if ($str === '') {
            return null;
        }

        if (preg_match('#^(\d{1,2}):(\d{2})$#', $str, $m)) {
            $h = (int) $m[1];
            $i = (int) $m[2];

            if ($h > 23 || $i > 59) {
                return null;
            }

            return sprintf('%02d:%02d:00', $h, $i);
        }

        return null;
    }

    /** Zahl (Minuten) → int oder null */
    public static function parseDauer(string $str): ?int
    {
        $str = trim($str);
        if ($str === '') {
            return null;
        }

        if (ctype_digit($str) && (int) $str > 0) {
            return (int) $str;
        }

        return null;
    }
}
