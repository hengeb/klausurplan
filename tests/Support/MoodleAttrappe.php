<?php

declare(strict_types=1);

namespace Klausurplan\Tests\Support;

use RuntimeException;

/** Startet den PHP-Entwicklungsserver mit tests/Support/moodle_attrappe.php. */
final class MoodleAttrappe
{
    /** @var resource|null */
    private $prozess;
    public readonly string $url;

    public function __construct()
    {
        $port = random_int(20000, 60000);
        $this->url = "http://127.0.0.1:$port";
        $this->prozess = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/moodle_attrappe.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );

        for ($i = 0; $i < 50; $i++) {
            $verbindung = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($verbindung !== false) {
                fclose($verbindung);
                return;
            }
            usleep(100_000);
        }
        throw new RuntimeException('Moodle-Attrappe startet nicht');
    }

    public function __destruct()
    {
        if (is_resource($this->prozess)) {
            proc_terminate($this->prozess);
            proc_close($this->prozess);
        }
    }
}
