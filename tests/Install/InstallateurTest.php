<?php

declare (strict_types=1);
namespace ActaLibre\Tests\Install;

use ActaLibre\Install\{AccesMysql, Installateur, EtatSite};
use PHPUnit\Framework\Attributes\DataProvider;
final class InstallateurTest extends BaseInstallation
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->sources();
        mkdir($this->racine . '/install');
        foreach (['creetables.sql', 'act_params.xml', 'liste_prenoms.csv'] as $f) {
            copy(dirname(__DIR__, 2) . '/install/' . $f, $this->racine . '/install/' . $f);
        }
        copy(dirname(__DIR__, 2) . '/composer.json', $this->racine . '/composer.json');
    }
    private function acces(): AccesMysql
    {
        return new AccesMysql((string) getenv('GM_DB_HOST'), (string) getenv('GM_DB_NAME'), (string) getenv('GM_DB_USER'), (string) getenv('GM_DB_PASS'), $this->prefixe);
    }
    private function installer(string $login, string $courriel, string $motDePasse, bool $preuve = true, ?\Closure $apres = null): void
    {
        (new Installateur($this->racine, $apres))->installer($this->acces(), null, $login, $courriel, $motDePasse, $motDePasse, 'https://example.org', $preuve);
    }
    public static function etapes(): array
    {
        return array_map(static fn($e) => [$e], ['params', 'installation', 'donnees', 'compte', 'schema', 'produit', 'nettoyage']);
    }
    public function testLaConnexionPublieLeFichierSansEcrireEnBase(): void
    {
        $avant = $this->empreinte();
        $r = (new Installateur($this->racine))->connecter($this->acces());
        self::assertSame('publie', $r['publication']);
        self::assertNull($r['contenu']);
        self::assertFileExists($this->racine . '/_config/connect.inc.php');
        self::assertSame($avant, $this->empreinte());
        $this->expectExceptionMessage('Fichier de connexion déjà présent');
        (new Installateur($this->racine))->connecter($this->acces());
    }
    public function testLAdministrateurExigeDAbordLaConnexion(): void
    {
        $avant = $this->empreinte();
        try {
            $this->installer('admin', 'a@example.org', 'secret');
            self::fail('Installation sans fichier de connexion');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('connexion', $e->getMessage());
            self::assertSame($avant, $this->empreinte());
        }
    }
    public function testUnNouvelAdministrateurExigeLaPreuveFtp(): void
    {
        (new Installateur($this->racine))->connecter($this->acces());
        $avant = $this->empreinte();
        try {
            $this->installer('admin', 'a@example.org', 'secret', false);
            self::fail('Administrateur créé sans preuve');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('preuve', $e->getMessage());
            self::assertSame($avant, $this->empreinte());
        }
    }
    #[DataProvider('etapes')]
    public function testChaqueInterruptionEstReprenableSansSecondCompte(string $arret): void
    {
        (new Installateur($this->racine))->connecter($this->acces());
        try {
            $this->installer('admin', 'a@example.org', 'secret', true, function ($etape) use ($arret) {
                if ($etape === $arret) {
                    throw new \RuntimeException('Interruption');
                }
            });
            self::fail('Étape non atteinte');
        } catch (\RuntimeException $e) {
            self::assertSame('Interruption', $e->getMessage());
        }
        if (in_array($arret, ['compte', 'schema', 'produit', 'nettoyage'], true)) {
            $avant = $this->empreinte();
            try {
                // Même muni d'une preuve FTP, un second compte n'est jamais créé.
                $this->installer('intrus', 'b@example.org', 'secret');
                self::fail('Intrus admis');
            } catch (\RuntimeException) {
                self::assertSame($avant, $this->empreinte());
            }
        }
        if ($arret === 'nettoyage') {
            self::assertSame('a_jour', EtatSite::lire($this->base, $this->prefixe, $this->racine . '/composer.json')->etat);
        } else {
            // Après la création du compte, ses identifiants suffisent ; avant, la preuve reste exigée.
            $this->installer('admin', 'a@example.org', 'secret', !in_array($arret, ['compte', 'schema', 'produit'], true));
        }
        self::assertSame('a_jour', EtatSite::lire($this->base, $this->prefixe, $this->racine . '/composer.json')->etat);
        self::assertSame('1', $this->base->query("SELECT COUNT(*) FROM {$this->prefixe}_user3 WHERE level=9")->fetch_row()[0]);
        self::assertSame('0', $this->base->query("SELECT COUNT(*) FROM {$this->prefixe}_params WHERE param='EA_VERSION'")->fetch_row()[0]);
    }
    public function testUneBaseIncoherenteNeSubitAucuneEcriture(): void
    {
        $this->base->query("CREATE TABLE {$this->prefixe}_intrus (id INT)");
        $avant = $this->empreinte();
        try {
            (new Installateur($this->racine))->connecter($this->acces());
            self::fail('Base incohérente admise');
        } catch (\RuntimeException) {
            self::assertSame($avant, $this->empreinte());
            self::assertDirectoryDoesNotExist($this->racine . '/_config');
        }
    }
    public function testUneRepriseNeCreePasDesTablesHomonymesALaCassePres(): void
    {
        if ((int) $this->base->query('SELECT @@lower_case_table_names')->fetch_row()[0] !== 0) {
            self::markTestSkipped('Cas propre aux noms de tables sensibles à la casse.');
        }
        (new \ActaLibre\Install\DonneesInitiales($this->racine))->creerParametres($this->base, $this->prefixe);
        $this->base->query("INSERT INTO {$this->prefixe}_params(param,groupe,ordre,type,valeur,listval,libelle,aide) VALUES ('ACTALIBRE_INSTALLATION','Hidden',100,'C','début','','','')");
        $avant = $this->empreinte();
        $a = $this->acces();
        $a = new AccesMysql($a->serveur, $a->base, $a->utilisateur, $a->motDePasse, strtoupper($a->prefixe));
        try {
            (new Installateur($this->racine))->connecter($a);
            self::fail('Mauvaise casse admise');
        } catch (\RuntimeException) {
            self::assertSame($avant, $this->empreinte());
            self::assertFileDoesNotExist($this->racine . '/_config/connect.inc.php');
        }
    }
    public static function modesSQL(): array
    {
        return [[MYSQLI_REPORT_OFF], [MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT]];
    }
    #[DataProvider('modesSQL')]
    public function testUneErreurDEcritureNeFinalisePasLInstallation(int $mode): void
    {
        $avant = (new \mysqli_driver())->report_mode;
        $this->base->query("CREATE TABLE {$this->prefixe}_params(param VARCHAR(25) PRIMARY KEY,valeur TEXT)");
        (new Installateur($this->racine))->connecter($this->acces());
        try {
            mysqli_report($mode);
            $refuse = false;
            try {
                $this->installer('admin', 'a@example.org', 'secret');
            } catch (\RuntimeException $e) {
                $refuse = true;
                self::assertStringNotContainsString('INSERT', $e->getMessage());
            }
            self::assertTrue($refuse);
            self::assertSame('0', $this->base->query("SELECT COUNT(*) FROM {$this->prefixe}_params")->fetch_row()[0]);
        } finally {
            mysqli_report($avant);
        }
    }
}
