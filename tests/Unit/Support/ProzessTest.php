<?php

declare(strict_types=1);

namespace Klausurplan\Tests\Unit\Support;

use Klausurplan\Support\Prozess;
use Klausurplan\Tests\Support\AnfrageBeendet;
use Klausurplan\Tests\Support\TestCase;

final class ProzessTest extends TestCase
{
    public function testBeendenRuftDenHandlerAuf(): void
    {
        $this->expectException(AnfrageBeendet::class);

        Prozess::beenden();
    }
}
