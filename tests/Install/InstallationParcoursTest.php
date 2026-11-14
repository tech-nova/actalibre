<?php

declare (strict_types=1);
namespace ActaLibre\Tests\Install;

use ActaLibre\Tests\GoldenMaster\Client;
use ActaLibre\Compte\MotDePasse;
use ActaLibre\Install\EtatSite;
use PHPUnit\Framework\TestCase;
final class InstallationParcoursTest extends TestCase
{
    private SiteInstallation $site;
    protected function setUp(): void
    {
        foreach (['HOST', 'NAME', 'USER', 'PASS'] as $c) {
            if (getenv('GM_DB_' . $c) === false) {
                self::markTestSkipped('Configurer GM_DB_*.');
            }
        }
        $this->site = new SiteInstallation(dirname(__DIR__, 2), ['hote' => (string) getenv('GM_DB_HOST'), 'nom' => (string) getenv('GM_DB_NAME'), 'utilisateur' => (string) getenv('GM_DB_USER'), 'passe' => (string) getenv('GM_DB_PASS')]);
    }
    protected function tearDown(): void
    {
        if (isset($this->site)) {
            $this->site->arreter();
        }
    }
    private function jeton(Client $c): string
    {
        $r = $c->get('/install.php');
        self::assertSame(200, $r->statut);
        self::assertSame(1, preg_match('/name="jeton" value="([0-9a-f]{64})"/', $r->corps, $m), $r->corps);
        return $m[1];
    }
    /** Écran 1 : seule la connexion est validée, puis l'installation revient sur elle-même. */
    private function connecter(Client $c, array $supplement = []): void
    {
        $r = $c->post('/install.php', $this->site->formulaire() + $supplement + ['jeton' => $this->jeton($c)]);
        self::assertSame(303, $r->statut, $r->corps);
        self::assertStringContainsString('Location: install.php', $r->entetes);
        self::assertFileExists($this->site->racine . '/_config/connect.inc.php');
    }
    /** Écran 2 : nom du fichier de preuve demandé à ce navigateur. */
    private function preuve(Client $c): string
    {
        $r = $c->get('/install.php');
        self::assertSame(200, $r->statut, $r->corps);
        self::assertStringContainsString('Étape 2 sur 2', $r->corps);
        self::assertSame(1, preg_match('/admin-[0-9a-f]{32}\.txt/', $r->corps, $m), $r->corps);
        return $this->site->racine . '/_config/' . $m[0];
    }
    public function testInstalleSansVendorDepuisUnSousDossier(): void
    {
        $this->site->demarrer(true);
        $c = $this->site->client();
        $this->jeton($c);
        foreach (['.htaccess', '_config', 'tmp/logs', 'tmp/backups', 'admin/_upload'] as $f) {
            self::assertFileDoesNotExist($this->site->racine . '/' . $f);
        }
        $avant = $this->empreinte();
        $this->connecter($c);
        self::assertSame($avant, $this->empreinte(), 'L’écran de connexion n’écrit rien en base.');
        $accueil = $c->get('/index.php');
        self::assertSame(503, $accueil->statut);
        self::assertStringContainsString('install.php"', $accueil->corps, 'L’accueil mène à l’écran administrateur.');
        $r = $c->get('/update.php');
        self::assertSame(303, $r->statut);
        self::assertStringContainsString('Location: install.php', $r->entetes);
        $preuve = $this->preuve($c);
        $r = $c->post('/install.php', $this->site->formulaire() + ['jeton' => $this->jeton($c)]);
        self::assertSame(400, $r->statut);
        self::assertStringContainsString('preuve', $r->corps);
        self::assertSame($avant, $this->empreinte());
        $autre = $this->site->deuxiemeClient();
        touch($this->preuve($autre));
        $r = $c->post('/install.php', $this->site->formulaire() + ['jeton' => $this->jeton($c)]);
        self::assertSame(400, $r->statut, 'La preuve d’un autre navigateur ne vaut pas pour celui-ci.');
        touch($preuve);
        $r = $c->post('/install.php', $this->site->formulaire() + ['jeton' => $this->jeton($c)]);
        self::assertSame(200, $r->statut, $r->corps);
        self::assertStringContainsString('Installation terminée', $r->corps);
        self::assertStringNotContainsString('Secret-2026', $r->corps);
        self::assertFileDoesNotExist($preuve);
        self::assertFileExists($this->site->racine . '/_config/connect.inc.php');
        $p = $this->site->prefixe;
        self::assertSame('a_jour', EtatSite::lire($this->site->base, $p, $this->site->racine . '/composer.json')->etat);
        $compte = $this->site->base->query("SELECT level,hashpass FROM {$p}_user3")->fetch_assoc();
        self::assertSame('9', $compte['level']);
        self::assertTrue(MotDePasse::verifier('Secret-2026', $compte['hashpass']));
        self::assertSame('0', $this->site->base->query("SELECT COUNT(*) FROM {$p}_params WHERE param='EA_VERSION'")->fetch_row()[0]);
        self::assertSame('Mon site principal', $this->site->base->query("SELECT valeur FROM {$p}_params WHERE param='SITENAME'")->fetch_row()[0]);
        self::assertSame('', $this->site->journal());
    }
    /** Socle de lecture strict, pas une simulation de migration de données. */
    private function etatMinimal(string $version, bool $separes = false): void
    {
        $p = $this->site->prefixe;
        (new \ActaLibre\Install\DonneesInitiales($this->site->racine))->creerParametres($this->site->base, $p);
        foreach (['nai3', 'mar3', 'dec3', 'div3', 'metadb', 'metalg', 'mgrplg'] as $t) {
            $this->site->base->query("CREATE TABLE {$p}_{$t} (id INT)");
        }
        $u = $separes ? $p . '_u' : $p;
        $this->site->base->query("CREATE TABLE {$u}_user3 (ID INT AUTO_INCREMENT PRIMARY KEY,login VARCHAR(15),hashpass VARCHAR(40),level INT,email VARCHAR(50)) DEFAULT CHARSET=latin1 COLLATE=latin1_general_ci");
        $q = $this->site->base->prepare("INSERT INTO {$u}_user3(login,hashpass,level,email) VALUES ('Admin',?,9,'a@example.org')");
        $q->execute([sha1('Secret-2026')]);
        $lignes = match ($version) {
            'actuel' => ['ACTALIBRE_VERSION' => '1.0.0', 'ACTALIBRE_SCHEMA' => '1.0.0'],
            'schema' => ['ACTALIBRE_VERSION' => '0.9.0', 'ACTALIBRE_SCHEMA' => '0.9.0'],
            'produit' => ['ACTALIBRE_VERSION' => '0.9.0', 'ACTALIBRE_SCHEMA' => '1.0.0'],
            'futur' => ['ACTALIBRE_VERSION' => '2.0.0', 'ACTALIBRE_SCHEMA' => '1.0.0'],
            default => ['EA_VERSION' => $version],
        };
        foreach ($lignes as $nom => $valeur) {
            $q = $this->site->base->prepare("INSERT INTO {$p}_params(param,groupe,ordre,type,valeur,listval,libelle,aide) VALUES (?,'Hidden',100,'C',?,'','','')");
            $q->execute([$nom, $valeur]);
        }
    }
    private function empreinte(): string
    {
        $lignes = [];
        foreach ($this->site->base->query('SHOW TABLES')->fetch_all() as [$t]) {
            if (!str_starts_with($t, $this->site->prefixe . '_')) {
                continue;
            }
            $contenu = array_map('serialize', $this->site->base->query("SELECT * FROM `{$t}`")->fetch_all());
            sort($contenu);
            $lignes[$t] = [$this->site->base->query("SHOW CREATE TABLE `{$t}`")->fetch_row()[1], $contenu];
        }
        ksort($lignes);
        return hash('sha256', serialize($lignes));
    }
    public static function rattachements(): array
    {
        return [['3.2.4', false], ['actuel', false], ['schema', false], ['produit', false], ['3.2.4', true], ['actuel', true]];
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('rattachements')]
    public function testRattacherNeModifieNiLesComptesNiLesDonnees(string $version, bool $separes): void
    {
        $this->site->demarrer();
        $this->etatMinimal($version, $separes);
        $c = $this->site->client();
        $avant = $this->empreinte();
        $supplement = [];
        if ($separes) {
            $f = $this->site->formulaire();
            $supplement = ['comptes_distincts' => '1', 'comptes_serveur' => $f['serveur'], 'comptes_base' => $f['base'], 'comptes_utilisateur' => $f['utilisateur'], 'comptes_mot_de_passe_mysql' => $f['mot_de_passe_mysql'], 'comptes_prefixe' => $f['prefixe'] . '_u'];
        }
        $this->connecter($c, $supplement);
        $r = $c->get('/install.php');
        self::assertSame(303, $r->statut, $r->corps);
        self::assertStringContainsString('Location: update.php', $r->entetes);
        self::assertSame($avant, $this->empreinte());
        self::assertSame('', $this->site->journal());
        // Ne pas suivre la destination du lot 4.
    }
    public static function refus(): array
    {
        return [['3.2.2'], ['3.2.3'], ['3.2.4b'], ['4.0.3'], ['futur']];
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('refus')]
    public function testUnEtatRefuseNeSubitAucuneEcriture(string $version): void
    {
        $this->site->demarrer();
        $this->etatMinimal($version);
        $c = $this->site->client();
        $j = $this->jeton($c);
        $avant = $this->empreinte();
        $r = $c->post('/install.php', $this->site->formulaire() + ['jeton' => $j]);
        self::assertSame(400, $r->statut);
        self::assertSame($avant, $this->empreinte());
        self::assertFileDoesNotExist($this->site->racine . '/_config/connect.inc.php');
        self::assertStringNotContainsString('Secret-2026', $r->corps);
        self::assertSame('', $this->site->journal());
    }
    public function testLinkIndisponiblePermetLeDepotManuel(): void
    {
        $this->site->demarrer(false, ['disable_functions=link']);
        $c = $this->site->client();
        $j = $this->jeton($c);
        $r = $c->post('/install.php', $this->site->formulaire() + ['jeton' => $j]);
        self::assertSame(200, $r->statut);
        self::assertStringContainsString('Déposez ce contenu exact', $r->corps);
        self::assertStringContainsString('href="install.php"', $r->corps);
        self::assertSame(1, preg_match('~<textarea[^>]*>(.*?)</textarea>~s', $r->corps, $m));
        self::assertStringContainsString('Cache-Control: no-store', $r->entetes);
        $f = $this->site->racine . '/_config/connect.inc.php';
        self::assertFileDoesNotExist($f);
        file_put_contents($f, html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $code = 'require ' . var_export($f, true) . '; $m=new mysqli($dbaddr,$dbuser,$dbpass,$dbname); echo $m->query("SELECT DATABASE()")->fetch_row()[0];';
        $p = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $t);
        $sortie = stream_get_contents($t[1]);
        $erreurs = stream_get_contents($t[2]);
        fclose($t[1]);
        fclose($t[2]);
        self::assertSame(0, proc_close($p), $erreurs);
        self::assertSame($this->site->connexion['nom'], $sortie);
        $this->preuve($c);
        self::assertSame('', $this->site->journal());
    }
    public function testUneDeuxiemeRequeteNePeutPasInstallerPendantLaPremiere(): void
    {
        $this->site->demarrer();
        $c = $this->site->deuxiemeClient();
        $pret = dirname($this->site->racine) . '/pret';
        $suite = dirname($this->site->racine) . '/suite';
        $script = dirname($this->site->racine) . '/premier.php';
        $f = $this->site->formulaire();
        $code = '<?php require ' . var_export(dirname(__DIR__, 2) . '/src/autoload.php', true) . '; $a=new ActaLibre\Install\AccesMysql(' . implode(',', array_map(static fn($v) => var_export($v, true), [$f['serveur'], $f['base'], $f['utilisateur'], $f['mot_de_passe_mysql'], $f['prefixe']])) . '); $i=new ActaLibre\Install\Installateur(' . var_export($this->site->racine, true) . ',function($e){if($e!=="installation")return;file_put_contents(' . var_export($pret, true) . ',"prêt");$limite=microtime(true)+15;while(!is_file(' . var_export($suite, true) . ') && microtime(true)<$limite)usleep(10000);});$r=$i->connecter($a);$i->installer($a,null,"admin","admin@example.org","Secret-2026","Secret-2026","https://example.org",true);echo $r["publication"];';
        touch($script);
        chmod($script, 0600);
        file_put_contents($script, $code);
        $p = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $t);
        try {
            $limite = microtime(true) + 5;
            while (!is_file($pret) && microtime(true) < $limite) {
                usleep(10000);
            }
            self::assertFileExists($pret);
            touch($this->preuve($c));
            $avant = $this->empreinte();
            $r = $c->post('/install.php', array_replace($f, ['login' => 'intrus']) + ['jeton' => $this->jeton($c)]);
            self::assertSame(400, $r->statut);
            self::assertStringContainsString('installation en cours', $r->corps);
            self::assertSame($avant, $this->empreinte());
        } finally {
            file_put_contents($suite, 'suite');
        }
        $sortie = stream_get_contents($t[1]);
        $erreur = stream_get_contents($t[2]);
        fclose($t[1]);
        fclose($t[2]);
        self::assertSame(0, proc_close($p), $erreur);
        self::assertSame('publie', $sortie);
        $r = $c->get('/install.php');
        self::assertSame(303, $r->statut);
        self::assertStringContainsString('Location: update.php', $r->entetes);
        self::assertSame('1', $this->site->base->query("SELECT COUNT(*) FROM {$this->site->prefixe}_user3")->fetch_row()[0]);
        self::assertSame('', $this->site->journal());
    }
}
