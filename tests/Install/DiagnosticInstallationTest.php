<?php
declare(strict_types=1);

namespace ActaLibre\Tests\Install;

use ActaLibre\Install\DiagnosticInstallation;
use ActaLibre\Install\ControleProtection;
use ActaLibre\Install\RapportProtection;
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

    public function testSansRapportAucunResultatNestAnnonceCommeDisponible(): void
    {
        $html = DiagnosticInstallation::html($this->root, 'base', '_upload');
        self::assertStringContainsString('Aucun résultat de contrôle disponible.', $html);
        self::assertStringNotContainsString('Contrôle non réalisé.', $html);
        self::assertStringNotContainsString('<table>', $html);
    }

    public static function etatsDuRapport(): iterable
    {
        yield 'protection refusée' => ['refuse', 200, null, 'Des fichiers privés sont accessibles publiquement.'];
        yield 'protection indéterminée' => ['indetermine', null, 'delai', 'Certaines protections n’ont pas pu être vérifiées.'];
        yield 'protection vérifiée' => ['verifie', 403, null, null];
    }

    #[DataProvider('etatsDuRapport')]
    public function testLeBandeauSignaleLesProtectionsRefuseesOuNonVerifiables(string $etat, ?int $http, ?string $cause, ?string $alerte): void
    {
        $sondes = array_fill_keys(['tmp','tmp_php','configuration','installation','depot','composer','inventaire'], ['etat'=>'verifie', 'http'=>403, 'cause'=>null]);
        $sondes['public'] = $sondes['css'] = ['etat'=>'verifie', 'http'=>200, 'cause'=>null];
        $sondes['tmp'] = ['etat'=>$etat, 'http'=>$http, 'cause'=>$cause];
        self::assertTrue((new RapportProtection($etat, '1.2.0', 'https://exemple.test/', $sondes))->enregistrer($this->root));
        $html = DiagnosticInstallation::html($this->root, 'base', '_upload', 'admin/protection.php');
        if ($alerte === null) {
            self::assertStringNotContainsString('class="erreur"', $html);
        } else {
            $message = '<p class="erreur" role="alert">'.$alerte.'</p>';
            self::assertStringContainsString($message, $html);
            self::assertLessThan(strpos($html, '<table>'), strpos($html, $message));
        }
        self::assertStringNotContainsString('Consultez le contrôle', $html);
        self::assertStringContainsString('href="admin/protection.php">Relancer le contrôle</a>', $html);
        self::assertLessThan(strpos($html, '>Relancer le contrôle</a>'), strpos($html, '</table>'));
    }

    public function testUnRapportNonEnregistreNestPasPresenteCommeUnControleNonRealise(): void
    {
        $racine = $this->root;
        // Un dossier à la place du rapport interdit son écriture, même si les sondes ont eu lieu.
        mkdir($racine.'/tmp/actalibre/protection.json', 0755, true);
        $rapport = (new ControleProtection($racine, static function (string $url, int $delai) use ($racine): array {
            return ['http'=>200, 'corps'=>(string)file_get_contents($racine.'/'.(string)parse_url($url, PHP_URL_PATH)), 'erreur'=>null];
        }))->executer('https://exemple.test/', false);
        self::assertCount(9, $rapport->sondes);
        self::assertSame('verifie', $rapport->sondes['public']['etat']);
        self::assertFalse($rapport->enregistrer($racine));
        $html = DiagnosticInstallation::html($racine, 'base', '_upload');
        self::assertStringContainsString('Aucun résultat de contrôle disponible.', $html);
        self::assertStringNotContainsString('Contrôle non réalisé.', $html);
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
