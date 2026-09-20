<?php

declare(strict_types=1);

namespace Klausurplan\Tests\Unit\Templates;

use Klausurplan\Tests\Support\TestCase;

final class LayoutTest extends TestCase
{
    private function rendere(): string
    {
        return $this->ausgabe(function (): void {
            include __DIR__ . '/../../../public/templates/layout.php';
        });
    }

    public function testAssetsBekommenEineVersionUndDieRollenGehenAnsFrontend(): void
    {
        $this->alsBenutzer(7, ['stufenleitung', 'lehrkraft'], 'Anna', 'Lehrer <b>');

        $html = $this->rendere();

        $this->assertMatchesRegularExpression('#/assets/app\.css\?v=[1-9]\d+#', $html);
        $this->assertMatchesRegularExpression('#/assets/app\.js\?v=[1-9]\d+#', $html);
        $this->assertStringContainsString('window.KLAUSURPLAN_ROLLEN = ["stufenleitung","lehrkraft"];', $html);
        $this->assertStringContainsString('window.KLAUSURPLAN_ME_ID  = 7;', $html);
        $this->assertStringContainsString('Lehrer &lt;b&gt;', $html, 'Namen werden escaped');
        $this->assertStringContainsString('<nav id="nav">', $html);
    }

    public function testReineSchuelerBekommenKeineKopfzeileUndKeineNavigation(): void
    {
        $this->alsBenutzer(8, ['schueler']);

        $html = $this->rendere();

        $this->assertStringNotContainsString('<nav id="nav">', $html);
        $this->assertStringNotContainsString('<header>', $html);
        $this->assertStringContainsString('<main id="app">', $html);
    }
}
