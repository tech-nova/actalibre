<?php
declare(strict_types=1);
namespace ActaLibre\Tests\Distribution;

use ActaLibre\Install\{AccesMysql,FichierConnexion,Inventaire};
use ActaLibre\Tests\Infrastructure\ServeurApacheJetable;
use ActaLibre\Tests\Install\{BaseInstallation,SourceExpoActes};
use ActaLibre\Tests\GoldenMaster\Client;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Exécute les fichiers du vrai ZIP commité, sans vendor ni générateur d'inventaire du banc. */
final class ArchiveParcoursTest extends TestCase
{
    private static string $zip;
    private static string $revision;
    private static string $sortie;
    private string $root;
    private string $prefixe;
    private \mysqli $actes;
    private \mysqli $comptes;
    private ?ServeurApacheJetable $serveur=null;

    /** @param list<string> $args */
    private static function commande(array $args): string
    {
        $p=proc_open($args,[1=>['pipe','w'],2=>['pipe','w']],$t,dirname(__DIR__,2));
        self::assertIsResource($p);
        $sortie=stream_get_contents($t[1]);$erreur=stream_get_contents($t[2]);fclose($t[1]);fclose($t[2]);
        self::assertSame(0,proc_close($p),$erreur);
        return $sortie;
    }
    public static function setUpBeforeClass(): void
    {
        foreach (['HOST','NAME','NAME_COMPTES','USER','PASS'] as $v) { self::assertNotFalse(getenv('GM_DB_'.$v),'Configuration indispensable : GM_DB_'.$v); }
        self::assertNotSame(getenv('GM_DB_NAME'),getenv('GM_DB_NAME_COMPTES'),'Deux bases physiques distinctes indispensables.');
        self::$revision=getenv('AL_DISTRIBUTION_REVISION') ?: trim(self::commande(['git','rev-parse','HEAD']));
        self::$sortie=getenv('AL_DISTRIBUTION_SORTIE') ?: sys_get_temp_dir().'/al5-distribution-'.bin2hex(random_bytes(5));
        self::commande(['python3','tools/construire_distribution.py','--revision',self::$revision,'--sortie',self::$sortie.'/a']);
        self::commande(['python3','tools/construire_distribution.py','--revision',self::$revision,'--sortie',self::$sortie.'/b']);
        $archives=glob(self::$sortie.'/a/*.zip');self::assertCount(1,$archives);self::$zip=$archives[0];
        self::assertSame(hash_file('sha256',self::$zip),hash_file('sha256',self::$sortie.'/b/'.basename(self::$zip)));
        // Comparer aux identifiants de blobs Git ne lit aucun contenu historique exclu.
        $verification=<<<'PYTHON'
import hashlib, json, subprocess, sys, zipfile
meta={}
for ligne in subprocess.check_output(['git','ls-tree','-rz','--full-tree',sys.argv[1]]).split(b'\0'):
    if ligne:
        objet,nom=ligne.split(b'\t',1)
        meta[nom.decode()]=objet.split()[2].decode()
with zipfile.ZipFile(sys.argv[2]) as z:
    noms=z.namelist()
    assert len(noms)==len(set(noms))
    assert 'install/configuration.php' not in noms
    assert '_config_init/connect.inc.php' not in noms
    for livre in ['tools/js/geoloc.js','tools/css/geoloc.css','admin/geoloc_travail.php','src/ActaLibre/Geoloc/Moteur.php','src/ActaLibre/Geoloc/Travaux.php']:
        assert livre in noms, livre
    for nom in noms:
        assert not any(p in {'vendor','tests','docs','.git','.superpowers','graft','_config','_logs','_backup','tmp'} for p in nom.split('/'))
        assert nom != 'composer.lock'
        if nom != 'inventaire-actalibre.json':
            contenu=z.read(nom)
            assert hashlib.sha1(b'blob '+str(len(contenu)).encode()+b'\0'+contenu).hexdigest()==meta[nom], nom
    inventaire=json.loads(z.read('inventaire-actalibre.json'))
    assert inventaire['revision']==sys.argv[1]
    assert inventaire['fichiers']==sorted(noms)
PYTHON;
        self::commande(['python3','-c',$verification,self::$revision,self::$zip]);
    }
    protected function setUp(): void
    {
        $this->prefixe='al4_'.bin2hex(random_bytes(6));
        $this->root=sys_get_temp_dir().'/'.$this->prefixe;mkdir($this->root,0755);
        self::commande(['python3','-c','import sys,zipfile; zipfile.ZipFile(sys.argv[1]).extractall(sys.argv[2])',self::$zip,$this->root]);
        $this->actes=new \mysqli(getenv('GM_DB_HOST'),getenv('GM_DB_USER'),getenv('GM_DB_PASS'),getenv('GM_DB_NAME'));$this->actes->set_charset('utf8mb4');
        $this->comptes=new \mysqli(getenv('GM_DB_HOST'),getenv('GM_DB_USER'),getenv('GM_DB_PASS'),getenv('GM_DB_NAME_COMPTES'));$this->comptes->set_charset('utf8mb4');
        Inventaire::lire($this->root)->verifierPresence();
        self::assertDirectoryDoesNotExist($this->root.'/vendor');self::assertFileDoesNotExist($this->root.'/composer.lock');
    }
    protected function tearDown(): void
    {
        $journal=$this->serveur?->journalPhp() ?? '';
        $this->serveur?->arreter();
        if (isset($this->actes)) { SourceExpoActes::supprimer($this->actes,$this->prefixe);$this->actes->close(); }
        if (isset($this->comptes)) { SourceExpoActes::supprimer($this->comptes,$this->prefixe.'u');$this->comptes->close(); }
        if (isset($this->root)) { BaseInstallation::supprimer($this->root); }
        self::assertSame('',$journal,'Journal PHP Apache');
    }
    private function client(bool $sousDossier=false): Client
    {
        $this->serveur=ServeurApacheJetable::demarrer($this->root,$sousDossier);
        return new Client(rtrim($this->serveur->url(),'/'));
    }
    private function jeton(string $html): string
    {
        self::assertSame(1,preg_match('/name="jeton" value="([0-9a-f]{64})"/',$html,$m),$html);
        return $m[1];
    }
    /** @return array<string,string> */
    private function formulaire(): array
    {
        return ['url_site'=>$this->serveur->url(),'serveur'=>getenv('GM_DB_HOST'),'base'=>getenv('GM_DB_NAME'),'utilisateur'=>getenv('GM_DB_USER'),'mot_de_passe_mysql'=>getenv('GM_DB_PASS'),'prefixe'=>$this->prefixe,'login'=>'admin','courriel'=>'admin@example.invalid','mot_de_passe'=>'Secret-archive-2026','confirmation'=>'Secret-archive-2026'];
    }
    public static function installations(): iterable
    {
        yield 'racine'=>[false,false];
        yield 'sous-dossier'=>[true,false];
        yield 'inventaire manquant'=>[false,true];
    }
    #[DataProvider('installations')]
    public function testInstallationViergeDeLArchive(bool $sousDossier,bool $sansInventaire): void
    {
        if ($sansInventaire) { unlink($this->root.'/inventaire-actalibre.json'); }
        file_put_contents($this->root.'/inconnu-'.str_repeat('a',12).'.php','SENTINELLE INERTE');
        $c=$this->client($sousDossier);$get=$c->get('/install.php');self::assertSame(200,$get->statut);
        $r=\ActaLibre\Tests\Install\InstallationHttp::installer($c,$this->root,$this->formulaire());
        self::assertSame(200,$r->statut,$r->corps);self::assertStringContainsString('Installation terminée',$r->corps);
        self::assertStringNotContainsString('Secret-archive-2026',$r->corps);
        self::assertSame('0',$this->actes->query("SELECT COUNT(*) FROM {$this->prefixe}_params WHERE param='EA_VERSION'")->fetch_row()[0]);
        self::assertSame('1',$this->actes->query("SELECT COUNT(*) FROM {$this->prefixe}_user3 WHERE level=9")->fetch_row()[0]);
        self::assertSame(['1.0.0','1.2.0'],array_column($this->actes->query("SELECT valeur FROM {$this->prefixe}_params WHERE param IN ('ACTALIBRE_VERSION','ACTALIBRE_SCHEMA') ORDER BY param")->fetch_all(MYSQLI_ASSOC),'valeur'));
        self::assertFileExists($this->root.'/tmp/logs/.keep');self::assertFileExists($this->root.'/tmp/backups/.keep');
        self::assertFileExists($this->root.'/_config/connect.inc.php');
        self::assertSame('SENTINELLE INERTE',file_get_contents($this->root.'/inconnu-'.str_repeat('a',12).'.php'));
        if ($sansInventaire) { self::assertStringContainsString('Analyse des fichiers non réalisée',$r->corps); }
        else { self::assertStringContainsString('inconnu-'.str_repeat('a',12).'.php',$r->corps); }
        self::assertSame(200,$c->get('/index.php')->statut);self::assertSame(200,$c->get('/login.php')->statut);
        self::assertSame(200,$c->post('/admin/index.php',['login'=>'admin','passwd'=>'Secret-archive-2026'])->statut);
        self::assertStringContainsString('à jour',$c->get('/update.php')->corps);
        $css=$c->get('/_config/actes.css');self::assertSame(200,$css->statut);
        // Le stockage de la géolocalisation (créé au premier géocodage) reste privé, comme le reste de tmp/.
        is_dir($this->root.'/tmp/actalibre/geocodage') || mkdir($this->root.'/tmp/actalibre/geocodage',0700,true);file_put_contents($this->root.'/tmp/actalibre/geocodage/debit.json','{"pasAvant":0}');
        self::assertSame(403,$c->get('/tmp/actalibre/geocodage/debit.json')->statut);
    }
    public static function migrations(): iterable { yield 'archive'=>[false];yield 'sources conservees'=>[true]; }
    #[DataProvider('migrations')]
    public function testMigrationReelleSurDeuxBasesEtArchivageInformatif(bool $obstacle): void
    {
        SourceExpoActes::creer($this->actes,$this->prefixe,$this->comptes,$this->prefixe.'u');
        mkdir($this->root.'/_config');
        $a=new AccesMysql(getenv('GM_DB_HOST'),getenv('GM_DB_NAME'),getenv('GM_DB_USER'),getenv('GM_DB_PASS'),$this->prefixe);
        $u=new AccesMysql($a->serveur,getenv('GM_DB_NAME_COMPTES'),$a->utilisateur,$a->motDePasse,$this->prefixe.'u');
        file_put_contents($this->root.'/_config/connect.inc.php',FichierConnexion::contenu($a,$u));
        file_put_contents($this->root.'/.htaccess',"# personnalisation de test conservee\n");
        foreach (['_logs','_backup'] as $p) { mkdir($this->root.'/'.$p);file_put_contents($this->root.'/'.$p.'/temoin','DONNEE HISTORIQUE'); }
        $inode=fileinode($this->root.'/_logs/temoin');
        if ($obstacle) { file_put_contents($this->root.'/tmp','OBSTACLE INERTE'); }
        mkdir($this->root.'/dossier-inconnu');file_put_contents($this->root.'/dossier-inconnu/aleatoire.php','SENTINELLE INERTE');
        $c=$this->client(true);$g=$c->get('/update.php');
        self::assertStringNotContainsString('dossier-inconnu',$g->corps);
        $r=$c->post('/update.php',['url_site'=>$this->serveur->url(),'action'=>'demarrer','login'=>'admin','mot_de_passe'=>SourceExpoActes::MOT_DE_PASSE,'jeton'=>$this->jeton($g->corps)]);
        for ($n=0;$n<100 && str_contains($r->corps,'name="action" value="continuer"');$n++) {
            self::assertSame(200,$r->statut,$r->corps);
            $r=$c->post('/update.php',['url_site'=>$this->serveur->url(),'action'=>'continuer','jeton'=>$this->jeton($r->corps)]);
        }
        self::assertLessThan(100,$n);self::assertSame(200,$r->statut,$r->corps);self::assertStringContainsString('à jour',$r->corps);
        self::assertStringContainsString('dossier-inconnu',$r->corps);
        self::assertSame('SENTINELLE INERTE',file_get_contents($this->root.'/dossier-inconnu/aleatoire.php'));
        self::assertSame(' 3.2.4 ',$this->actes->query("SELECT valeur FROM {$this->prefixe}_params WHERE param='EA_VERSION'")->fetch_row()[0]);
        self::assertSame('InnoDB',$this->comptes->query("SHOW TABLE STATUS WHERE Name='{$this->prefixe}u_user3'")->fetch_assoc()['Engine']);
        self::assertStringStartsWith("# personnalisation de test conservee\n",file_get_contents($this->root.'/.htaccess'));
        if ($obstacle) {
            self::assertFileExists($this->root.'/_logs/temoin');self::assertFileExists($this->root.'/_backup/temoin');
            self::assertSame('OBSTACLE INERTE',file_get_contents($this->root.'/tmp'));
        } else {
            $archives=glob($this->root.'/tmp/archive/*/logs/temoin');self::assertCount(1,$archives);
            self::assertSame($inode,fileinode($archives[0]));self::assertCount(1,glob($this->root.'/tmp/archive/*/backups/temoin'));
            self::assertDirectoryDoesNotExist($this->root.'/_logs');self::assertDirectoryDoesNotExist($this->root.'/_backup');
        }
    }
}
