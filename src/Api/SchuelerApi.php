<?php

declare(strict_types=1);

namespace Klausurplan\Api;

use Klausurplan\Auth\Session;
use Klausurplan\Models\Database;

class SchuelerApi
{
    /**
     * Gibt alle Klausurtermine des eingeloggten Schülers zurück.
     * Schüler*innen sehen ausschließlich ihre eigenen Termine.
     *
     * @return list<array{kurs_anzeigename: string, kursart: string, klausur_nr: int,
     *                     termin_datum: ?string, termin_uhrzeit: ?string,
     *                     dauer_minuten: ?int, raum: ?string}>
     */
    public static function meineKlausuren(): array
    {
        Session::requireRolle('schueler');
        $db       = Database::getInstance();
        $benutzer = Session::getBenutzer();

        $stmt = $db->prepare(
            "SELECT k.anzeigename   AS kurs_anzeigename,
                    k.kursart,
                    kl.klausur_nr,
                    kl.termin_datum,
                    kl.termin_uhrzeit,
                    kl.dauer_minuten,
                    kl.raum
             FROM kurs_schueler ks
             JOIN kurse k      ON k.id     = ks.kurs_id
             JOIN klausuren kl ON kl.kurs_id = k.id
             WHERE ks.schueler_id = ?
             ORDER BY
                 CASE WHEN kl.termin_datum IS NULL THEN 1 ELSE 0 END,
                 kl.termin_datum,
                 kl.termin_uhrzeit,
                 k.anzeigename,
                 kl.klausur_nr"
        );
        $stmt->execute([$benutzer['id']]);

        return $stmt->fetchAll();
    }
}
