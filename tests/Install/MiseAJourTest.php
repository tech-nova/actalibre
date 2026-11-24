<?php
declare(strict_types=1);
namespace ActaLibre\Tests\Install;
use ActaLibre\Install\{Avancement,SessionMiseAJour,Verrou};
use ActaLibre\Compte\MotDePasse;
use PHPUnit\Framework\Attributes\{DataProvider,RunTestsInSeparateProcesses};
#[RunTestsInSeparateProcesses]
class MiseAJourTest extends BaseMiseAJour
{
    public function testLaMigrationCompleteConserveLesDonneesEtLesComptes(): void
    {
        $this->base->query("UPDATE {$this->prefixe}_params SET valeur='1' WHERE param='EA_MAINTENANCE'");
        $acte=$this->base->query("SELECT * FROM {$this->prefixe}_nai3")->fetch_all();
        $configuration=file_get_contents($this->racine.'/_config/connect.inc.php');
        $this->terminer();
        self::assertSame('1',$this->valeur('EA_MAINTENANCE'),'Le choix de maintenance de l’administrateur est conservé');
        self::assertSame($acte,$this->base->query("SELECT * FROM {$this->prefixe}_nai3")->fetch_all());
        self::assertSame(' 3.2.4 ',$this->valeur('EA_VERSION'));self::assertNull($this->valeur('ACTALIBRE_MIGRATION'));
        self::assertSame('1.0.0',$this->valeur('ACTALIBRE_SCHEMA'));self::assertSame('1.1.3',$this->valeur('ACTALIBRE_VERSION'));
        self::assertSame($configuration,file_get_contents($this->racine.'/_config/connect.inc.php'));
        $hash=$this->comptes->query("SELECT hashpass FROM {$this->prefixeComptes}_user3 WHERE ID=1")->fetch_row()[0];
        self::assertTrue(MotDePasse::verifier(SourceExpoActes::MOT_DE_PASSE,$hash));
        self::assertSame(2,(int)$this->comptes->query("SELECT COUNT(*) FROM {$this->prefixeComptes}_user3")->fetch_row()[0]);
    }
    public static function interruptions(): iterable
    { foreach (['capacite:mar3','mots-de-passe','parametres','schema','produit'] as $e) yield $e=>[$e]; }
    #[DataProvider('interruptions')]
    public function testUneInterruptionApresEffetSeReprend(string $etape): void
    {
        $interrompu=false;
        for ($i=0;$i<100;$i++) {
            try { $this->lancer($i===0?'demarrer':'continuer',static function(string $e) use($etape): void { if ($e===$etape) throw new \RuntimeException('arrêt contrôlé'); }); }
            catch (\RuntimeException $e) { self::assertSame(\RuntimeException::class,$e::class);self::assertSame('arrêt contrôlé',$e->getMessage());$interrompu=true;break; }
        }
        self::assertTrue($interrompu);
        self::assertSame(' 3.2.4 ',$this->valeur('EA_VERSION'));
        if ($etape==='produit') { self::assertTrue($this->etat()->accessible);self::assertSame('nettoyer',$this->etat()->action); }
        else self::assertNull($this->valeur('ACTALIBRE_VERSION'));
        $this->identifier();$this->terminer();self::assertTrue($this->etat()->accessible);
    }
    public function testUneVersionRefuseeNeModifieAucunPerimetre(): void
    {
        $this->base->query("UPDATE {$this->prefixe}_params SET valeur='4.0.0' WHERE param='EA_VERSION'");
        $avant=[$this->empreinte(),$this->instantaneComptes()];
        try { $this->lancer('demarrer');self::fail('4.0.0 admis'); }
        catch (\RuntimeException $e) { self::assertSame(\RuntimeException::class,$e::class);self::assertSame($avant,[$this->empreinte(),$this->instantaneComptes()]); }
    }
    public function testLeSecondVerrouOuLePremierRefuseTouteMutation(): void
    {
        $a=$this->c->configuration->comptes??$this->c->configuration->actes;$autre=$a->ouvrir();
        $v=Verrou::prendre($autre,$a->base,$a->prefixe,'migration');
        $avant=[$this->empreinte(),$this->instantaneComptes()];
        try {
            try { $this->lancer('demarrer');self::fail('Verrou contourné'); }
            catch (\RuntimeException $e) { self::assertSame(\RuntimeException::class,$e::class);self::assertStringContainsString('migration en cours',$e->getMessage()); }
            self::assertSame($avant,[$this->empreinte(),$this->instantaneComptes()]);
            $v->liberer();
            $premier=$this->c->configuration->actes;$probe=$premier->ouvrir();
            try { $libre=Verrou::prendre($probe,$premier->base,$premier->prefixe,'migration');$libre->liberer(); }
            finally { $probe->close(); }
        } finally { $v->liberer();$autre->close(); }
    }
    public function testLeProduitSeulChangeUneSeuleLigne(): void
    {
        $this->marqueur('ACTALIBRE_SCHEMA','1.0.0');$this->marqueur('ACTALIBRE_VERSION','0.9.0');$this->identifier();
        $avant=$this->base->query("SELECT * FROM {$this->prefixe}_params ORDER BY param")->fetch_all(MYSQLI_ASSOC);$comptes=$this->instantaneComptes();$complet=$this->empreinte();
        $this->lancer('produit');
        $apres=$this->base->query("SELECT * FROM {$this->prefixe}_params ORDER BY param")->fetch_all(MYSQLI_ASSOC);
        foreach ($avant as &$p) if ($p['param']==='ACTALIBRE_VERSION') $p['valeur']='1.1.3';unset($p);
        self::assertSame($avant,$apres);self::assertSame($comptes,$this->instantaneComptes());self::assertNull($this->valeur('ACTALIBRE_MIGRATION'));
        $this->base->query("UPDATE {$this->prefixe}_params SET valeur='0.9.0' WHERE param='ACTALIBRE_VERSION'");
        self::assertSame($complet,$this->empreinte(),'Le retrait de la seule différence autorisée restitue toute la base');
    }
    public function testLeProduitSeulCompleteLesModelesCsv(): void
    {
        $this->marqueur('ACTALIBRE_SCHEMA','1.0.0');$this->marqueur('ACTALIBRE_VERSION','0.9.0');
        mkdir($this->racine.'/admin/depot');file_put_contents($this->racine.'/admin/depot/N-NimegueV3.m_csv','personnel');
        $this->marqueur('UPLOAD_DIR','depot');$this->identifier();
        $r=$this->lancer('produit');
        self::assertSame([],$r['avis']);
        self::assertCount(8,glob($this->racine.'/admin/depot/*.m_csv'));
        self::assertSame('personnel',file_get_contents($this->racine.'/admin/depot/N-NimegueV3.m_csv'));
    }
    public function testUnDepotInvalideNEmpechePasLaMiseAJourDuProduit(): void
    {
        $this->marqueur('ACTALIBRE_SCHEMA','1.0.0');$this->marqueur('ACTALIBRE_VERSION','0.9.0');
        $this->marqueur('UPLOAD_DIR','../dehors');$this->identifier();
        $r=$this->lancer('produit');
        self::assertTrue($r['terminee']);self::assertCount(1,$r['avis']);
        self::assertSame('1.1.3',$this->valeur('ACTALIBRE_VERSION'));
    }
    public function testLaMigrationFournitLesModelesCsv(): void
    {
        $this->terminer();
        self::assertCount(8,glob($this->racine.'/admin/_upload/*.m_csv'));
    }
    public function testUnNettoyageNeffacePasUneCibleChangee(): void
    {
        $this->marqueur('ACTALIBRE_SCHEMA','1.0.0');$this->marqueur('ACTALIBRE_VERSION','1.1.3');
        $a=Avancement::commencer($this->etat()->site->livraison);$this->marqueur('ACTALIBRE_MIGRATION',$a->encoder());$this->identifier();
        $this->marqueur('ACTALIBRE_MIGRATION',$a->suivant('verification',null)->encoder());$avant=$this->empreinte();
        try { $this->lancer('nettoyer');self::fail('Reste changé effacé'); }
        catch (\RuntimeException $e) { self::assertSame(\RuntimeException::class,$e::class);self::assertSame($avant,$this->empreinte()); }
    }
    public function testUneAutorisationDUnAutreSiteNEstPasReutilisable(): void
    {
        $a=$this->c->configuration->actes;
        $configuration=new \ActaLibre\Install\ConfigurationSite($this->racine.'/autre/_config/connect.inc.php',$a,$this->c->configuration->comptes);
        $autre=new \ActaLibre\Install\ConnexionsSite($configuration,$this->base,$this->comptes);
        $avant=[$this->empreinte(),$this->instantaneComptes()];
        try { (new \ActaLibre\Install\MiseAJour($this->racine))->executer($autre,$this->autorisation,'demarrer','https://exemple.test');self::fail('Autre site accepté'); }
        catch (\RuntimeException $e) { self::assertSame(\RuntimeException::class,$e::class);self::assertSame($avant,[$this->empreinte(),$this->instantaneComptes()]); }
    }
    public function testUnAvancementIllisibleNestJamaisEfface(): void
    {
        $this->lancer('demarrer');$this->marqueur('ACTALIBRE_MIGRATION','{');$avant=$this->empreinte();
        try { $this->lancer();self::fail('Progression réinitialisée'); }
        catch (\RuntimeException $e) { self::assertSame(\RuntimeException::class,$e::class);self::assertSame($avant,$this->empreinte()); }
    }
    public static function erreursSql(): iterable { yield 'false'=>[false];yield 'exception'=>[true]; }
    #[DataProvider('erreursSql')]
    public function testUneErreurSqlNeDeclarePasLeProduitFinal(bool $exception): void
    {
        for ($i=0;$i<100;$i++) {
            $this->lancer($i===0?'demarrer':'continuer');
            if (Avancement::decoder($this->valeur('ACTALIBRE_MIGRATION'))->etape==='produit') break;
        }
        self::assertLessThan(100,$i);
        $original=$this->c;$a=$original->configuration->actes;
        $lien=new ConnexionEchecMiseAJour($a->serveur,$a->utilisateur,$a->motDePasse,$a->base);$lien->set_charset('utf8mb4');
        $this->c=new \ActaLibre\Install\ConnexionsSite($original->configuration,$lien,$this->deuxBases()?$this->comptes:$lien);
        try {
            $avant=[$this->empreinte(),$this->instantaneComptes()];$lien->exception=$exception;$lien->refuser=true;
            try { $this->lancer();self::fail('Erreur SQL acceptée'); }
            catch (\RuntimeException $e) { self::assertSame(\RuntimeException::class,$e::class);self::assertStringNotContainsString('sql-secret-temoin',$e->getMessage()); }
            self::assertSame($avant,[$this->empreinte(),$this->instantaneComptes()]);
            self::assertNull($this->valeur('ACTALIBRE_VERSION'));self::assertSame('1.0.0',$this->valeur('ACTALIBRE_SCHEMA'));
            $lien->refuser=false;$this->terminer();
        } finally { $this->c=$original;$lien->close(); }
    }
    public function testUnProcessusInterrompuLibereSesVerrousEtLeRejeuReprend(): void
    {
        $processus=proc_open([PHP_BINARY,dirname(__DIR__).'/fixtures/mise_a_jour_processus.php',$this->racine,session_id()],
            [0=>['pipe','r'],1=>['file',$this->racine.'/processus.out','w'],2=>['file',$this->racine.'/processus.err','w']],$pipes);
        self::assertIsResource($processus);fclose($pipes[0]);
        try {
            $limite=microtime(true)+10;
            do { clearstatcache();if (is_file($this->racine.'/verrou-pris')) break;usleep(10000); } while (microtime(true)<$limite && proc_get_status($processus)['running']);
            self::assertFileExists($this->racine.'/verrou-pris',(string)file_get_contents($this->racine.'/processus.err'));
            $avant=$this->empreinte();
            try { $this->lancer();self::fail('Deux lots exécutés en parallèle'); }
            catch (\RuntimeException $e) { self::assertSame(\RuntimeException::class,$e::class);self::assertStringContainsString('migration en cours',$e->getMessage()); }
            self::assertSame($avant,$this->empreinte());
        } finally { proc_terminate($processus);proc_close($processus); }
        $this->attendreVerrousLibres();
        self::assertSame('capacite:mar3',Avancement::decoder($this->valeur('ACTALIBRE_MIGRATION'))->etape);
        $this->terminer();self::assertTrue($this->etat()->accessible);
    }
    /** MySQL ne rend les verrous d'un processus tué qu'après avoir constaté la fin de ses connexions. */
    private function attendreVerrousLibres(): void
    {
        $limite=microtime(true)+10;
        foreach (array_filter([$this->c->configuration->actes,$this->c->configuration->comptes]) as $a) {
            $lien=$a->ouvrir();
            try {
                while (true) {
                    try { Verrou::prendre($lien,$a->base,$a->prefixe,'migration')->liberer();break; }
                    catch (\RuntimeException $e) {
                        if ($e->getMessage()!=='migration en cours' || microtime(true)>=$limite) throw $e;
                        usleep(20000);
                    }
                }
            } finally { $lien->close(); }
        }
    }
    public function testUneRetrogradationInterditLeLotSuivant(): void
    {
        $this->lancer('demarrer');$this->comptes->query("UPDATE {$this->prefixeComptes}_user3 SET level=8 WHERE ID=1");
        $avant=[$this->empreinte(),$this->instantaneComptes()];
        try { $this->lancer();self::fail('Compte rétrogradé admis'); }
        catch (\RuntimeException $e) { self::assertSame(\RuntimeException::class,$e::class);self::assertSame($avant,[$this->empreinte(),$this->instantaneComptes()]); }
    }
}
