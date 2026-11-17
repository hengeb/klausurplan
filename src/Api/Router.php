<?php

declare(strict_types=1);

namespace Klausurplan\Api;

use Klausurplan\Auth\Session;
use Klausurplan\Models\Database;
use Klausurplan\Support\Prozess;

class Router
{
    private string $method;
    private string $path;
    /** @var array<array{method: string, pattern: string, handler: callable, rollen: string[]}> */
    private array $routes = [];

    public function __construct()
    {
        $this->method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $this->path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

        // API-Pfad-Präfix abschneiden
        $prefix = '/api';
        if (str_starts_with($this->path, $prefix)) {
            $this->path = substr($this->path, strlen($prefix)) ?: '/';
        }
    }

    public function get(string $pattern, callable $handler, string ...$rollen): void
    {
        $this->routes[] = ['method' => 'GET', 'pattern' => $pattern, 'handler' => $handler, 'rollen' => $rollen];
    }

    public function post(string $pattern, callable $handler, string ...$rollen): void
    {
        $this->routes[] = ['method' => 'POST', 'pattern' => $pattern, 'handler' => $handler, 'rollen' => $rollen];
    }

    public function put(string $pattern, callable $handler, string ...$rollen): void
    {
        $this->routes[] = ['method' => 'PUT', 'pattern' => $pattern, 'handler' => $handler, 'rollen' => $rollen];
    }

    public function delete(string $pattern, callable $handler, string ...$rollen): void
    {
        $this->routes[] = ['method' => 'DELETE', 'pattern' => $pattern, 'handler' => $handler, 'rollen' => $rollen];
    }

    public function dispatch(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        foreach ($this->routes as $route) {
            if ($route['method'] !== $this->method) {
                continue;
            }

            $params = $this->match($route['pattern'], $this->path);
            if ($params === null) {
                continue;
            }

            // Rollenprüfung
            if (!empty($route['rollen'])) {
                Session::requireRolle(...$route['rollen']);
            }

            $handlerBeginn = hrtime(true);
            try {
                $result  = ($route['handler'])($params);
                $antwort = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                http_response_code(500);
                $antwort = json_encode(['fehler' => 'JSON-Serialisierungsfehler']);
            } catch (\RuntimeException $e) {
                // Handler setzen bei Bedarf selbst 403/404/409/422 – nur ohne Fehlerstatus gilt 400
                $status = http_response_code();
                if (!is_int($status) || $status < 400) {
                    http_response_code(400);
                }
                $antwort = json_encode(['fehler' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            } catch (\Throwable $e) {
                http_response_code(500);
                error_log('API-Fehler: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                $antwort = json_encode(['fehler' => 'Interner Serverfehler'], JSON_UNESCAPED_UNICODE);
            }

            self::sendeServerTiming(
                (hrtime(true) - $handlerBeginn) / 1e6,
                Database::verbindungsdauerMs(),
                isset($_SERVER['REQUEST_TIME_FLOAT']) ? (microtime(true) - (float) $_SERVER['REQUEST_TIME_FLOAT']) * 1000 : null,
            );
            echo $antwort;

            return;
        }

        http_response_code(404);
        echo json_encode(['fehler' => 'Endpunkt nicht gefunden', 'pfad' => $this->path]);
    }

    /**
     * Server-Timing-Header für die Entwicklertools des Browsers (Netzwerk → Timing):
     * gesamt = seit Anfragebeginn (inkl. PHP-Start, Autoloader, .env, Session),
     * db-verbindung = Verbindungsaufbau zur Datenbank, handler = Fachlogik inkl. SQL (ohne Verbindungsaufbau).
     */
    public static function serverTimingWert(float $handlerMs, ?float $dbVerbindungMs, ?float $gesamtMs): string
    {
        $teile = [];
        if ($gesamtMs !== null) {
            $teile[] = sprintf('gesamt;desc="PHP gesamt";dur=%.1F', $gesamtMs);
        }
        if ($dbVerbindungMs !== null) {
            $teile[] = sprintf('db-verbindung;desc="DB-Verbindung";dur=%.1F', $dbVerbindungMs);
            $handlerMs = max(0.0, $handlerMs - $dbVerbindungMs);
        }
        $teile[] = sprintf('handler;desc="Fachlogik + SQL";dur=%.1F', $handlerMs);
        return implode(', ', $teile);
    }

    private static function sendeServerTiming(float $handlerMs, ?float $dbVerbindungMs, ?float $gesamtMs): void
    {
        if (!headers_sent()) {
            header('Server-Timing: ' . self::serverTimingWert($handlerMs, $dbVerbindungMs, $gesamtMs));
        }
    }

    /**
     * Gibt Pfad-Parameter zurück wenn das Muster passt, sonst null.
     * Muster-Syntax: /pfad/{param}/rest
     */
    private function match(string $pattern, string $path): ?array
    {
        $regex = preg_replace('/\{([a-z_]+)\}/', '(?P<$1>[^/]+)', $pattern);
        $regex = '@^' . $regex . '$@';

        if (!preg_match($regex, $path, $treffer)) {
            return null;
        }

        return array_filter($treffer, 'is_string', ARRAY_FILTER_USE_KEY);
    }

    /**
     * Liest den JSON-Body der Anfrage. Ungültiges JSON (oder etwas anderes als ein Objekt/Array)
     * beendet die Anfrage mit HTTP 400. $roh dient nur Tests; sonst wird php://input gelesen.
     */
    public static function jsonBody(?string $roh = null): array
    {
        $raw = $roh ?? file_get_contents('php://input');
        if (empty($raw)) {
            return [];
        }

        try {
            $daten = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $daten = false;
        }

        if ($daten !== null && !is_array($daten)) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['fehler' => 'Ungültiger JSON-Body']);
            Prozess::beenden();
        }

        return $daten ?? [];
    }
}
