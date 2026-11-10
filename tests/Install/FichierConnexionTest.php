<?php

declare (strict_types=1);
namespace ActaLibre\Tests\Install;

use ActaLibre\Install\AccesMysql;
use ActaLibre\Install\FichierConnexion;
final class FichierConnexionTest extends BaseInstallation
{
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testPrepareLesCinqDestinationsEtPublieSeulementUnFichierComplet(): void
    {
        $this->sources();
        $contenu = "<?php\n\$dbpass = 'a@@b';\n";
        self::assertSame(FichierConnexion::PUBLIE, FichierConnexion::publier($this->racine, $contenu));
        self::assertSame($contenu, file_get_contents($this->racine . '/_config/connect.inc.php'));
        self::assertSame(0600, fileperms($this->racine . '/_config/connect.inc.php') & 0777);
        foreach (['.htaccess', '_config/.htaccess', 'tmp/.htaccess', 'tmp/logs/.keep', 'tmp/backups/.keep', 'admin/_upload/.htaccess', '_config/actes.css'] as $f) {
            self::assertFileExists($this->racine . '/' . $f);
        }
        file_put_contents($this->racine . '/_config/actes.css', 'personnalise');
        self::assertSame(FichierConnexion::EXISTANT, FichierConnexion::publier($this->racine, 'autre'));
        self::assertSame('personnalise', file_get_contents($this->racine . '/_config/actes.css'));
        self::assertSame($contenu, file_get_contents($this->racine . '/_config/connect.inc.php'));
    }
    public function testUnFichierMultihoteSuffitARefuserSansCopie(): void
    {
        mkdir($this->racine . '/_config');
        file_put_contents($this->racine . '/_config/BD-exemple-connect.inc.php', '');
        self::assertSame(FichierConnexion::EXISTANT, FichierConnexion::publier($this->racine, 'secret'));
        self::assertDirectoryDoesNotExist($this->racine . '/_logs');
    }
    public function testLinkDesactiveDonneLeDepotManuelEtNettoieLeTemporaire(): void
    {
        $this->sources();
        $code = 'require ' . var_export(dirname(__DIR__, 2) . '/src/autoload.php', true) . '; echo ActaLibre\Install\FichierConnexion::publier(' . var_export($this->racine, true) . ',"secret");';
        $p = proc_open([PHP_BINARY, '-d', 'disable_functions=link', '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $t);
        $sortie = stream_get_contents($t[1]);
        $erreur = stream_get_contents($t[2]);
        fclose($t[1]);
        fclose($t[2]);
        self::assertSame(0, proc_close($p), $erreur);
        self::assertSame(FichierConnexion::MANUEL, $sortie);
        self::assertFileDoesNotExist($this->racine . '/_config/connect.inc.php');
        self::assertSame([], glob($this->racine . '/_config/.actalibre-connect-*.tmp'));
    }
    /** @return array{int,string,string} */
    private function enfant(string $fonctions): array
    {
        $script = $this->racine . '/simulation.php';
        $code = '<?php namespace ActaLibre\Install; ' . $fonctions . ' require ' . var_export(dirname(__DIR__, 2) . '/src/autoload.php', true) . '; echo FichierConnexion::publier(' . var_export($this->racine, true) . ',"secret-demo");';
        file_put_contents($script, $code);
        $p = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $t);
        $sortie = stream_get_contents($t[1]);
        $erreur = stream_get_contents($t[2]);
        fclose($t[1]);
        fclose($t[2]);
        return [proc_close($p), $sortie, $erreur];
    }
    public function testUnArretPendantLEcritureNePublieRienEtLeSuivantNettoie(): void
    {
        $this->sources();
        [$code] = $this->enfant('function fwrite($f,$s){if(str_ends_with(stream_get_meta_data($f)["uri"],".tmp")){\fwrite($f,substr($s,0,4));exit(73);}return \fwrite($f,$s);}');
        self::assertSame(73, $code);
        self::assertFileDoesNotExist($this->racine . '/_config/connect.inc.php');
        self::assertCount(1, glob($this->racine . '/_config/.actalibre-connect-*.tmp'));
        self::assertSame(FichierConnexion::PUBLIE, FichierConnexion::publier($this->racine, 'complet'));
        self::assertSame('complet', file_get_contents($this->racine . '/_config/connect.inc.php'));
        self::assertSame([], glob($this->racine . '/_config/.actalibre-connect-*.tmp'));
    }
    public function testUneEcritureCourteOuUneRelectureDifferenteNePublieRien(): void
    {
        $this->sources();
        foreach (['function fwrite($f,$s){return \fwrite($f,substr($s,0,4));}', 'function file_get_contents($p){return str_ends_with($p,".tmp")?"différent":\file_get_contents($p);}'] as $fonctions) {
            [$code, $sortie, $erreur] = $this->enfant($fonctions);
            self::assertSame(0, $code, $erreur);
            self::assertSame('manuel', $sortie);
            self::assertFileDoesNotExist($this->racine . '/_config/connect.inc.php');
        }
    }
    public function testUnePublicationEntreLaGardeEtLinkNeRemplacePasLeGagnant(): void
    {
        $this->sources();
        [$code, $sortie, $erreur] = $this->enfant('function link($a,$b){file_put_contents($b,"gagnant");return \link($a,$b);}');
        self::assertSame(0, $code, $erreur);
        self::assertSame('existant', $sortie);
        self::assertSame('gagnant', file_get_contents($this->racine . '/_config/connect.inc.php'));
    }
    public function testLeNettoyageNeTouchePasLeTemporaireDUnAutreProcessus(): void
    {
        $this->sources();
        $signal = $this->racine . '/pret';
        $suite = $this->racine . '/suite';
        $code = '<?php namespace ActaLibre\Install; function link($a,$b){file_put_contents(' . var_export($signal, true) . ',"prêt"); $limite=microtime(true)+10;while(!is_file(' . var_export($suite, true) . ') && microtime(true)<$limite)usleep(10000);return \link($a,$b);} require ' . var_export(dirname(__DIR__, 2) . '/src/autoload.php', true) . '; echo FichierConnexion::publier(' . var_export($this->racine, true) . ',"premier");';
        file_put_contents($this->racine . '/course.php', $code);
        $p = proc_open([PHP_BINARY, $this->racine . '/course.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $t);
        try {
            $limite = microtime(true) + 5;
            while (!is_file($signal) && microtime(true) < $limite) {
                usleep(10000);
            }
            self::assertFileExists($signal);
            $temporaires = glob($this->racine . '/_config/.actalibre-connect-*.tmp');
            self::assertCount(1, $temporaires);
            try {
                FichierConnexion::publier($this->racine, 'second');
                self::fail('Deux publications simultanées');
            } catch (\RuntimeException $e) {
                self::assertSame('installation en cours', $e->getMessage());
            }
            self::assertSame($temporaires, glob($this->racine . '/_config/.actalibre-connect-*.tmp'));
        } finally {
            file_put_contents($suite, 'suite');
        }
        $sortie = stream_get_contents($t[1]);
        $erreur = stream_get_contents($t[2]);
        fclose($t[1]);
        fclose($t[2]);
        self::assertSame(0, proc_close($p), $erreur);
        self::assertSame('publie', $sortie);
        self::assertSame('premier', file_get_contents($this->racine . '/_config/connect.inc.php'));
    }
    public function testUnDossierNonInscriptibleDonneLeDepotManuel(): void
    {
        $this->sources();
        mkdir($this->racine . '/_config');
        chmod($this->racine . '/_config', 0500);
        $script = $this->racine . '/permissions.php';
        file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__, 2) . '/src/autoload.php', true) . '; echo ActaLibre\Install\FichierConnexion::publier(' . var_export($this->racine, true) . ',"secret-demo");');
        $commande = [PHP_BINARY, $script];
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $commande = ['runuser', '-u', 'nobody', '--', ...$commande];
        }
        try {
            $p = proc_open($commande, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $t);
            $sortie = stream_get_contents($t[1]);
            $erreur = stream_get_contents($t[2]);
            fclose($t[1]);
            fclose($t[2]);
            self::assertSame(0, proc_close($p), $erreur);
            self::assertSame('manuel', $sortie);
            self::assertFileDoesNotExist($this->racine . '/_config/connect.inc.php');
        } finally {
            chmod($this->racine . '/_config', 0755);
        }
    }
}
