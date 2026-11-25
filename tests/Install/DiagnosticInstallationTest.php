<?php
declare(strict_types=1);

namespace ActaLibre\Tests\Install;

use ActaLibre\Install\DiagnosticInstallation;
use ActaLibre\Install\ControleProtection;
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

    public function testSansRapportLeControleEstAnnonceCommeNonRealise(): void
    {
        $html = DiagnosticInstallation::html($this->root, 'base', '_upload');
        self::assertStringContainsString('Contrôle non réalisé.', $html);
        self::assertStringNotContainsString('<table>', $html);
    }

    public function testUnControlePartielAfficheSesResultatsEtLaCauseDuFichierAbsent(): void
    {
        unlink($this->root.'/inventaire-actalibre.json');
        $racine = $this->root;
        // Seul le transport HTTP est simulé : les témoins sont réellement déposés et nettoyés.
        $rapport = (new ControleProtection($this->root, static function (string $url, int $delai) use ($racine): array {
            if (str_ends_with($url, '.json')) { return ['http'=>403, 'corps'=>'', 'erreur'=>null]; }
            return ['http'=>200, 'corps'=>(string)file_get_contents($racine.'/'.basename($url)), 'erreur'=>null];
        }))->executer('https://exemple.test/', false);
        self::assertTrue($rapport->enregistrer($this->root));
        $html = DiagnosticInstallation::html($this->root, 'base', '_upload');
        self::assertStringContainsString('<td>inventaire-actalibre.json</td><td>Non vérifiable</td><td>x</td><td>fichier absent ou chemin non autorisé</td>', $html);
        self::assertStringNotContainsString('Contrôle non réalisé.', $html);
        self::assertStringNotContainsString('Contrôle :', $html);
        self::assertStringNotContainsString('Consultez le contrôle des protections.', $html);
        self::assertStringContainsString('<td>composer.json</td><td>Vérifiée</td><td>403</td>', $html);
    }
}
