<?php

declare(strict_types=1);

namespace Klausurplan\Api;

use Klausurplan\Auth\Session;
use Klausurplan\Models\Database;

class MeController
{
    public static function handle(): array
    {
        $benutzer = Session::getBenutzer();

        // Stufenleitungs-Zuordnungen nachladen
        $stufenIds = [];
        if (in_array('stufenleitung', $benutzer['rollen'], true)) {
            $db   = Database::getInstance();
            $stmt = $db->prepare(
                'SELECT s.id, s.name, s.schuljahr
                 FROM stufenleitungen sl
                 JOIN stufen s ON sl.stufe_id = s.id
                 WHERE sl.benutzer_id = ?'
            );
            $stmt->execute([$benutzer['id']]);
            $stufenIds = $stmt->fetchAll();
        }

        return [
            'id'       => $benutzer['id'],
            'vorname'  => $benutzer['vorname'],
            'nachname' => $benutzer['nachname'],
            'rollen'   => $benutzer['rollen'],
            'stufen'   => $stufenIds,
        ];
    }
}
