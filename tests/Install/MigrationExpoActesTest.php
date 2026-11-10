<?php
declare(strict_types=1);
namespace ActaLibre\Tests\Install;
use ActaLibre\Install\{AccesMysql,ConfigurationSite,ConnexionsSite,MigrationExpoActes,Avancement};
use ActaLibre\Compte\MotDePasse;
use PHPUnit\Framework\Attributes\DataProvider;
final class MigrationExpoActesTest extends BaseInstallation
{
    private ConnexionsSite $c;
    protected function setUp(): void
    {
        parent::setUp();SourceExpoActes::creer($this->base,$this->prefixe,$this->base,$this->prefixe);
        $a=new AccesMysql('localhost','actalibre','actalibre','',$this->prefixe);
        $this->c=new ConnexionsSite(new ConfigurationSite($this->racine.'/_config/connect.inc.php',$a),$this->base,$this->base);
        $this->sources();mkdir($this->racine.'/install');
        foreach (['creetables.sql','act_params.xml','liste_prenoms.csv'] as $f) copy(dirname(__DIR__,2).'/install/'.$f,$this->racine.'/install/'.$f);
    }
    private function migrer(?\Closure $interruption=null): void
    {
        $m=new MigrationExpoActes($this->racine,$interruption);$m->controler($this->c);
        foreach ($m->etapes() as $e) {
            if (in_array($e,['schema','produit','nettoyage'],true)) continue;
            $position=null;$i=0;
            do {
                $r=$m->executer($this->c,$e,$position,'https://exemple.test');$position=$r['position'];
                self::assertLessThan(100,++$i,'Lot sans borne');
            } while (!$r['terminee']);
        }
        $m->verifier($this->c);
    }
    public function testLaMigrationPreserveDonneesParametresEtMotsDePasse(): void
    {
        $this->migrer();$p=$this->prefixe;
        self::assertSame('CONSERVE',$this->base->query("SELECT NOM FROM {$p}_nai3 WHERE ID=1")->fetch_row()[0]);
        self::assertSame(' 3.2.4 ',$this->parametre('EA_VERSION'));
        self::assertSame('mail-personnalise@example.org',$this->parametre('LOC_MAIL'));
        self::assertSame('https://exemple.test',$this->parametre('URL_PUBLIQUE'));
        self::assertNull($this->parametre('GOOGLE_ANA_CODE'));self::assertNull($this->parametre('ACTALIBRE_VERSION'));
        self::assertTrue(MotDePasse::verifier(SourceExpoActes::MOT_DE_PASSE,$this->base->query("SELECT hashpass FROM {$p}_user3 WHERE ID=1")->fetch_row()[0]));
        $hash=$this->base->query("SELECT hashpass FROM {$p}_user3 WHERE ID=1")->fetch_row()[0];
        $avant=$this->empreinte();$this->migrer();self::assertSame($avant,$this->empreinte());
        self::assertSame($hash,$this->base->query("SELECT hashpass FROM {$p}_user3 WHERE ID=1")->fetch_row()[0]);
        self::assertSame('InnoDB',$this->base->query("SHOW TABLE STATUS WHERE Name='{$p}_user3'")->fetch_assoc()['Engine']);
    }
    private function parametre(string $nom): ?string
    {
        $q=$this->base->prepare("SELECT valeur FROM {$this->prefixe}_params WHERE param=?");$q->execute([$nom]);return $q->get_result()->fetch_row()[0]??null;
    }
    public function testLeRejeuNeRetrecitPasUnePersonnalisation(): void
    {
        $p=$this->prefixe;$this->base->query("ALTER TABLE {$p}_mar3 MODIFY C_PRO VARCHAR(50) COLLATE latin1_general_ci DEFAULT NULL");
        $this->base->query("ALTER TABLE {$p}_user3 MODIFY hashpass VARCHAR(512) COLLATE latin1_general_ci NOT NULL DEFAULT ''");
        $this->base->query("UPDATE {$p}_metadb SET taille=50,AFFICH='9' WHERE ZID=2035");
        $this->migrer();
        self::assertSame('varchar(50)',$this->base->query("SHOW COLUMNS FROM {$p}_mar3 LIKE 'C_PRO'")->fetch_assoc()['Type']);
        self::assertSame('varchar(512)',$this->base->query("SHOW COLUMNS FROM {$p}_user3 LIKE 'hashpass'")->fetch_assoc()['Type']);
        self::assertSame(50,(int)$this->base->query("SELECT taille FROM {$p}_metadb WHERE ZID=2035")->fetch_row()[0]);
        self::assertSame('9',$this->base->query("SELECT AFFICH FROM {$p}_metadb WHERE ZID=2035")->fetch_row()[0]);
    }
    public function testUneColonneObligatoireSansDefautConserveSaDefinition(): void
    {
        $p=$this->prefixe;
        $this->base->query("ALTER TABLE {$p}_mar3 MODIFY C_PRO VARCHAR(30) COLLATE latin1_general_ci NOT NULL COMMENT 'personnalise'");
        $m=new MigrationExpoActes($this->racine);
        $m->executer($this->c,'capacite:mar3',null,'https://exemple.test');
        $d=$this->base->query("SHOW FULL COLUMNS FROM {$p}_mar3 LIKE 'C_PRO'")->fetch_assoc();
        self::assertSame('varchar(35)',$d['Type']);self::assertSame('NO',$d['Null']);
        self::assertNull($d['Default']);self::assertSame('personnalise',$d['Comment']);
        self::assertSame('latin1_general_ci',$d['Collation']);
        $ddl=$this->base->query("SHOW CREATE TABLE {$p}_mar3")->fetch_row()[1];
        self::assertSame(1,preg_match('/`C_PRO` ([^\n]+)/',$ddl,$ligne));
        self::assertStringNotContainsString('DEFAULT',$ligne[1]);
    }
    public function testLaCorrectionDeDatesSeLimiteAuxTablesReconstruites(): void
    {
        $mode=$this->base->query('SELECT @@SESSION.sql_mode')->fetch_row()[0];$p=$this->prefixe;
        $this->base->query("SET SESSION sql_mode=''");
        $this->base->query("INSERT INTO {$p}_mar3 (ID,LADATE,DTDEPOT,DTMODIF) VALUES (1,'0000-00-00','2020-00-01','2020-01-02')");
        $this->base->query("UPDATE {$p}_nai3 SET LADATE='0000-00-00' WHERE ID=1");
        $this->base->query("INSERT INTO {$p}_log (date) VALUES ('0000-00-00 00:00:00')");
        $this->base->query("INSERT INTO {$p}_sums (COMMUNE,DTDEPOT,DER_MAJ) VALUES ('TEMOIN','0000-00-00','0000-00-00 00:00:00')");
        $q=$this->base->prepare('SET SESSION sql_mode=?');$q->execute([$mode]);$this->migrer();
        self::assertSame(['1001-01-01','1001-01-01','2020-01-02'],$this->base->query("SELECT LADATE,DTDEPOT,DTMODIF FROM {$p}_mar3 WHERE ID=1")->fetch_row());
        self::assertSame('0000-00-00',$this->base->query("SELECT LADATE FROM {$p}_nai3 WHERE ID=1")->fetch_row()[0]);
        self::assertSame('0000-00-00 00:00:00',$this->base->query("SELECT date FROM {$p}_log")->fetch_row()[0]);
        self::assertSame(['0000-00-00','0000-00-00 00:00:00'],$this->base->query("SELECT DTDEPOT,DER_MAJ FROM {$p}_sums")->fetch_row());
        self::assertSame($mode,$this->base->query('SELECT @@SESSION.sql_mode')->fetch_row()[0]);
    }
    public function testUnArretApresEffetEstRejouable(): void
    {
        try { $this->migrer(static function(string $e): void { if ($e==='capacite:mar3') throw new \RuntimeException('arrêt simulé'); });self::fail('Pas interrompu'); }
        catch (\RuntimeException $e) { self::assertSame(\RuntimeException::class,$e::class); self::assertSame('arrêt simulé',$e->getMessage()); }
        $this->migrer();self::assertSame(' 3.2.4 ',$this->parametre('EA_VERSION'));
    }
    public static function urls(): iterable { yield [''];yield ['https://personnalise.test/site']; }
    #[DataProvider('urls')]
    public function testUneUrlVideEstCompleteeEtUneUrlExistanteConservee(string $url): void
    {
        $q=$this->base->prepare("INSERT INTO {$this->prefixe}_params (param,valeur,aide) VALUES ('URL_PUBLIQUE',?,'')");$q->execute([$url]);
        $this->migrer();self::assertSame($url===''?'https://exemple.test':$url,$this->parametre('URL_PUBLIQUE'));
    }
    public function testUnModeleRacineAbsentNeBloquePasLaMigrationSql(): void
    {
        unlink($this->racine.'/.htaccess_init');
        $this->migrer();
        self::assertSame('InnoDB',$this->base->query("SHOW TABLE STATUS WHERE Name='{$this->prefixe}_user3'")->fetch_assoc()['Engine']);
        self::assertNotNull(\ActaLibre\Install\RapportProtection::lire($this->racine)->avertissement());
    }
    public function testLesSourcesHistoriquesSontArchiveesSansLireLesInconnus(): void
    {
        mkdir($this->racine.'/_logs'); mkdir($this->racine.'/_backup');
        file_put_contents($this->racine.'/_logs/temoin', 'journal');
        file_put_contents($this->racine.'/_backup/temoin', 'sauvegarde');
        file_put_contents($this->racine.'/inconnu.php', 'SENTINELLE INERTE');
        $this->migrer();
        self::assertDirectoryDoesNotExist($this->racine.'/_logs');
        self::assertCount(1,glob($this->racine.'/tmp/archive/*/logs/temoin'));
        self::assertCount(1,glob($this->racine.'/tmp/archive/*/backups/temoin'));
        self::assertSame('SENTINELLE INERTE',file_get_contents($this->racine.'/inconnu.php'));
    }
    public function testUneProtectionRacinePersonnaliseeEstConserveeEtSignalee(): void
    {
        file_put_contents($this->racine.'/.htaccess',"# configuration de l’hébergeur\n");
        $this->migrer();
        self::assertSame("# configuration de l’hébergeur\n",file_get_contents($this->racine.'/.htaccess'));
        $avis=(new MigrationExpoActes($this->racine))->avis($this->c);
        self::assertNotSame([],array_filter($avis,static fn(string $a): bool=>str_contains($a,'composer')));
    }
    public function testUnHashVideEstConserveEtSignale(): void
    {
        $this->base->query("UPDATE {$this->prefixe}_user3 SET hashpass='' WHERE ID=2");
        $this->migrer();
        self::assertSame('',$this->base->query("SELECT hashpass FROM {$this->prefixe}_user3 WHERE ID=2")->fetch_row()[0]);
        $avis=(new MigrationExpoActes($this->racine))->avis($this->c);
        self::assertNotSame([],array_filter($avis,static fn(string $a): bool=>str_contains($a,'empreinte')));
    }
    public function testUneTableModerneIncompatibleEstRefuseeAvantMutation(): void
    {
        $this->base->query("CREATE TABLE {$this->prefixe}_session (empreinte VARCHAR(12) PRIMARY KEY) ENGINE=InnoDB");
        $avant=$this->empreinte();
        try { (new MigrationExpoActes($this->racine))->controler($this->c);self::fail('Table incompatible admise'); }
        catch (\RuntimeException $e) { self::assertSame(\RuntimeException::class,$e::class); self::assertSame($avant,$this->empreinte()); }
    }
    public function testUnLotDeDatesEstBorneADeuxCentsLignes(): void
    {
        $mode=$this->base->query('SELECT @@SESSION.sql_mode')->fetch_row()[0];$this->base->query("SET SESSION sql_mode=''");
        for ($i=1;$i<=201;$i++) $this->base->query("INSERT INTO {$this->prefixe}_mar3 (ID,LADATE) VALUES ($i,'0000-00-00')");
        $q=$this->base->prepare('SET SESSION sql_mode=?');$q->execute([$mode]);
        $m=new MigrationExpoActes($this->racine);
        $r=$m->executer($this->c,'dates:actes:mar3:LADATE',null,'https://exemple.test');
        self::assertFalse($r['terminee']);
        self::assertSame(200,(int)$this->base->query("SELECT COUNT(*) FROM {$this->prefixe}_mar3 WHERE LADATE='1001-01-01'")->fetch_row()[0]);
        self::assertTrue($m->executer($this->c,'dates:actes:mar3:LADATE',null,'https://exemple.test')['terminee']);
    }
    public function testUneDefinitionMgrplgAvecSigleNullNEstPasDupliquee(): void
    {
        $f=$this->racine.'/install/creetables.sql';
        file_put_contents($f,"\nINSERT INTO EA_DB_mgrplg (grp,dtable,lg,sigle,getiq) VALUES ('ZZ','N','fr',NULL,'Null test');\n",FILE_APPEND);
        $this->migrer();$this->migrer();
        self::assertSame(1,(int)$this->base->query("SELECT COUNT(*) FROM {$this->prefixe}_mgrplg WHERE grp='ZZ' AND sigle IS NULL")->fetch_row()[0]);
    }
    public function testLeMoteurLuiMemeRefuseUneAutreSource(): void
    {
        $this->base->query("UPDATE {$this->prefixe}_params SET valeur='4.0.0' WHERE param='EA_VERSION'");
        $avant=$this->empreinte();
        try { (new MigrationExpoActes($this->racine))->controler($this->c);self::fail('Source 4.0.0 admise'); }
        catch (\RuntimeException $e) { self::assertSame(\RuntimeException::class,$e::class); self::assertSame($avant,$this->empreinte()); }
    }
    public function testUnHashInconnuEtUneStructureInattendueBloquentAvantMutation(): void
    {
        $this->base->query("UPDATE {$this->prefixe}_user3 SET hashpass='inconnu' WHERE ID=1");$avant=$this->empreinte();
        try { (new MigrationExpoActes($this->racine))->controler($this->c);self::fail('Hash inconnu admis'); }
        catch (\RuntimeException $e) { self::assertSame(\RuntimeException::class,$e::class); self::assertSame($avant,$this->empreinte()); }
    }
}
