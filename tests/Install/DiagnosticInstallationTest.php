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
        self::assertStringContainsString('<p class="diagnostic-protection avertissement" role="status">', $html);
        self::assertStringNotContainsString('Contrôle non réalisé.', $html);
        self::assertStringNotContainsString('<table>', $html);
    }

    public static function etatsDuRapport(): iterable
    {
        yield 'protection refusée' => ['refuse', 200, null, 'erreur', 'Des fichiers privés sont accessibles publiquement.'];
        yield 'protection indéterminée' => ['indetermine', null, 'delai', 'avertissement', 'Certaines protections n’ont pas pu être vérifiées.'];
        yield 'protection vérifiée' => ['verifie', 403, null, 'information', 'Les protections contrôlées sont vérifiées.'];
    }

    #[DataProvider('etatsDuRapport')]
    public function testLeBandeauDistingueErreurAvertissementEtInformation(string $etat, ?int $http, ?string $cause, string $classe, string $message): void
    {
        $sondes = array_fill_keys(['tmp','tmp_php','configuration','installation','depot','composer','inventaire'], ['etat'=>'verifie', 'http'=>403, 'cause'=>null]);
        $sondes['public'] = $sondes['css'] = ['etat'=>'verifie', 'http'=>200, 'cause'=>null];
        $sondes['tmp'] = ['etat'=>$etat, 'http'=>$http, 'cause'=>$cause];
        self::assertTrue((new RapportProtection($etat, '1.2.1', 'https://exemple.test/', $sondes))->enregistrer($this->root));
        $html = DiagnosticInstallation::html($this->root, 'base', '_upload', 'admin/protection.php');
        $bandeau = '<p class="diagnostic-protection '.$classe.'" role="status">'.$message.'</p>';
        self::assertStringContainsString($bandeau, $html);
        self::assertLessThan(strpos($html, '<table>'), strpos($html, $bandeau));
        self::assertStringNotContainsString('role="alert"', $html);
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
        foreach (['tmp','_config','install','admin/_upload'] as $dossier) { mkdir($racine.'/'.$dossier, 0755, true); }
        // Seul le transport HTTP est simulé : les témoins sont réellement déposés et nettoyés.
        $rapport = (new ControleProtection($this->root, static function (string $url, int $delai) use ($racine): array {
            $chemin = ltrim((string)parse_url($url, PHP_URL_PATH), '/');
            if (str_contains($chemin, '/') && !str_ends_with($chemin, '.css') || str_ends_with($chemin, '.json')) {
                return ['http'=>403, 'corps'=>'', 'erreur'=>null];
            }
            return ['http'=>200, 'corps'=>(string)file_get_contents($racine.'/'.$chemin), 'erreur'=>null];
        }))->executer('https://exemple.test/', false);
        self::assertTrue($rapport->enregistrer($this->root));
        $html = DiagnosticInstallation::html($this->root, 'base', '_upload');
        self::assertSame('fichier_absent', $rapport->sondes['inventaire']['cause']);
        self::assertStringContainsString('<td>inventaire-actalibre.json</td><td>Non vérifiable</td><td>x</td><td>fichier absent</td>', $html);
        self::assertStringContainsString('Contrôle partiel : 8 essais concluants sur 9.', $html);
        self::assertStringNotContainsString('Contrôle non réalisé.', $html);
        self::assertStringNotContainsString('Contrôle :', $html);
        self::assertStringNotContainsString('Consultez le contrôle des protections.', $html);
        self::assertStringContainsString('<td>composer.json</td><td>Vérifié</td><td>403</td>', $html);
    }

    public static function causesSansConclusion(): iterable
    {
        yield 'témoin public non validé' => ['public', null, 'Non testé'];
        yield 'budget épuisé' => ['budget', null, 'Non testé'];
        yield 'cURL absent' => ['curl', null, 'Non testé'];
        yield 'témoin impossible à déposer' => ['non_prepare', null, 'Non vérifiable'];
        yield 'fichier absent' => ['fichier_absent', null, 'Non vérifiable'];
        yield 'chemin interdit' => ['chemin_interdit', null, 'Non vérifiable'];
        yield 'délai dépassé' => ['delai', null, 'Non vérifiable'];
        yield 'connexion impossible' => ['connexion', null, 'Non vérifiable'];
        yield 'TLS refusé' => ['tls', null, 'Non vérifiable'];
        yield 'transport interrompu' => ['transport', null, 'Non vérifiable'];
        yield 'réponse inattendue' => ['statut', 500, 'Indéterminé'];
        yield 'contenu inattendu' => ['contenu', 200, 'Indéterminé'];
        yield 'ancien rapport sans cause' => [null, null, 'Indéterminé'];
    }

    #[DataProvider('causesSansConclusion')]
    public function testLaCauseDistingueUnEssaiNonLanceDUnObstacleOuDUneReponseIndeterminee(?string $cause, ?int $http, string $resultat): void
    {
        $sondes = array_fill_keys(['public','tmp','tmp_php','configuration','css','installation','depot','composer','inventaire'], ['etat'=>'indetermine', 'http'=>$http, 'cause'=>$cause]);
        self::assertTrue((new RapportProtection('indetermine', '1.2.1', 'https://exemple.test/', $sondes))->enregistrer($this->root));
        $html = DiagnosticInstallation::html($this->root, 'base', '_upload');
        self::assertStringContainsString('<td>Dossier tmp/</td><td>'.$resultat.'</td>', $html);
        self::assertStringNotContainsString('Contrôle partiel', $html);
        if ($resultat === 'Non testé') {
            self::assertStringContainsString('Aucun essai HTTP réalisé.', $html);
        } else {
            self::assertStringContainsString('Contrôle sans résultat concluant.', $html);
        }
    }

    public function testUnControlePartielConserveLAlerteDExposition(): void
    {
        $sondes = array_fill_keys(['public','tmp','tmp_php','configuration','css','installation','depot','composer','inventaire'], ['etat'=>'indetermine', 'http'=>null, 'cause'=>'budget']);
        $sondes['public'] = ['etat'=>'verifie', 'http'=>200, 'cause'=>null];
        $sondes['tmp'] = ['etat'=>'refuse', 'http'=>200, 'cause'=>null];
        self::assertTrue((new RapportProtection('refuse', '1.2.1', 'https://exemple.test/', $sondes))->enregistrer($this->root));
        $html = DiagnosticInstallation::html($this->root, 'base', '_upload');
        self::assertStringContainsString('Contrôle partiel : 2 essais concluants sur 9.', $html);
        self::assertStringContainsString('<p class="diagnostic-protection erreur" role="status">Des fichiers privés sont accessibles publiquement.</p>', $html);
        self::assertStringContainsString('<td>Dossier tmp/</td><td>En défaut</td><td>200</td>', $html);
    }

    public function testUnInventaireRemplaceParUnLienNestPasSignaleCommeAbsent(): void
    {
        unlink($this->root.'/inventaire-actalibre.json');
        symlink($this->root.'/composer.json', $this->root.'/inventaire-actalibre.json');
        $racine = $this->root;
        $rapport = (new ControleProtection($racine, static function (string $url, int $delai) use ($racine): array {
            if (str_ends_with($url, '.json')) { return ['http'=>403, 'corps'=>'', 'erreur'=>null]; }
            return ['http'=>200, 'corps'=>(string)file_get_contents($racine.'/'.basename($url)), 'erreur'=>null];
        }))->executer('https://exemple.test/', false);
        self::assertSame('chemin_interdit', $rapport->sondes['inventaire']['cause']);
        self::assertTrue($rapport->enregistrer($racine));
        self::assertStringContainsString('<td>inventaire-actalibre.json</td><td>Non vérifiable</td><td>x</td><td>chemin non autorisé (dossier ou lien symbolique)</td>', DiagnosticInstallation::html($racine, 'base', '_upload'));
    }
}
