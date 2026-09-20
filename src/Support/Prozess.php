<?php

declare(strict_types=1);

namespace Klausurplan\Support;

use Closure;

/**
 * Zentrale Stelle zum Beenden einer Anfrage.
 *
 * Produktiv beendet `beenden()` den PHP-Prozess (`exit`). Tests hinterlegen einen
 * Handler, der stattdessen eine Exception wirft – so lassen sich Zugriffsverweigerungen,
 * Token-Seiten und Downloads ohne Subprozess prüfen.
 */
final class Prozess
{
    /** @var (Closure(): never)|null */
    public static ?Closure $beendenHandler = null;

    public static function beenden(): never
    {
        if (self::$beendenHandler !== null) {
            (self::$beendenHandler)();
        }

        exit;
    }
}
