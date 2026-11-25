<?php
declare(strict_types=1);

namespace ActaLibre\Tests\Install;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValeursLivreesTest extends TestCase
{
    private string $racine;

    protected function setUp(): void
    {
        $this->racine = sys_get_temp_dir() . '/actalibre-valeurs-' . bin2hex(random_bytes(8));
        mkdir($this->racine);
    }

    protected function tearDown(): void
    {
        $elements = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->racine, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($elements as $element) {
            $element->isDir() ? rmdir($element->getPathname()) : unlink($element->getPathname());
        }
        rmdir($this->racine);
    }

    private function ecrire(string $chemin, string $contenu): void
    {
        $cible = $this->racine . '/' . $chemin;
        if (!is_dir(dirname($cible))) {
            mkdir(dirname($cible), 0777, true);
        }
        file_put_contents($cible, $contenu);
    }

    private function preparerArbre(): void
    {
        foreach (['update.php', 'src/ActaLibre/Install/PageMiseAJour.php', 'src/ActaLibre/Install/SessionMiseAJour.php', 'install.php', 'src/ActaLibre/Install/PageInstallation.php', 'src/ActaLibre/Install/InstallGuard.php', 'src/ActaLibre/Install/Installateur.php', 'src/ActaLibre/Securite/Filtre.php', 'src/autoload.php', 'src/ActaLibre/Livraison.php', 'src/livraison.php', 'tools/_COMMUN_env.inc.php', 'tools/actutils.php', 'install/install.php', 'install/update.php', 'composer.json'] as $fichier) {
            $source = dirname(__DIR__, 2) . '/' . $fichier;
            // Avant l'implémentation, l'absence du point de chargement doit rester observable.
            if (is_file($source)) {
                $this->ecrire($fichier, (string) file_get_contents($source));
            }
        }
        BaseInstallation::copier(dirname(__DIR__, 2) . '/src', $this->racine . '/src');
        $this->ecrire('_config_init/temoin.txt', 'ne pas déplacer');
        $this->ecrire('tools/function.php', <<<'PHP'
<?php
function con_db() {
    file_put_contents(__DIR__ . '/../sql-tente', 'connexion');
    throw new RuntimeException('Connexion SQL interdite par la sonde.');
}
PHP);
        $this->ecrire('tools/adlcutils.php', '<?php');
    }

    /** @param list<string> $arguments @return array{int,string,string} */
    private function sonde(array $arguments, ?string $cwd = null): array
    {
        $commande = [PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/../fixtures/valeurs_livrees_probe.php', ...$arguments];
        $processus = proc_open($commande, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes, $cwd);
        self::assertIsResource($processus);
        $sortie = stream_get_contents($tubes[1]);
        $erreurs = stream_get_contents($tubes[2]);
        fclose($tubes[1]);
        fclose($tubes[2]);
        return [proc_close($processus), $sortie, $erreurs];
    }

    /** @return iterable<string,array{string,string}> */
    public static function fichiersInvalides(): iterable
    {
        foreach (['install.php', 'update.php'] as $page) {
            foreach (['absent', 'json', 'version'] as $cas) {
                yield $page . ' / ' . $cas => [$page, $cas];
            }
        }
    }

    #[DataProvider('fichiersInvalides')]
    public function testUneLivraisonInvalideArreteAvantTouteEcriture(string $page, string $cas): void
    {
        $this->preparerArbre();
        if ($cas === 'absent') {
            unlink($this->racine . '/composer.json');
        } elseif ($cas === 'json') {
            $this->ecrire('composer.json', '{"secret":"mot_de_passe_confidentiel",');
        } else {
            $donnees = json_decode((string) file_get_contents($this->racine . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
            unset($donnees['version']);
            $this->ecrire('composer.json', json_encode($donnees, JSON_THROW_ON_ERROR));
        }
        [$code, $sortie, $erreurs] = $this->sonde(['page', $this->racine, $page], $this->racine . '/install');
        self::assertNotSame(0, $code);
        self::assertDirectoryDoesNotExist($this->racine . '/_config');
        self::assertFileExists($this->racine . '/_config_init/temoin.txt');
        self::assertFileDoesNotExist($this->racine . '/sql-tente');
        self::assertStringContainsString('composer.json', $sortie . $erreurs);
        self::assertStringContainsString('<p class="message erreur" role="alert">', $sortie);
        self::assertStringNotContainsString('mot_de_passe_confidentiel', $sortie . $erreurs);
        self::assertStringNotContainsString('Warning', $sortie . $erreurs);
        self::assertStringNotContainsString('Fatal error', $sortie . $erreurs);
        self::assertSame('', $erreurs);
    }

    /** @return iterable<string,array{string,string}> */
    public static function chargements(): iterable
    {
        foreach (['parametres', 'sans-marqueurs'] as $mode) {
            foreach (['', '/admin'] as $cwd) {
                yield $mode . $cwd => [$mode, $cwd];
            }
        }
    }

    #[DataProvider('chargements')]
    public function testLesValeursLivreesRestentIndependantesDesParametres(string $mode, string $cwd): void
    {
        $depot = dirname(__DIR__, 2);
        [$code, $sortie, $erreurs] = $this->sonde([$mode, $depot], $depot . $cwd);
        self::assertSame('', $erreurs);
        self::assertSame(0, $code, $sortie);
        self::assertSame([
            'version' => '1.1.3',
            'schema' => '1.0.0',
            'installation' => false,
            'migration' => false,
            'titre' => 'Site & test',
            'version_heritee' => false,
            'format_herite' => false,
        ], json_decode($sortie, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testLeControleBloquePreProcessSansConfiguration(): void
    {
        $this->preparerArbre();
        $this->ecrire('tools/PreProcess.inc.php', <<<'PHP'
<?php
echo json_encode([
    class_exists('ActaLibre\Livraison'),
    defined('ACTALIBRE_VERSION') ? ACTALIBRE_VERSION : null,
    defined('ACTALIBRE_SCHEMA') ? ACTALIBRE_SCHEMA : null,
]);
exit(0);
PHP);
        [$code, $sortie, $erreurs] = $this->sonde(['environnement', $this->racine]);
        self::assertSame('', $erreurs);
        self::assertSame(1, $code);
        self::assertStringContainsString('maintenance', $sortie);
        self::assertStringContainsString('install.php', $sortie);
        self::assertStringNotContainsString('[true', $sortie);
        self::assertFileDoesNotExist($this->racine . '/sql-tente');
        self::assertDirectoryDoesNotExist($this->racine . '/vendor');
    }

    public function testPreProcessConserveSonTamponPourLaMutualisation(): void
    {
        [$code, $sortie, $erreurs] = $this->sonde(['tampon', dirname(__DIR__, 2)]);
        self::assertSame('', $erreurs);
        self::assertSame(0, $code);
        self::assertSame('tampon', $sortie);
    }

    public function testLeGenerateurRssUtiliseLeProduitEtNonLeSchema(): void
    {
        [$code, $sortie, $erreurs] = $this->sonde(['rss', dirname(__DIR__, 2)]);
        self::assertSame('', $erreurs);
        self::assertSame(0, $code);
        self::assertSame('<generator>ActaLibre 1.2.3 + ILIX MakeRss 0.2</generator>' . "\n", $sortie);
    }
}
