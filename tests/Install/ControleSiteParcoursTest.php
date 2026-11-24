<?php
declare(strict_types=1);
namespace ActaLibre\Tests\Install;
use ActaLibre\Install\{AccesMysql,Avancement,FichierConnexion};
use ActaLibre\Livraison;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class ControleSiteParcoursTest extends TestCase
{
    private SiteInstallation $site;
    protected function setUp(): void
    {
        foreach (['HOST','NAME','USER','PASS'] as $cle) if (getenv('GM_DB_'.$cle)===false) self::markTestSkipped('Configurer GM_DB_*');
        $this->site=new SiteInstallation(dirname(__DIR__,2),['hote'=>(string)getenv('GM_DB_HOST'),'nom'=>(string)getenv('GM_DB_NAME'),'utilisateur'=>(string)getenv('GM_DB_USER'),'passe'=>(string)getenv('GM_DB_PASS')]);
        $this->site->demarrer();
    }
    protected function tearDown(): void { if (isset($this->site)) $this->site->arreter(); }
    private function source(): void
    {
        SourceExpoActes::creer($this->site->base,$this->site->prefixe,$this->site->base,$this->site->prefixe);
        mkdir($this->site->racine.'/_config');$c=$this->site->connexion;
        file_put_contents($this->site->racine.'/_config/connect.inc.php',FichierConnexion::contenu(new AccesMysql($c['hote'],$c['nom'],$c['utilisateur'],$c['passe'],$this->site->prefixe)));
    }
    private function empreinte(): string
    {
        $l=[];foreach ($this->site->base->query('SHOW TABLES')->fetch_all() as [$t]) {
            if (!str_starts_with($t,$this->site->prefixe.'_')) continue;
            $l[$t]=[$this->site->base->query("SHOW CREATE TABLE `$t`")->fetch_row()[1],$this->site->base->query("SELECT * FROM `$t`")->fetch_all()];
        }
        return hash('sha256',serialize($l));
    }
    public static function etatsBloquants(): iterable
    { foreach (['migration','refus','sql','livraison','futur'] as $e) yield $e=>[$e]; }
    #[DataProvider('etatsBloquants')]
    public function testLeControlePrecedePreProcessEtTouteEcriture(string $etat): void
    {
        $this->source();$p=$this->site->prefixe;
        $json=Avancement::commencer(Livraison::lire($this->site->racine.'/composer.json'))->encoder();
        if ($etat==='refus') $this->site->base->query("UPDATE {$p}_params SET valeur='4.0.3' WHERE param='EA_VERSION'");
        elseif ($etat==='sql') $this->site->base->query("ALTER TABLE {$p}_params CHANGE valeur autre TEXT NOT NULL");
        elseif ($etat==='livraison') file_put_contents($this->site->racine.'/composer.json','{');
        elseif ($etat==='futur') {
            $this->site->base->query("INSERT INTO {$p}_params (param,valeur,aide) VALUES ('ACTALIBRE_VERSION','1.1.3',''),('ACTALIBRE_SCHEMA','1.0.0','')");
            $a=json_decode($json,true);$a['cible']=['produit'=>'1.1.0','schema'=>'1.1.0'];$json=json_encode($a);
        }
        if (in_array($etat,['migration','futur'],true)) {
            $q=$this->site->base->prepare("INSERT INTO {$p}_params (param,valeur,aide) VALUES ('ACTALIBRE_MIGRATION',?,'')");$q->execute([$json]);
        }
        file_put_contents($this->site->racine.'/tools/PreProcess.inc.php','<?php file_put_contents(__DIR__."/../effet-preprocess", "interdit");');
        $avant=$this->empreinte();$c=$this->site->client();
        foreach (['/index.php','/login.php','/cree_compte.php','/changepw.php','/admin/index.php','/rss.php','/eclair.php','/stat_annees.php'] as $page) {
            $r=$c->get($page);self::assertSame(503,$r->statut,$page.' '.$r->corps);
            self::assertStringContainsString('maintenance',$r->corps);self::assertFileDoesNotExist($this->site->racine.'/effet-preprocess');
            self::assertSame($avant,$this->empreinte());
            if (str_starts_with($page,'/admin')) self::assertStringContainsString('href="../update.php"',$r->corps);
        }
        self::assertSame('',$this->site->journal());
    }
    public function testUneInstallationNeuveSansEAParcourtLeSite(): void
    {
        $c=$this->site->client();
        $r=InstallationHttp::installer($c,$this->site->racine,$this->site->formulaire());self::assertSame(200,$r->statut,$r->corps);
        $this->site->base->query("UPDATE {$this->site->prefixe}_params SET valeur='3' WHERE param='EA_ERROR'");
        $accueil=$c->get('/index.php');self::assertSame(200,$accueil->statut,$accueil->corps.$this->site->journal());self::assertSame(200,$c->get('/login.php')->statut);
        $r=$c->post('/admin/index.php',['login'=>'admin','passwd'=>'Secret-2026']);self::assertSame(200,$r->statut,$r->corps);
        self::assertSame(200,$c->get('/admin/listusers.php')->statut);
        $r=$c->get('/update.php');self::assertStringContainsString('à jour',$r->corps);self::assertStringNotContainsString('name="mot_de_passe"',$r->corps);
        self::assertSame('0',$this->site->base->query("SELECT COUNT(*) FROM {$this->site->prefixe}_params WHERE param='EA_VERSION'")->fetch_row()[0]);
        self::assertSame('',$this->site->journal());
    }
    public function testUneNouvelleRequeteRelitLaLivraisonEtLesMarqueurs(): void
    {
        $this->source();$p=$this->site->prefixe;
        $this->site->base->query("INSERT INTO {$p}_params (param,valeur,aide) VALUES ('ACTALIBRE_VERSION','1.1.3',''),('ACTALIBRE_SCHEMA','1.0.0','')");
        file_put_contents($this->site->racine.'/tools/PreProcess.inc.php','<?php echo json_encode([ACTALIBRE_VERSION,ACTALIBRE_SCHEMA,defined("EA_VERSION"),defined("EA_VERSION_PRG")]);exit;');
        $c=$this->site->client();self::assertSame(['1.1.3','1.0.0',false,false],json_decode($c->get('/index.php')->corps,true));
        $this->site->base->query("UPDATE {$p}_params SET valeur='1.1.0' WHERE param='ACTALIBRE_SCHEMA'");
        self::assertSame(503,$c->get('/index.php')->statut);
        $this->site->base->query("UPDATE {$p}_params SET valeur='1.0.0' WHERE param='ACTALIBRE_SCHEMA'");
        self::assertSame(200,$c->get('/index.php')->statut);
        file_put_contents($this->site->racine.'/composer.json','{');self::assertSame(503,$c->get('/index.php')->statut);
    }
    public function testUnResteTermineAutoriseLeSiteSansLeNettoyer(): void
    {
        $this->source();$p=$this->site->prefixe;
        $this->site->base->query("INSERT INTO {$p}_params (param,valeur,aide) VALUES ('ACTALIBRE_VERSION','1.1.3',''),('ACTALIBRE_SCHEMA','1.0.0',''),('ACTALIBRE_INSTALLATION','debut','')");
        file_put_contents($this->site->racine.'/tools/PreProcess.inc.php','<?php echo "site ouvert";exit;');$avant=$this->empreinte();
        self::assertSame('site ouvert',$this->site->client()->get('/index.php')->corps);self::assertSame($avant,$this->empreinte());
    }
}
