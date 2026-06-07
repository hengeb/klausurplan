<?php

declare(strict_types=1);

namespace Klausurplan\Api;

use Klausurplan\Auth\Session;

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

            try {
                $result = ($route['handler'])($params);
                echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                http_response_code(500);
                echo json_encode(['fehler' => 'JSON-Serialisierungsfehler']);
            }

            return;
        }

        http_response_code(404);
        echo json_encode(['fehler' => 'Endpunkt nicht gefunden', 'pfad' => $this->path]);
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

    public static function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if (empty($raw)) {
            return [];
        }

        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR) ?? [];
        } catch (\JsonException) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['fehler' => 'Ungültiger JSON-Body']);
            exit;
        }
    }
}
