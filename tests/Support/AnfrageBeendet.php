<?php

declare(strict_types=1);

namespace Klausurplan\Tests\Support;

use RuntimeException;

/** Signalisiert in Tests, dass der Code die Anfrage beenden wollte (statt `exit`). */
final class AnfrageBeendet extends RuntimeException {}
