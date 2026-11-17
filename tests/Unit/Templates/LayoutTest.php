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

    public function testMeDatenWerdenEingebettetUndSicherKodiert(): void
    {
        $this->alsBenutzer(7, ['stufenleitung'], 'Anna', '</script><b>');
        $this->db->onRows('/FROM stufenleitungen sl/', [['id' => 3, 'name' => 'Q1', 'schuljahr' => '2026/2027']]);

        $html = $this->rendere();

        $this->assertStringContainsString('window.KLAUSURPLAN_ME     = {"id":7,', $html);
        $this->assertStringContainsString('"stufen":[{"id":3,"name":"Q1","schuljahr":"2026\/2027"}]};', $html);
        $this->assertStringContainsString('\u003C\/script\u003E\u003Cb\u003E', $html, 'kein </script> aus Nutzerdaten im Skriptblock');
        $this->assertSame(1, substr_count($html, '</script>') - 1, 'nur die eigenen Script-Tags werden geschlossen');
    }

    public function testOhneDatenbankBleibtMeDatenLeer(): void
    {
        $this->alsBenutzer(7, ['stufenleitung']);
        $this->db->on('/FROM stufenleitungen/', fn () => throw new \PDOException('weg'));
        $altesLog = ini_set('error_log', tempnam(sys_get_temp_dir(), 'kp-log'));

        try {
            $html = $this->rendere();
        } finally {
            ini_set('error_log', (string) $altesLog);
        }

        $this->assertStringContainsString('window.KLAUSURPLAN_ME     = null;', $html, 'Seite lädt trotzdem, das Frontend holt /me selbst');
        $this->assertStringContainsString('<main id="app">', $html);
    }
}
