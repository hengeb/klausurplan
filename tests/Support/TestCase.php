<?php

declare(strict_types=1);

namespace Klausurplan\Tests\Support;

use Klausurplan\Models\Database;

/** Basisklasse der Unit-Tests: die Datenbank ist eine FakePdo (siehe FakePdo). */
abstract class TestCase extends BaseTestCase
{
    protected FakePdo $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new FakePdo();
        Database::setInstance($this->db);
    }
}
