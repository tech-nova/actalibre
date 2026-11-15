<?php
declare(strict_types=1);

namespace ActaLibre\Tests\Geoloc;

use PHPUnit\Framework\TestCase;

/** Le recalcul des statistiques ne contacte aucun service de géocodage. */
final class SeparationTest extends TestCase
{
    private function fonction(string $nom): string
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/tools/actutils.php');
        self::assertSame(1, preg_match('~\nfunction ' . $nom . '\(.*?(?=\nfunction )~s', $source, $m), $nom);
        return $m[0];
    }

    public function testMajStatsNAppelleAucunGeocodage(): void
    {
        $corps = $this->fonction('maj_stats');
        self::assertStringNotContainsString('geoloc_1_com', $corps);
        self::assertStringNotContainsString('geocoder_commune', $corps);
        self::assertStringContainsString('geoloc_fiches()->creerManquante(', $corps);
        self::assertStringContainsString('signaler_sans_coordonnees(', $corps);
    }

    public function testLAncienGeocodageImpliciteADisparu(): void
    {
        self::assertStringNotContainsString('function geoloc_1_com', (string) file_get_contents(dirname(__DIR__, 2) . '/tools/actutils.php'));
    }
}
