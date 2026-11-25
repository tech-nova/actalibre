<?php
declare(strict_types=1);
namespace ActaLibre\Tests\Install;
use ActaLibre\Install\{AccesMysql,FichierConnexion};
use ActaLibre\Tests\GoldenMaster\{Client,Reponse};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class MiseAJourParcoursTest extends TestCase
{
    private SiteInstallation $site;
    private ?\mysqli $comptesDistincts=null;
    protected function setUp(): void
    {
        foreach (['HOST','NAME','USER','PASS'] as $cle) if (getenv('GM_DB_'.$cle)===false) self::markTestSkipped('Configurer GM_DB_*');
        $this->site=new SiteInstallation(dirname(__DIR__,2),['hote'=>(string)getenv('GM_DB_HOST'),'nom'=>(string)getenv('GM_DB_NAME'),'utilisateur'=>(string)getenv('GM_DB_USER'),'passe'=>(string)getenv('GM_DB_PASS')]);
    }
    protected function tearDown(): void
    {
        if ($this->comptesDistincts!==null) { SourceExpoActes::supprimer($this->comptesDistincts,$this->site->prefixe.'u');$this->comptesDistincts->close(); }
        if (isset($this->site)) $this->site->arreter();
    }
    private function preparer(bool $sousDossier=false,array $options=[],bool $deuxBases=false): void
    {
        $this->site->demarrer($sousDossier,$options);
        mkdir($this->site->racine.'/_config');
        $a=new AccesMysql((string)getenv('GM_DB_HOST'),(string)getenv('GM_DB_NAME'),(string)getenv('GM_DB_USER'),(string)getenv('GM_DB_PASS'),$this->site->prefixe);
        $u=$deuxBases?new AccesMysql($a->serveur,(string)getenv('GM_DB_NAME_COMPTES'),$a->utilisateur,$a->motDePasse,$this->site->prefixe.'u'):null;
        $this->comptesDistincts=$u?->ouvrir();
        SourceExpoActes::creer($this->site->base,$this->site->prefixe,$this->comptesDistincts??$this->site->base,($u??$a)->prefixe);
        file_put_contents($this->site->racine.'/_config/connect.inc.php',FichierConnexion::contenu($a,$u));
    }
    private function jeton(Reponse $r): string
    { self::assertSame(1,preg_match('/name="jeton" value="([0-9a-f]{64})"/',$r->corps,$m),$r->corps);return $m[1]; }
    public static function compression(): iterable { yield 'sans compression'=>[false];yield 'gzip'=>[true]; }
    #[DataProvider('compression')]
    public function testLaMigrationAfficheLAttenteEtPermetDeContinuer(bool $gzip): void
    {
        $this->preparer(true,$gzip?['output_buffering=4096','zlib.output_compression=1']:[]);$c=$this->site->client();$accueil=$c->get('/update.php');$j=$this->jeton($accueil);
        self::assertStringContainsString('<div id="attente" class="attente" role="status"',$accueil->corps);self::assertStringContainsString('Adresse complète du site',$accueil->corps);
        self::assertStringContainsString('<p class="message" role="status">',$accueil->corps);self::assertStringNotContainsString('role="alert"',$accueil->corps);
        $r=$c->post('/update.php',['url_site'=>$this->site->url().'/', 'action'=>'demarrer','jeton'=>$j,'login'=>'admin','mot_de_passe'=>SourceExpoActes::MOT_DE_PASSE]);
        for ($i=0;$i<100 && str_contains($r->corps,'name="action" value="continuer"');$i++) {
            self::assertSame(200,$r->statut,$r->corps);self::assertStringContainsString('method="post"',$r->corps);self::assertStringContainsString('Continuer',$r->corps);self::assertStringContainsString('<div id="attente" class="attente active" role="status"',$r->corps);self::assertStringNotContainsString('Adresse complète du site',$r->corps);
            $r=$c->post('/update.php',['url_site'=>$this->site->url().'/', 'action'=>'continuer','jeton'=>$this->jeton($r)]);
        }
        self::assertLessThan(100,$i);self::assertSame(200,$r->statut,$r->corps);self::assertStringContainsString('à jour',$r->corps);
        self::assertSame('',$this->site->journal());
        self::assertSame('0',$this->site->base->query("SELECT COUNT(*) FROM {$this->site->prefixe}_session")->fetch_row()[0]);
        if ($gzip) {
            self::assertStringContainsStringIgnoringCase('Content-Encoding: gzip',$r->entetes);
            $connexion=$c->post('/admin/index.php',['login'=>'admin','passwd'=>SourceExpoActes::MOT_DE_PASSE]);self::assertSame(200,$connexion->statut,$connexion->corps);
            $backup=$c->get('/admin/backup.php');self::assertSame(200,$backup->statut,$backup->corps);self::assertStringContainsString('</html>',$backup->corps);
        }
    }
    public function testUnFichierAbsentRedirigeEtUnFichierVideNeReinstallePas(): void
    {
        $this->site->demarrer();$c=$this->site->client();$r=$c->get('/update.php');self::assertSame(303,$r->statut);self::assertStringContainsString('Location: install.php',$r->entetes);
        mkdir($this->site->racine.'/_config');file_put_contents($this->site->racine.'/_config/connect.inc.php','');
        $r=$c->get('/update.php');self::assertSame(503,$r->statut);self::assertSame('',file_get_contents($this->site->racine.'/_config/connect.inc.php'));
        self::assertStringNotContainsString('Location:',$r->entetes);
    }
    public function testLaMigrationHttpUtiliseLaSecondeBasePhysique(): void
    {
        $this->preparer(deuxBases:true);$c=$this->site->client();$r=$c->post('/update.php',['url_site'=>$this->site->url().'/', 'action'=>'demarrer','jeton'=>$this->jeton($c->get('/update.php')),'login'=>'admin','mot_de_passe'=>SourceExpoActes::MOT_DE_PASSE]);
        for ($i=0;$i<100 && str_contains($r->corps,'name="action" value="continuer"');$i++) $r=$c->post('/update.php',['url_site'=>$this->site->url().'/', 'action'=>'continuer','jeton'=>$this->jeton($r)]);
        self::assertLessThan(100,$i);self::assertSame(200,$r->statut,$r->corps);self::assertStringContainsString('à jour',$r->corps);
        $p=$this->site->prefixe;
        self::assertSame('0',$this->site->base->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='{$p}_user3'")->fetch_row()[0]);
        self::assertSame('InnoDB',$this->comptesDistincts->query("SHOW TABLE STATUS WHERE Name='{$p}u_user3'")->fetch_assoc()['Engine']);
        self::assertSame('0',$this->comptesDistincts->query("SELECT COUNT(*) FROM {$p}u_session")->fetch_row()[0]);self::assertSame('',$this->site->journal());
    }
    public function testUnNouvelAdministrateurCreeSurPreuveFtpConduitLaMigration(): void
    {
        $this->preparer();$c=$this->site->client();$p=$this->site->prefixe;
        $accueil=$c->get('/update.php');self::assertSame(1,preg_match('/admin-[0-9a-f]{32}\.txt/',$accueil->corps,$m),$accueil->corps);
        $preuve=$this->site->racine.'/_config/'.$m[0];
        $formulaire=['url_site'=>$this->site->url().'/','action'=>'creer','login'=>'nouveau','courriel'=>'nouveau@example.org','mot_de_passe'=>'Nouveau-2026','confirmation'=>'Nouveau-2026'];
        $r=$c->post('/update.php',$formulaire+['jeton'=>$this->jeton($accueil)]);
        self::assertSame(400,$r->statut);self::assertStringContainsString('preuve',$r->corps);
        self::assertSame('0',$this->site->base->query("SELECT COUNT(*) FROM {$p}_user3 WHERE login='nouveau'")->fetch_row()[0]);
        touch($preuve);
        $r=$c->post('/update.php',array_replace($formulaire,['url_site'=>$this->site->url().'/?lang=fr'])+['jeton'=>$this->jeton($c->get('/update.php'))]);
        self::assertSame(400,$r->statut);self::assertFileExists($preuve,'Une adresse refusée ne consomme pas la preuve.');
        self::assertSame('0',$this->site->base->query("SELECT COUNT(*) FROM {$p}_user3 WHERE login='nouveau'")->fetch_row()[0]);
        $r=$c->post('/update.php',array_replace($formulaire,['login'=>'admin'])+['jeton'=>$this->jeton($c->get('/update.php'))]);
        self::assertSame(400,$r->statut);self::assertStringContainsString('existe déjà',$r->corps);
        $r=$c->post('/update.php',$formulaire+['jeton'=>$this->jeton($c->get('/update.php'))]);
        self::assertFileDoesNotExist($preuve);
        for ($i=0;$i<100 && str_contains($r->corps,'name="action" value="continuer"');$i++) $r=$c->post('/update.php',['url_site'=>$this->site->url().'/','action'=>'continuer','jeton'=>$this->jeton($r)]);
        self::assertLessThan(100,$i);self::assertSame(200,$r->statut,$r->corps);self::assertStringContainsString('à jour',$r->corps);
        $compte=$this->site->base->query("SELECT level,hashpass FROM {$p}_user3 WHERE login='nouveau'")->fetch_assoc();
        self::assertSame('9',(string)$compte['level']);
        self::assertTrue(\ActaLibre\Compte\MotDePasse::verifier('Nouveau-2026',$compte['hashpass']));
        self::assertFalse(\ActaLibre\Compte\MotDePasse::estAncienFormat($compte['hashpass']));
        self::assertSame('',$this->site->journal());
    }
    public function testUnRefusRouvreLeFormulaireDeCreationAvecSaSaisie(): void
    {
        $this->preparer();$c=$this->site->client();$accueil=$c->get('/update.php');
        self::assertStringContainsString('<details><summary>',$accueil->corps);
        self::assertSame(1,preg_match('/admin-[0-9a-f]{32}\.txt/',$accueil->corps,$m));
        $adresse=$this->site->url().'/?lang=fr';
        $formulaire=['url_site'=>$this->site->url().'/','action'=>'creer','login'=>'nou"veau','courriel'=>'nouveau@example.org','mot_de_passe'=>'Nouveau-2026','confirmation'=>'Autre-2026'];
        // Preuve absente, adresse refusée, puis confirmation différente.
        foreach ([[[],'Fichier de preuve introuvable',false],[['url_site'=>$adresse],'',true],[[],'Les mots de passe doivent être identiques.',true]] as [$ecart,$erreur,$deposee]) {
            if ($deposee) touch($this->site->racine.'/_config/'.$m[0]);
            $r=$c->post('/update.php',array_replace($formulaire,$ecart)+['jeton'=>$this->jeton($accueil)]);
            self::assertSame(400,$r->statut);
            self::assertSame(1,preg_match('~<details open>.*?</details>~s',$r->corps,$d),$r->corps);
            self::assertStringContainsString('<p class="erreur" role="alert">',$d[0]);self::assertStringContainsString($erreur,$d[0]);
            self::assertSame(1,substr_count($r->corps,'role="alert"'));
            self::assertStringContainsString('name="login" type="text" value="nou&quot;veau"',$d[0]);
            self::assertStringContainsString('value="nouveau@example.org"',$d[0]);
            self::assertStringContainsString('value="'.($ecart['url_site']??$formulaire['url_site']).'"',$d[0]);
            self::assertStringContainsString($m[0],$d[0],'Le nom de la preuve ne change pas.');
            self::assertStringNotContainsString('Nouveau-2026',$r->corps);self::assertStringNotContainsString('Autre-2026',$r->corps);
        }
        self::assertSame('0',$this->site->base->query("SELECT COUNT(*) FROM {$this->site->prefixe}_user3 WHERE login='nou\"veau'")->fetch_row()[0]);
        // Un échec du premier formulaire n'ouvre pas la création.
        $r=$c->post('/update.php',['url_site'=>$this->site->url().'/','action'=>'demarrer','jeton'=>$this->jeton($accueil),'login'=>'admin','mot_de_passe'=>'faux-2026']);
        self::assertSame(400,$r->statut);self::assertStringNotContainsString('<details open>',$r->corps);
        self::assertStringContainsString('<p class="message erreur" role="alert">Identifiants administrateur invalides.</p>',$r->corps);
        self::assertSame(1,substr_count($r->corps,'role="alert"'));
        self::assertSame('',$this->site->journal());
    }
    public function testUnEchecApresCreationInviteASeConnecterAvecLeCompteCree(): void
    {
        $this->preparer();$c=$this->site->client();$p=$this->site->prefixe;$accueil=$c->get('/update.php');
        self::assertSame(1,preg_match('/admin-[0-9a-f]{32}\.txt/',$accueil->corps,$m));touch($this->site->racine.'/_config/'.$m[0]);
        $this->site->base->query("ALTER TABLE {$p}_mar3 MODIFY LADATE DATETIME");
        $r=$c->post('/update.php',['url_site'=>$this->site->url().'/','action'=>'creer','jeton'=>$this->jeton($accueil),'login'=>'nouveau','courriel'=>'nouveau@example.org','mot_de_passe'=>'Nouveau-2026','confirmation'=>'Nouveau-2026']);
        self::assertSame(400,$r->statut);
        self::assertStringContainsString('Structure de dates non prise en charge.',$r->corps);
        self::assertStringContainsString('Le compte administrateur « nouveau » a été créé',$r->corps);
        self::assertStringContainsString('id="login" name="login" autocomplete="username" value="nouveau"',$r->corps);
        self::assertStringNotContainsString('<details',$r->corps,'La preuve est consommée : aucune seconde création proposée.');
        self::assertStringNotContainsString('Nouveau-2026',$r->corps);
        self::assertSame('1',$this->site->base->query("SELECT COUNT(*) FROM {$p}_user3 WHERE login='nouveau' AND level=9")->fetch_row()[0]);
        $this->site->base->query("ALTER TABLE {$p}_mar3 MODIFY LADATE DATE");
        $r=$c->post('/update.php',['url_site'=>$this->site->url().'/','action'=>'demarrer','jeton'=>$this->jeton($r),'login'=>'nouveau','mot_de_passe'=>'Nouveau-2026']);
        for ($i=0;$i<100 && str_contains($r->corps,'name="action" value="continuer"');$i++) $r=$c->post('/update.php',['url_site'=>$this->site->url().'/','action'=>'continuer','jeton'=>$this->jeton($r)]);
        self::assertLessThan(100,$i);self::assertStringContainsString('à jour',$r->corps);self::assertSame('',$this->site->journal());
    }
    /** Site ActaLibre complet, laissé devant l'action demandée ; $distincts : comptes dans la seconde base. */
    private function preparerActaLibre(string $action,bool $distincts=false): void
    {
        $this->preparer(deuxBases:$distincts);$c=$this->site->client();
        $r=$c->post('/update.php',['url_site'=>$this->site->url().'/','action'=>'demarrer','jeton'=>$this->jeton($c->get('/update.php')),'login'=>'admin','mot_de_passe'=>SourceExpoActes::MOT_DE_PASSE]);
        for ($i=0;$i<100 && str_contains($r->corps,'name="action" value="continuer"');$i++) $r=$c->post('/update.php',['url_site'=>$this->site->url().'/','action'=>'continuer','jeton'=>$this->jeton($r)]);
        self::assertStringContainsString('à jour',$r->corps);$p=$this->site->prefixe;
        if ($action==='produit') $this->site->base->query("UPDATE {$p}_params SET valeur='0.9.0' WHERE param='ACTALIBRE_VERSION'");
        else $this->site->base->query("INSERT INTO {$p}_params (param,groupe,ordre,type,valeur,listval,libelle,aide) VALUES ('ACTALIBRE_INSTALLATION','Hidden',100,'C','reste','','','')");
    }
    /** @return array{string,string} jeton et chemin de la preuve */
    private function secours(Client $c): array
    {
        $r=$c->get('/update.php');self::assertSame(200,$r->statut,$r->corps);
        self::assertStringContainsString('Identifiants perdus : créer un nouvel administrateur',$r->corps);
        self::assertSame(1,preg_match('/admin-[0-9a-f]{32}\.txt/',$r->corps,$m),$r->corps);
        return [$this->jeton($r),$this->site->racine.'/_config/'.$m[0]];
    }
    public static function secoursActaLibre(): iterable
    { yield 'produit'=>['produit',false];yield 'nettoyer'=>['nettoyer',false];yield 'produit, comptes distincts'=>['produit',true]; }
    #[DataProvider('secoursActaLibre')]
    public function testLaPreuveFtpDebloqueUnSiteActaLibre(string $action,bool $distincts): void
    {
        $this->preparerActaLibre($action,$distincts);$c=$this->site->client();$p=$this->site->prefixe;
        $comptes=$this->comptesDistincts??$this->site->base;$u=$distincts?$p.'u':$p;
        $avant=$comptes->query("SELECT * FROM {$u}_user3 ORDER BY ID")->fetch_all(MYSQLI_ASSOC);
        [$jeton,$preuve]=$this->secours($c);
        $f=['url_site'=>$this->site->url().'/','action'=>'creer','jeton'=>$jeton,'login'=>'nouveau','courriel'=>'nouveau@example.org','mot_de_passe'=>'Nouveau-2026','confirmation'=>'Nouveau-2026'];
        $r=$c->post('/update.php',$f);self::assertSame(400,$r->statut);self::assertStringContainsString('Fichier de preuve introuvable',$r->corps);
        // La preuve d'un autre navigateur ne vaut pas pour celui-ci.
        [,$autre]=$this->secours($this->site->deuxiemeClient());touch($autre);
        self::assertSame(400,$c->post('/update.php',$f)->statut);
        touch($preuve);
        foreach ([['login'=>'admin'],['login'=>'ADMIN'],['confirmation'=>'Autre-2026'],['mot_de_passe'=>'court','confirmation'=>'court']] as $ecart) {
            $r=$c->post('/update.php',array_replace($f,$ecart));
            self::assertSame(400,$r->statut);self::assertStringContainsString('<details open>',$r->corps);self::assertFileExists($preuve);
        }
        self::assertSame($avant,$comptes->query("SELECT * FROM {$u}_user3 ORDER BY ID")->fetch_all(MYSQLI_ASSOC),'Aucun refus ne touche aux comptes.');
        // Trop d'échecs depuis cette adresse : la preuve FTP reste une issue.
        $limiteur=new \ActaLibre\Compte\Limiteur($comptes,$u.'_essai');
        for ($i=0;$i<\ActaLibre\Compte\Limiteur::MAX_ADRESSE;$i++) $limiteur->noterEchec('inconnu','127.0.0.1');
        $r=$c->post('/update.php',['url_site'=>$this->site->url().'/','action'=>$action,'jeton'=>$jeton,'login'=>'admin','mot_de_passe'=>SourceExpoActes::MOT_DE_PASSE]);
        self::assertStringContainsString('Trop de tentatives',$r->corps);
        $r=$c->post('/update.php',$f);
        self::assertSame(200,$r->statut,$r->corps);self::assertStringContainsString('à jour',$r->corps);
        self::assertFileDoesNotExist($preuve);self::assertFileExists($autre);
        $compte=$comptes->query("SELECT level,hashpass FROM {$u}_user3 WHERE login='nouveau'")->fetch_assoc();
        self::assertSame('9',(string)$compte['level']);self::assertFalse(\ActaLibre\Compte\MotDePasse::estAncienFormat($compte['hashpass']));
        self::assertTrue(\ActaLibre\Compte\MotDePasse::verifier('Nouveau-2026',$compte['hashpass']));
        self::assertSame($avant,$comptes->query("SELECT * FROM {$u}_user3 WHERE login<>'nouveau' ORDER BY ID")->fetch_all(MYSQLI_ASSOC));
        self::assertSame('0',$this->site->base->query("SELECT COUNT(*) FROM {$p}_params WHERE param='ACTALIBRE_INSTALLATION' OR (param='ACTALIBRE_VERSION' AND valeur='0.9.0')")->fetch_row()[0]);
        // Le site à jour ne propose plus aucune création.
        $r=$c->get('/update.php');self::assertStringNotContainsString('<details',$r->corps);
        $c->post('/update.php',array_replace($f,['login'=>'second']));
        self::assertSame('0',$comptes->query("SELECT COUNT(*) FROM {$u}_user3 WHERE login='second'")->fetch_row()[0]);
        self::assertSame('',$this->site->journal());
    }
}
