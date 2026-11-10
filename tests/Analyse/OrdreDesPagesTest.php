<?php
declare(strict_types=1);

namespace ActaLibre\Tests\Analyse;

use PHPUnit\Framework\TestCase;

final class OrdreDesPagesTest extends TestCase
{
    private function source(string $page): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $page);
    }

    public function testLeSignalementIdentifieAvantDEcrire(): void
    {
        $source = $this->source('signal_erreur.php');
        $identification = strpos($source, 'logonok(1)');
        self::assertIsInt($identification);
        self::assertLessThan(strpos($source, 'open_page('), $identification);
    }

    public function testLaSuppressionDUnActeTransmetLeDepartement(): void
    {
        self::assertStringContainsString('maj_stats($xtyp, $T0, $path, "C", $ligne[3], $ligne[4])', $this->source('admin/suppr_acte.php'));
    }
}
