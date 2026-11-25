<?php
declare(strict_types=1);

namespace ActaLibre\Tests\Install;

use ActaLibre\Install\DiagnosticInstallation;
use PHPUnit\Framework\Attributes\DataProvider;

final class DiagnosticInstallationTest extends BaseDiagnostic
{
    public static function depotsInvalides(): iterable
    {
        yield 'parent' => ['../depot'];
        yield 'absolu' => ['/depot'];
        yield 'Windows' => ['C:\\depot'];
    }

    #[DataProvider('depotsInvalides')]
    public function testUnDepotInvalideEstSignaleSansAccuserLaLivraison(string $depot): void
    {
        $html = DiagnosticInstallation::html($this->root, 'base', $depot);
        self::assertStringContainsString('réglage UPLOAD_DIR invalide', $html);
        self::assertStringNotContainsString('inventaire absent', $html);
        self::assertStringNotContainsString('Aucun chemin inconnu détecté', $html);
    }

    public function testLeLienVersLeControleEstReserveAuxPagesExterieures(): void
    {
        self::assertStringContainsString('href="admin/protection.php"', DiagnosticInstallation::html($this->root, 'base', '_upload', 'admin/protection.php'));
        self::assertStringNotContainsString('href=', DiagnosticInstallation::html($this->root, 'base', '_upload'));
    }

    public function testUnInventaireAbsentResteSignaleCommeTel(): void
    {
        unlink($this->root.'/inventaire-actalibre.json');
        $html = DiagnosticInstallation::html($this->root, 'base', '_upload');
        self::assertStringContainsString('inventaire absent', $html);
        self::assertStringNotContainsString('réglage UPLOAD_DIR invalide', $html);
    }
}
