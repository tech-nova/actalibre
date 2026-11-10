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
    public static function etapes(): array
    {
        return array_map(static fn($e) => [$e], ['params', 'installation', 'donnees', 'compte', 'schema', 'produit', 'nettoyage', 'publication']);
    }
    #[DataProvider('etapes')]
    public function testChaqueInterruptionEstReprenableSansSecondCompte(string $arret): void
    {
        $i = new Installateur($this->racine, function ($etape) use ($arret) {
            self::assertFileDoesNotExist($this->racine . '/_config/connect.inc.php');
            if ($etape === $arret) {
                throw new \RuntimeException('Interruption');
            }
        });
        try {
            $i->executer($this->acces(), 'admin', 'a@example.org', 'secret', 'secret', 'https://example.org');
            self::fail('Étape non atteinte');
        } catch (\RuntimeException $e) {
            self::assertSame('Interruption', $e->getMessage());
        }
        if (in_array($arret, ['compte', 'schema', 'produit', 'nettoyage', 'publication'], true)) {
            $avant = $this->empreinte();
            try {
                (new Installateur($this->racine))->executer($this->acces(), 'intrus', 'b@example.org', 'secret', 'secret', 'https://example.org');
                self::fail('Intrus admis');
            } catch (\RuntimeException) {
                self::assertSame($avant, $this->empreinte());
            }
        }
        $r = (new Installateur($this->racine))->executer($this->acces(), 'admin', 'a@example.org', 'secret', 'secret', 'https://example.org');
        self::assertSame('publie', $r['publication']);
        self::assertSame('a_jour', EtatSite::lire($this->base, $this->prefixe, $this->racine . '/composer.json')->etat);
        self::assertSame('1', $this->base->query("SELECT COUNT(*) FROM {$this->prefixe}_user3 WHERE level=9")->fetch_row()[0]);
        self::assertSame('0', $this->base->query("SELECT COUNT(*) FROM {$this->prefixe}_params WHERE param='EA_VERSION'")->fetch_row()[0]);
    }
    public function testUneBaseIncoherenteNeSubitAucuneEcriture(): void
    {
        $this->base->query("CREATE TABLE {$this->prefixe}_intrus (id INT)");
        $avant = $this->empreinte();
        try {
            (new Installateur($this->racine))->executer($this->acces(), 'admin', 'a@example.org', 'secret', 'secret', 'https://example.org');
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
            (new Installateur($this->racine))->executer($a, 'admin', 'a@example.org', 'secret', 'secret', 'https://example.org');
            self::fail('Mauvaise casse admise');
        } catch (\RuntimeException) {
            self::assertSame($avant, $this->empreinte());
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
        try {
            mysqli_report($mode);
            $refuse = false;
            try {
                (new Installateur($this->racine))->executer($this->acces(), 'admin', 'a@example.org', 'secret', 'secret', 'https://example.org');
            } catch (\RuntimeException $e) {
                $refuse = true;
                self::assertStringNotContainsString('INSERT', $e->getMessage());
            }
            self::assertTrue($refuse);
            self::assertSame('0', $this->base->query("SELECT COUNT(*) FROM {$this->prefixe}_params")->fetch_row()[0]);
            self::assertFileDoesNotExist($this->racine . '/_config/connect.inc.php');
        } finally {
            mysqli_report($avant);
        }
    }
}
