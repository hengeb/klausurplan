<?php

declare(strict_types=1);

namespace Klausurplan\Tests\Support;

use Klausurplan\Auth\MoodleApi;

/** MoodleApi mit vorgegebener Nutzerliste statt HTTP-Abruf. */
final class MoodleApiMitNutzern extends MoodleApi
{
    /** @param list<array<string, mixed>> $nutzer */
    public function __construct(private readonly array $nutzer)
    {
        parent::__construct();
    }

    protected function alleNutzer(): array
    {
        return $this->nutzer;
    }
}
