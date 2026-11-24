<?php
declare(strict_types=1);

namespace ActaLibre\Tests;

use ActaLibre\Livraison;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LivraisonTest extends TestCase
{
    private string $dossier;
    private string $fichier;

    protected function setUp(): void
    {
        $this->dossier = sys_get_temp_dir() . '/actalibre-livraison-' . bin2hex(random_bytes(8));
        mkdir($this->dossier);
        $this->fichier = $this->dossier . '/composer.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->fichier)) {
            unlink($this->fichier);
        }
        rmdir($this->dossier);
    }

    /** @return array<string,mixed> */
    private static function donnees(): array
    {
        return [
            'version' => '1.2.3',
            'extra' => ['actalibre' => ['schema' => '1.0.0']],
            'require' => ['php' => '>=8.3', 'ext-xml' => '*', 'ext-mysqli' => '*'],
            'suggest' => ['ext-openssl' => 'Géocodage HTTPS facultatif.'],
        ];
    }

    public function testLitLesValeursSansDefinirDeConstante(): void
    {
        $constantes = [defined('ACTALIBRE_VERSION'), defined('ACTALIBRE_SCHEMA')];
        file_put_contents($this->fichier, json_encode(self::donnees(), JSON_THROW_ON_ERROR));

        $livraison = Livraison::lire($this->fichier);

        self::assertSame('1.2.3', $livraison->version);
        self::assertSame('1.0.0', $livraison->schema);
        self::assertSame('8.3.0', $livraison->phpMinimum);
        self::assertSame(['mysqli', 'xml'], $livraison->extensions);
        self::assertSame($constantes, [defined('ACTALIBRE_VERSION'), defined('ACTALIBRE_SCHEMA')]);
    }

    public function testAccepteUnMinimumAvecCorrectif(): void
    {
        $donnees = self::donnees();
        $donnees['require']['php'] = '>=8.3.1';
        file_put_contents($this->fichier, json_encode($donnees, JSON_THROW_ON_ERROR));
        self::assertSame('8.3.1', Livraison::lire($this->fichier)->phpMinimum);
    }

    public function testRefuseUnFichierAbsent(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('composer.json');
        Livraison::lire($this->fichier);
    }

    public function testRefuseUnCheminQuiNestPasUnFichier(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('composer.json');
        Livraison::lire($this->dossier);
    }

    /** @return iterable<string,array{string,string}> */
    public static function invalides(): iterable
    {
        yield 'vide' => ['', 'JSON'];
        yield 'JSON tronqué' => ['{"version":', 'JSON'];
        yield 'racine scalaire' => ['"confidentiel"', 'racine'];
        yield 'racine liste' => ['[1,2]', 'racine'];
        foreach (['version', 'extra', 'require'] as $cle) {
            $d = self::donnees();
            unset($d[$cle]);
            yield 'absence ' . $cle => [json_encode($d, JSON_THROW_ON_ERROR), $cle];
        }
        $d = self::donnees();
        unset($d['extra']['actalibre']);
        yield 'absence actalibre' => [json_encode($d, JSON_THROW_ON_ERROR), 'extra.actalibre'];
        $d = self::donnees();
        unset($d['extra']['actalibre']['schema']);
        yield 'absence schéma' => [json_encode($d, JSON_THROW_ON_ERROR), 'extra.actalibre.schema'];
        $d = self::donnees();
        unset($d['require']['php']);
        yield 'absence php' => [json_encode($d, JSON_THROW_ON_ERROR), 'require.php'];
        foreach ([null, 100, [], '1.0', '1.0.0-beta', ' 1.0.0', '01.0.0', '1.0.0\n'] as $i => $valeur) {
            $d = self::donnees();
            $d['version'] = $valeur;
            yield 'version ' . $i => [json_encode($d, JSON_THROW_ON_ERROR), 'version'];
            $d = self::donnees();
            $d['extra']['actalibre']['schema'] = $valeur;
            yield 'schéma ' . $i => [json_encode($d, JSON_THROW_ON_ERROR), 'extra.actalibre.schema'];
        }
        foreach ([null, 8.3, '^8.3', '>=8.3 <9', '>=8', '>=8.3.0-dev', '>=08.3', ">=8.3\n"] as $i => $valeur) {
            $d = self::donnees();
            $d['require']['php'] = $valeur;
            yield 'minimum PHP ' . $i => [json_encode($d, JSON_THROW_ON_ERROR), 'require.php'];
        }
        foreach (['extra', 'require'] as $cle) {
            $d = self::donnees();
            $d[$cle] = 'confidentiel';
            yield 'type ' . $cle => [json_encode($d, JSON_THROW_ON_ERROR), $cle];
        }
        $d = self::donnees();
        $d['extra']['actalibre'] = 42;
        yield 'type actalibre' => [json_encode($d, JSON_THROW_ON_ERROR), 'extra.actalibre'];
        foreach (['' => '*', 'mysqli' => '', 'xml' => 4] as $nom => $contrainte) {
            $d = self::donnees();
            $d['require']['ext-' . $nom] = $contrainte;
            yield 'extension ' . $nom => [json_encode($d, JSON_THROW_ON_ERROR), 'require.ext-' . $nom];
        }
    }

    #[DataProvider('invalides')]
    public function testRefuseLesMetadonneesInvalides(string $json, string $cle): void
    {
        file_put_contents($this->fichier, $json);
        try {
            Livraison::lire($this->fichier);
            self::fail('Une livraison invalide doit être refusée.');
        } catch (\RuntimeException $erreur) {
            self::assertStringContainsString('composer.json', $erreur->getMessage());
            self::assertStringContainsString($cle, $erreur->getMessage());
            self::assertStringNotContainsString('confidentiel', $erreur->getMessage());
        }
    }

    public function testRelitMemeSiLaTailleEtLaDateSontInchangees(): void
    {
        $d = self::donnees();
        file_put_contents($this->fichier, json_encode($d, JSON_THROW_ON_ERROR));
        $date = filemtime($this->fichier);
        $taille = filesize($this->fichier);
        self::assertSame('1.2.3', Livraison::lire($this->fichier)->version);
        $d['version'] = '1.2.4';
        file_put_contents($this->fichier, json_encode($d, JSON_THROW_ON_ERROR));
        touch($this->fichier, $date);
        clearstatcache(true, $this->fichier);
        self::assertSame($date, filemtime($this->fichier));
        self::assertSame($taille, filesize($this->fichier));
        self::assertSame('1.2.4', Livraison::lire($this->fichier)->version);
        file_put_contents($this->fichier, '{');
        $this->expectException(\RuntimeException::class);
        Livraison::lire($this->fichier);
    }

    public function testLeFichierLivreDeclareLaVersionEtLesPrerequis(): void
    {
        $livraison = Livraison::lire(dirname(__DIR__) . '/composer.json');
        self::assertSame('1.1.0', $livraison->version);
        self::assertSame('1.0.0', $livraison->schema);
        self::assertSame('8.3.0', $livraison->phpMinimum);
        self::assertSame(['filter', 'gd', 'iconv', 'mbstring', 'mysqli', 'mysqlnd', 'session', 'xml'], $livraison->extensions);
        $donnees = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('GPL-2.0-only', $donnees['license']);
        self::assertArrayHasKey('ext-openssl', $donnees['suggest']);
    }
}
