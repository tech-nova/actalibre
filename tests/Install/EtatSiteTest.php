<?php
declare(strict_types=1);

namespace ActaLibre\Tests\Install;

use ActaLibre\Install\EtatSite;
use ActaLibre\Install\Marqueurs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class EtatSiteTest extends TestCase
{
    private const SOCLE = ['params', 'nai3', 'mar3', 'dec3', 'div3', 'metadb', 'metalg', 'mgrplg', 'user3'];
    private \mysqli $preparation;
    private ConnexionLecture $actes;
    private ConnexionLecture $comptes;
    private string $prefixe;
    private string $prefixeComptes;
    private string $composer;
    private int $modeInitial;
    /** @var list<string> */
    private array $tables = [];

    protected function setUp(): void
    {
        foreach (['HOST', 'NAME', 'USER', 'PASS'] as $cle) {
            if (getenv('GM_DB_' . $cle) === false) {
                self::markTestSkipped('Définir GM_DB_HOST, GM_DB_NAME, GM_DB_USER et GM_DB_PASS.');
            }
        }
        $this->modeInitial = (new \mysqli_driver())->report_mode;
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $connexion = [getenv('GM_DB_HOST'), getenv('GM_DB_USER'), getenv('GM_DB_PASS'), getenv('GM_DB_NAME')];
        $this->preparation = new \mysqli(...$connexion);
        $this->actes = new ConnexionLecture(...$connexion);
        $this->comptes = new ConnexionLecture(...$connexion);
        $this->prefixe = 'al2_' . bin2hex(random_bytes(6)) . '_a';
        $this->prefixeComptes = substr($this->prefixe, 0, -1) . 'u';
        $this->composer = tempnam(sys_get_temp_dir(), 'al2-livraison-');
        $this->livraison('1.0.0', '1.0.0');
    }

    protected function tearDown(): void
    {
        if (!isset($this->preparation)) {
            return;
        }
        foreach (array_reverse($this->tables) as $table) {
            $this->preparation->query("DROP TABLE `$table`");
        }
        $this->actes->close();
        $this->comptes->close();
        $this->preparation->close();
        unlink($this->composer);
        mysqli_report($this->modeInitial);
    }

    private function livraison(string $produit, string $schema): void
    {
        file_put_contents($this->composer, json_encode([
            'version' => $produit, 'extra' => ['actalibre' => ['schema' => $schema]], 'require' => ['php' => '>=8.3'],
        ], JSON_THROW_ON_ERROR));
    }

    private function table(string $suffixe, ?string $prefixe = null): void
    {
        $table = ($prefixe ?? $this->prefixe) . '_' . $suffixe;
        $definition = match (strtolower($suffixe)) {
            'params' => 'param VARCHAR(25), valeur TEXT NULL',
            'user3' => 'ID INT AUTO_INCREMENT PRIMARY KEY, login VARCHAR(15), hashpass VARCHAR(255)',
            default => 'id INT',
        };
        $this->preparation->query("CREATE TABLE `$table` ($definition) ENGINE=MyISAM");
        $this->tables[] = $table;
    }

    /** @param list<array{string,?string}> $lignes */
    private function parametres(array $lignes): void
    {
        $q = $this->preparation->prepare("INSERT INTO `{$this->prefixe}_params` VALUES (?, ?)");
        foreach ($lignes as [$nom, $valeur]) {
            $q->execute([$nom, $valeur]);
        }
    }

    /** @return array<string,array{string,list<string>}> */
    private function empreinte(): array
    {
        $empreinte = [];
        foreach ($this->tables as $table) {
            $lignes = array_map('serialize', $this->preparation->query("SELECT * FROM `$table`")->fetch_all());
            sort($lignes);
            $empreinte[$table] = [$this->preparation->query("SHOW CREATE TABLE `$table`")->fetch_row()[1], $lignes];
        }
        return $empreinte;
    }

    /** @return iterable<string,array{list<string>,list<array{string,?string}>,string,string,string}> */
    public static function etats(): iterable
    {
        $p = 'ACTALIBRE_VERSION'; $s = 'ACTALIBRE_SCHEMA';
        $i = 'ACTALIBRE_INSTALLATION'; $m = 'ACTALIBRE_MIGRATION';
        yield 'vide' => [[], [], 'base_vide', '1.0.0', '1.0.0'];
        yield 'params seule vide' => [['params'], [], 'base_vide', '1.0.0', '1.0.0'];
        yield 'table inconnue' => [['intrus'], [], 'incoherente', '1.0.0', '1.0.0'];
        yield 'params vide et actes' => [['params', 'nai3'], [], 'incoherente', '1.0.0', '1.0.0'];
        yield 'params ordinaire seule' => [['params'], [['SITENAME', 'Un site']], 'incoherente', '1.0.0', '1.0.0'];
        yield 'schéma orphelin' => [['params'], [[$s, '1.0.0']], 'incoherente', '1.0.0', '1.0.0'];
        yield 'installation' => [['params'], [[$i, 'début']], 'installation_inachevee', '1.0.0', '1.0.0'];
        yield 'installation avec schéma' => [['params'], [[$i, 'début'], [$s, '1.0.0']], 'installation_inachevee', '1.0.0', '1.0.0'];
        foreach (['3.2.4', '', null] as $n => $ea) {
            yield 'installation et héritage ' . $n => [['params'], [[$i, 'début'], ['EA_VERSION', $ea]], 'incoherente', '1.0.0', '1.0.0'];
        }
        foreach ([[], [[$s, '1.0.0']]] as $n => $schema) {
            yield 'installation et migration ' . $n => [['params'], [[$i, 'début'], [$m, 'brut'], ...$schema], 'incoherente', '1.0.0', '1.0.0'];
        }
        foreach (['', null, '1.0', '1.0.0-beta', ' 1.0.0', '01.0.0'] as $n => $v) {
            yield 'produit invalide ' . $n => [self::SOCLE, [[$p, $v], [$s, '1.0.0'], ['EA_VERSION', '3.2.4']], 'incoherente', '1.0.0', '1.0.0'];
            yield 'schéma invalide ' . $n => [self::SOCLE, [[$p, '1.0.0'], [$s, $v], ['EA_VERSION', '3.2.4']], 'incoherente', '1.0.0', '1.0.0'];
        }
        yield 'schéma absent' => [self::SOCLE, [[$p, '1.0.0'], ['EA_VERSION', '3.2.4']], 'incoherente', '1.0.0', '1.0.0'];
        yield 'produit dupliqué' => [self::SOCLE, [[$p, '1.0.0'], [$p, '1.0.0'], [$s, '1.0.0']], 'incoherente', '1.0.0', '1.0.0'];
        yield 'héritage dupliqué' => [self::SOCLE, [['EA_VERSION', '3.2.4'], ['EA_VERSION', '3.2.4']], 'incoherente', '1.0.0', '1.0.0'];
        foreach (['3.2.4', ' 3.2.4 '] as $ea) {
            yield 'source admise ' . $ea => [self::SOCLE, [['EA_VERSION', $ea]], 'expoactes_admis', '1.0.0', '1.0.0'];
        }
        foreach (['3.2.1', '3.2.2', '3.2.3', '3.2.4b', '3.2.5', '4.0.0', '4.0.3', '', null] as $n => $ea) {
            yield 'source refusée ' . $n => [self::SOCLE, [['EA_VERSION', $ea]], 'expoactes_refuse', '1.0.0', '1.0.0'];
        }
        foreach ([[[$s, '1.0.0']], [[$m, 'avancement brut']], [[$s, '1.0.0'], [$m, 'avancement brut']]] as $n => $marqueurs) {
            yield 'migration interrompue ' . $n => [self::SOCLE, [['EA_VERSION', '3.2.4'], ...$marqueurs], 'expoactes_admis', '1.0.0', '1.0.0'];
        }
        yield 'à jour' => [self::SOCLE, [[$p, '1.0.0'], [$s, '1.0.0']], 'a_jour', '1.0.0', '1.0.0'];
        yield 'produit à monter' => [self::SOCLE, [[$p, '1.0.0'], [$s, '1.0.0']], 'mise_a_jour_produit', '1.0.1', '1.0.0'];
        yield 'schéma à monter' => [self::SOCLE, [[$p, '1.0.0'], [$s, '1.0.0']], 'mise_a_jour_schema', '1.1.0', '1.1.0'];
        yield 'produit supérieur schéma inférieur' => [self::SOCLE, [[$p, '2.0.0'], [$s, '0.9.0']], 'retour_version_refuse', '1.0.0', '1.0.0'];
        yield 'schéma supérieur produit inférieur' => [self::SOCLE, [[$p, '0.9.0'], [$s, '2.0.0']], 'retour_version_refuse', '1.0.0', '1.0.0'];
        yield 'comparaison numérique' => [self::SOCLE, [[$p, '1.10.0'], [$s, '1.0.0']], 'retour_version_refuse', '1.9.0', '1.0.0'];
        yield 'restes conservés' => [self::SOCLE, [[$p, '1.0.0'], [$s, '1.0.0'], [$i, 'début'], [$m, 'reste']], 'a_jour', '1.0.0', '1.0.0'];
        foreach (array_slice(self::SOCLE, 1) as $table) {
            yield 'socle absent ' . $table => [array_values(array_diff(self::SOCLE, [$table])), [['EA_VERSION', '3.2.4']], 'incoherente', '1.0.0', '1.0.0'];
        }
    }

    #[DataProvider('etats')]
    public function testLaMatriceSansEcriture(array $tables, array $params, string $attendu, string $produit, string $schema): void
    {
        foreach ($tables as $table) { $this->table($table); }
        if ($params !== []) { $this->parametres($params); }
        $this->livraison($produit, $schema);
        $avant = $this->empreinte();
        $etat = EtatSite::lire($this->actes, $this->prefixe, $this->composer);
        self::assertSame($attendu, $etat->etat);
        self::assertSame($avant, $this->empreinte());
        self::assertSame($produit, $etat->livraison->version);
        $marqueurs = [];
        foreach ($params as [$nom, $valeur]) {
            if (Marqueurs::est($nom)) { $marqueurs[$nom] = $valeur; }
        }
        $observes = $etat->marqueurs;
        ksort($marqueurs);
        ksort($observes);
        self::assertSame($marqueurs, $observes);
        if (array_key_exists('ACTALIBRE_VERSION', $marqueurs)) {
            self::assertStringNotContainsString('EA_VERSION', implode("\n", $this->actes->requetes));
        }
        foreach ($this->actes->requetes as $sql) {
            if (isset($marqueurs['ACTALIBRE_INSTALLATION']) && !array_key_exists('ACTALIBRE_VERSION', $marqueurs) && str_contains($sql, 'EA_VERSION')) {
                self::assertMatchesRegularExpression('/\ASELECT 1\b/', $sql);
                self::assertStringNotContainsString('valeur', $sql);
            }
        }
    }

    public function testConsulteLaConnexionEtLePrefixeDesComptes(): void
    {
        foreach (array_diff(self::SOCLE, ['user3']) as $table) { $this->table($table); }
        $this->table('user3', $this->prefixeComptes);
        $this->parametres([['EA_VERSION', '3.2.4']]);
        $avant = $this->empreinte();
        self::assertSame('expoactes_admis', EtatSite::lire($this->actes, $this->prefixe, $this->composer, $this->comptes, $this->prefixeComptes)->etat);
        self::assertStringContainsString($this->prefixeComptes . '_user3', implode("\n", $this->comptes->requetes));
        self::assertSame($avant, $this->empreinte());
        $this->comptes->requetes = [];
        $this->comptes->echecAuRang = 1;
        self::assertSame('illisible', EtatSite::lire($this->actes, $this->prefixe, $this->composer, $this->comptes, $this->prefixeComptes)->etat);
    }

    public function testCompteDejaPresentEmpecheUneInstallationAutomatique(): void
    {
        $this->table('user3', $this->prefixeComptes);
        self::assertSame('incoherente', EtatSite::lire($this->actes, $this->prefixe, $this->composer, $this->comptes, $this->prefixeComptes)->etat);
    }

    public function testAccepteLesComptagesNumeriquesDuPiloteMysqli(): void
    {
        $this->table('params');
        $this->actes->close();
        $this->actes = new ConnexionLecture();
        $this->actes->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, true);
        $this->actes->real_connect(getenv('GM_DB_HOST'), getenv('GM_DB_USER'), getenv('GM_DB_PASS'), getenv('GM_DB_NAME'));
        self::assertSame('base_vide', EtatSite::lire($this->actes, $this->prefixe, $this->composer)->etat);
        self::assertSame('base_vide', EtatSite::lire($this->comptes, $this->prefixe, $this->composer, $this->actes)->etat);
    }

    public function testNeConfondNiLesPrefixesVoisinsNiLeurCasse(): void
    {
        $this->table('params', $this->prefixe . 'x');
        self::assertSame('base_vide', EtatSite::lire($this->actes, $this->prefixe, $this->composer)->etat);
        $this->table('PARAMS');
        $this->preparation->query("INSERT INTO `{$this->prefixe}_PARAMS` VALUES ('ACTALIBRE_INSTALLATION', 'début')");
        self::assertSame('installation_inachevee', EtatSite::lire($this->actes, strtoupper($this->prefixe), $this->composer)->etat);
        self::assertStringContainsString($this->prefixe . '_PARAMS', implode("\n", $this->actes->requetes));
    }

    public function testUnPrefixeImbriqueNeRendPasLePerimetreVide(): void
    {
        $this->table('params', $this->prefixe . '_x');
        self::assertSame('incoherente', EtatSite::lire($this->actes, $this->prefixe, $this->composer)->etat);
    }

    public function testRefuseUneAmbiguiteDeCasse(): void
    {
        if ((int) $this->preparation->query('SELECT @@lower_case_table_names')->fetch_row()[0] !== 0) {
            // Le serveur ne permet pas de créer les deux noms ; le test de casse précédent reste exécuté.
            self::assertNotSame(0, (int) $this->preparation->query('SELECT @@lower_case_table_names')->fetch_row()[0]);
            return;
        }
        $this->table('params');
        $this->table('PARAMS');
        self::assertSame('incoherente', EtatSite::lire($this->actes, $this->prefixe, $this->composer)->etat);
    }

    public static function prefixesInvalides(): array
    {
        return array_map(static fn (string $p): array => [$p], ['', str_repeat('a', 56), 'a-b', "a'b", 'a`b', 'a%b', 'a b']);
    }

    #[DataProvider('prefixesInvalides')]
    public function testRefuseUnPrefixeAvantToutSql(string $prefixe): void
    {
        try {
            EtatSite::lire($this->actes, $prefixe, $this->composer);
            self::fail('Préfixe refusé attendu.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $this->actes->requetes);
        }
    }

    public function testTouteErreurDeLectureResteUneErreur(): void
    {
        foreach (self::SOCLE as $table) { $this->table($table); }
        $this->parametres([['EA_VERSION', '3.2.4']]);
        EtatSite::lire($this->actes, $this->prefixe, $this->composer);
        $nombre = count($this->actes->requetes);
        for ($rang = 1; $rang <= $nombre; $rang++) {
            $this->actes->requetes = [];
            $this->actes->echecAuRang = $rang;
            $etat = EtatSite::lire($this->actes, $this->prefixe, $this->composer);
            self::assertSame('illisible', $etat->etat, 'Lecture ' . $rang);
            self::assertStringNotContainsString('SELECT', $etat->diagnostic);
        }
    }

    public function testLesErreursSqlSontGereesDansLesDeuxModesMysqli(): void
    {
        $this->table('params');
        $this->preparation->query("ALTER TABLE `{$this->prefixe}_params` DROP valeur");
        foreach ([MYSQLI_REPORT_OFF, MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT] as $mode) {
            mysqli_report($mode);
            self::assertSame('illisible', EtatSite::lire($this->actes, $this->prefixe, $this->composer)->etat);
            self::assertSame($mode, (new \mysqli_driver())->report_mode);
        }
    }

    public function testRelitLaLivraisonSansCacheNiValeurDeSecours(): void
    {
        foreach (self::SOCLE as $table) { $this->table($table); }
        $this->parametres([['ACTALIBRE_VERSION', '1.0.0'], ['ACTALIBRE_SCHEMA', '1.0.0']]);
        $date = filemtime($this->composer);
        $taille = filesize($this->composer);
        self::assertSame('a_jour', EtatSite::lire($this->actes, $this->prefixe, $this->composer)->etat);
        $this->livraison('1.0.1', '1.0.0');
        touch($this->composer, $date);
        clearstatcache(true, $this->composer);
        self::assertSame($taille, filesize($this->composer));
        self::assertSame($date, filemtime($this->composer));
        self::assertSame('mise_a_jour_produit', EtatSite::lire($this->actes, $this->prefixe, $this->composer)->etat);
        file_put_contents($this->composer, '{"secret":"sentinelle_confidentielle",');
        $this->actes->requetes = [];
        $etat = EtatSite::lire($this->actes, $this->prefixe, $this->composer);
        self::assertSame('livraison_invalide', $etat->etat);
        self::assertNull($etat->livraison);
        self::assertSame([], $this->actes->requetes);
        self::assertStringNotContainsString('sentinelle_confidentielle', $etat->diagnostic);
        unlink($this->composer);
        self::assertSame('livraison_invalide', EtatSite::lire($this->actes, $this->prefixe, $this->composer)->etat);
        file_put_contents($this->composer, '{}');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLesConstantesNeSontPasLesMarqueursInstalles(): void
    {
        define('ACTALIBRE_VERSION', '9.0.0');
        define('ACTALIBRE_SCHEMA', '9.0.0');
        foreach (self::SOCLE as $table) { $this->table($table); }
        $this->parametres([['ACTALIBRE_VERSION', '1.0.0'], ['ACTALIBRE_SCHEMA', '1.0.0']]);
        self::assertSame('a_jour', EtatSite::lire($this->actes, $this->prefixe, $this->composer)->etat);
    }
}
