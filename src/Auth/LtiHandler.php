<?php

declare(strict_types=1);

namespace Klausurplan\Auth;

use ceLTIc\LTI\Tool;
use ceLTIc\LTI\DataConnector\DataConnector;
use Klausurplan\Models\Database;
use PDO;

class LtiHandler extends Tool
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $connector = DataConnector::getDataConnector($this->db, 'lti_');
        parent::__construct($connector);
    }

    protected function onLaunch(): void
    {
        $userResult = $this->userResult;

        if ($userResult === null) {
            $this->ok     = false;
            $this->reason = 'Kein Nutzer im LTI-Launch enthalten.';
            return;
        }

        $moodleId = $userResult->ltiUserId ?? $userResult->getId();
        $vorname  = $userResult->firstname;
        $nachname = $userResult->lastname;
        $email    = $userResult->email ?: null;

        if (empty($moodleId)) {
            $this->ok     = false;
            $this->reason = 'LTI-Launch ohne Nutzer-ID.';
            return;
        }

        $kuerzel = $this->extraktKuerzel($nachname);

        $benutzer = $this->syncBenutzer($moodleId, $vorname, $nachname, $email, $kuerzel);
        $rollen   = $this->ladeRollen($benutzer['id']);

        if ($userResult->isAdmin() && !in_array('admin', $rollen, true)) {
            $this->weiseRolleZu($benutzer['id'], 'admin');
            $rollen[] = 'admin';
        }

        Session::start();
        Session::setBenutzer(
            $benutzer['id'],
            $moodleId,
            $vorname,
            $nachname,
            $rollen,
        );
        Session::updateZuletztGesehen();

        $appUrl = rtrim($_ENV['APP_URL'] ?? '', '/');
        header('Location: ' . $appUrl . '/');
        exit;
    }

    protected function onError(): void
    {
        $this->ok = false;
    }

    // --- private Hilfsmethoden ---

    private function extraktKuerzel(string $nachname): ?string
    {
        if (preg_match('/\(([A-ZÄÖÜa-zäöü]{1,10})\)$/', trim($nachname), $treffer)) {
            return strtoupper($treffer[1]);
        }

        return null;
    }

    private function syncBenutzer(
        string  $moodleId,
        string  $vorname,
        string  $nachname,
        ?string $email,
        ?string $kuerzel,
    ): array {
        $stmt = $this->db->prepare(
            'SELECT id, vorname, nachname, email, kuerzel FROM benutzer WHERE moodle_id = ?'
        );
        $stmt->execute([$moodleId]);
        $vorhandener = $stmt->fetch();

        if ($vorhandener === false) {
            $stmt = $this->db->prepare(
                'INSERT INTO benutzer (moodle_id, vorname, nachname, email, kuerzel, zuletzt_gesehen)
                 VALUES (?, ?, ?, ?, ?, NOW())'
            );
            $stmt->execute([$moodleId, $vorname, $nachname, $email, $kuerzel]);

            return [
                'id'       => (int) $this->db->lastInsertId(),
                'vorname'  => $vorname,
                'nachname' => $nachname,
            ];
        }

        $updates = [];
        $params  = [];

        if ($vorhandener['vorname'] !== $vorname) {
            $updates[] = 'vorname = ?';
            $params[]  = $vorname;
        }
        if ($vorhandener['nachname'] !== $nachname) {
            $updates[] = 'nachname = ?';
            $params[]  = $nachname;
        }
        if ($email !== null && $vorhandener['email'] !== $email) {
            $updates[] = 'email = ?';
            $params[]  = $email;
        }
        if ($kuerzel !== null && $vorhandener['kuerzel'] !== $kuerzel) {
            $updates[] = 'kuerzel = ?';
            $params[]  = $kuerzel;
        }

        if (!empty($updates)) {
            $params[] = $vorhandener['id'];
            $sql      = 'UPDATE benutzer SET ' . implode(', ', $updates) . ' WHERE id = ?';
            $this->db->prepare($sql)->execute($params);
        }

        return [
            'id'       => (int) $vorhandener['id'],
            'vorname'  => $vorname,
            'nachname' => $nachname,
        ];
    }

    private function ladeRollen(int $benutzerId): array
    {
        $stmt = $this->db->prepare('SELECT rolle FROM rollen WHERE benutzer_id = ?');
        $stmt->execute([$benutzerId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function weiseRolleZu(int $benutzerId, string $rolle): void
    {
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO rollen (benutzer_id, rolle) VALUES (?, ?)'
        );
        $stmt->execute([$benutzerId, $rolle]);
    }
}
