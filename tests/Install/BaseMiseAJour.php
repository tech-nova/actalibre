<?php
declare(strict_types=1);
namespace ActaLibre\Tests\Install;
use ActaLibre\Install\{AccesMysql,Avancement,ConfigurationSite,ConnexionsSite,EtatExploitation,FichierConnexion,MiseAJour,SessionMiseAJour};

abstract class BaseMiseAJour extends BaseInstallation
{
    protected ConnexionsSite $c;
    protected \mysqli $comptes;
    protected string $prefixeComptes;
    protected string $perimetre;
    protected array $autorisation;
    protected function deuxBases(): bool { return false; }
    protected function setUp(): void
    {
        parent::setUp();
        $a=new AccesMysql((string)getenv('GM_DB_HOST'),(string)getenv('GM_DB_NAME'),(string)getenv('GM_DB_USER'),(string)getenv('GM_DB_PASS'),$this->prefixe);
        $u=$this->deuxBases()?new AccesMysql($a->serveur,(string)getenv('GM_DB_NAME_COMPTES'),$a->utilisateur,$a->motDePasse,$this->prefixe.'u'):null;
        $this->comptes=$u?->ouvrir()??$this->base;$this->prefixeComptes=($u??$a)->prefixe;
        $this->sources();mkdir($this->racine.'/install');mkdir($this->racine.'/_config');mkdir($this->racine.'/sessions');
        foreach (['creetables.sql','act_params.xml','liste_prenoms.csv'] as $f) copy(dirname(__DIR__,2).'/install/'.$f,$this->racine.'/install/'.$f);
        copy(dirname(__DIR__,2).'/composer.json',$this->racine.'/composer.json');
        file_put_contents($this->racine.'/_config/connect.inc.php',FichierConnexion::contenu($a,$u));chmod($this->racine.'/_config/connect.inc.php',0600);
        $conf=new ConfigurationSite($this->racine.'/_config/connect.inc.php',$a,$u);
        $this->c=new ConnexionsSite($conf,$this->base,$this->comptes);
        SourceExpoActes::creer($this->base,$this->prefixe,$this->comptes,$this->prefixeComptes);
        $this->perimetre=$conf->perimetre();
        session_save_path($this->racine.'/sessions');
        $this->identifier();
    }
    protected function identifier(): void
    {
        if (session_status()!==PHP_SESSION_ACTIVE) SessionMiseAJour::ouvrir('/',false);
        $this->autorisation=SessionMiseAJour::authentifier($this->comptes,$this->prefixeComptes,'admin',SourceExpoActes::MOT_DE_PASSE,$this->perimetre);
        SessionMiseAJour::lierProcedure($this->etat());SessionMiseAJour::fermer();
    }
    protected function etat(): EtatExploitation { return EtatExploitation::lire($this->c,$this->racine.'/composer.json'); }
    protected function lancer(string $action='continuer',?\Closure $effet=null): array
    { return (new MiseAJour($this->racine,$effet))->executer($this->c,$this->autorisation,$action,'https://exemple.test'); }
    protected function terminer(): void
    {
        for ($i=0;$i<100;$i++) {
            $e=$this->etat();
            if ($e->accessible && $e->restes===[]) { self::assertSame('aucune',$e->action); return; }
            $action=match($e->action) { 'expoactes'=>'demarrer','reprendre'=>'continuer','nettoyer'=>'nettoyer','produit'=>'produit',default=>throw new \LogicException($e->diagnostic) };
            $this->lancer($action);
        }
        self::fail('Migration sans borne');
    }
    protected function marqueur(string $nom,string $valeur): void
    {
        $q=$this->base->prepare("INSERT INTO {$this->prefixe}_params (param,valeur,aide) VALUES (?,?,'') ON DUPLICATE KEY UPDATE valeur=VALUES(valeur)");$q->execute([$nom,$valeur]);
    }
    protected function valeur(string $nom): ?string
    {
        $q=$this->base->prepare("SELECT valeur FROM {$this->prefixe}_params WHERE param=?");$q->execute([$nom]);return $q->get_result()->fetch_row()[0]??null;
    }
    protected function instantaneComptes(): string
    {
        $r=[];foreach ($this->comptes->query('SHOW TABLES')->fetch_all() as [$table]) {
            if (!in_array($table,array_map(fn(string $suffixe): string=>$this->prefixeComptes.'_'.$suffixe,['user3','session','essai','reinit','acte_vu','courriel']),true)) continue;
            $l=array_map('serialize',$this->comptes->query("SELECT * FROM `$table`")->fetch_all());sort($l);
            $r[$table]=[$this->comptes->query("SHOW CREATE TABLE `$table`")->fetch_row()[1],$l];
        }
        ksort($r);return hash('sha256',serialize($r));
    }
    protected function tearDown(): void
    {
        if (session_status()===PHP_SESSION_ACTIVE) { $_SESSION=[];session_destroy(); }
        if (isset($this->comptes) && $this->comptes!==$this->base) { SourceExpoActes::supprimer($this->comptes,$this->prefixeComptes);$this->comptes->close(); }
        parent::tearDown();
    }
}
