<?php

declare(strict_types=1);

namespace Klausurplan\Auth;

use Klausurplan\Models\Database;

class Session
{
    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started) {
            return;
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_name('klausurplan_session');
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'secure'   => isset($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }

        self::$started = true;
    }

    public static function setBenutzer(int $id, string $moodleId, string $vorname, string $nachname, array $rollen): void
    {
        session_regenerate_id(true);

        $_SESSION['benutzer_id']  = $id;
        $_SESSION['moodle_id']    = $moodleId;
        $_SESSION['vorname']      = $vorname;
        $_SESSION['nachname']     = $nachname;
        $_SESSION['rollen']       = $rollen;
        $_SESSION['eingeloggt_am'] = time();
    }

    public static function isAuthenticated(): bool
    {
        return isset($_SESSION['benutzer_id']);
    }

    public static function getBenutzer(): ?array
    {
        if (!self::isAuthenticated()) {
            return null;
        }

        return [
            'id'       => $_SESSION['benutzer_id'],
            'moodle_id' => $_SESSION['moodle_id'],
            'vorname'  => $_SESSION['vorname'],
            'nachname' => $_SESSION['nachname'],
            'rollen'   => $_SESSION['rollen'],
        ];
    }

    public static function getBenutzerId(): ?int
    {
        return $_SESSION['benutzer_id'] ?? null;
    }

    public static function hasRolle(string $rolle): bool
    {
        return in_array($rolle, $_SESSION['rollen'] ?? [], true);
    }

    public static function requireAuth(): void
    {
        if (!self::isAuthenticated()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['fehler' => 'Nicht authentifiziert']);
            exit;
        }
    }

    public static function requireRolle(string ...$rollen): void
    {
        self::requireAuth();

        foreach ($rollen as $rolle) {
            if (self::hasRolle($rolle)) {
                return;
            }
        }

        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['fehler' => 'Keine Berechtigung']);
        exit;
    }

    public static function updateZuletztGesehen(): void
    {
        $id = self::getBenutzerId();
        if ($id === null) {
            return;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare('UPDATE benutzer SET zuletzt_gesehen = NOW() WHERE id = ?');
        $stmt->execute([$id]);
    }

    public static function destroy(): void
    {
        session_unset();
        session_destroy();
        self::$started = false;
    }
}
