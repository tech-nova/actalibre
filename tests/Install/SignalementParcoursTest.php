<?php
declare(strict_types=1);
namespace ActaLibre\Tests\Install;
use ActaLibre\Install\{Inventaire,Conservation,SignalementFichiers};
use ActaLibre\Tests\Infrastructure\InventaireDuBanc;
use ActaLibre\Tests\GoldenMaster\Client;
use PHPUnit\Framework\TestCase;

final class SignalementParcoursTest extends TestCase
{
    public function testLesNomsDesProducteursReelsSontConservesEtLesInconnusEchappes(): void
    {
        if (getenv('GM_DB_NAME')===false) { self::markTestSkipped('GM_DB_* requis.'); }
        $bin=sys_get_temp_dir().'/al5-dump-'.bin2hex(random_bytes(6));mkdir($bin);
        file_put_contents($bin.'/mysqldump',"#!/bin/sh\nprintf '%s\\n' '-- TEMOIN SQL INERTE'\n");chmod($bin.'/mysqldump',0755);
        $s=new SiteInstallation(dirname(__DIR__,2),['hote'=>getenv('GM_DB_HOST'),'nom'=>getenv('GM_DB_NAME'),'utilisateur'=>getenv('GM_DB_USER'),'passe'=>getenv('GM_DB_PASS')]);
        try {
            $path=getenv('PATH');putenv('PATH='.$bin.':'.$path);
            try { $s->demarrer(); } finally { putenv('PATH='.$path); }
            InventaireDuBanc::ecrire($s->racine);
            $c=$s->client();
            self::assertSame(200,InstallationHttp::installer($c,$s->racine,$s->formulaire())->statut);
            file_put_contents($s->racine.'/_config/connect.inc.php', "\ndefine('GEOCODEUR','mock');\n",FILE_APPEND);
            $c->post('/admin/index.php',['login'=>'admin','passwd'=>'Secret-2026']);
            $s->base->query("INSERT INTO {$s->prefixe}_nai3 (COMMUNE,DEPART,NOM,PRE,LADATE) VALUES ('Lieu','Dept','TEMOIN','Alice','1900-01-01')");
            $dump=$c->get('/admin/backup.php',['jeton'=>Client::JETON]);
            self::assertSame(200,$dump->statut,$dump->corps);
            $nom=$s->connexion['nom'].'_'.date('Y-m-d').'.sql';
            self::assertSame("-- TEMOIN SQL INERTE\n",file_get_contents($s->racine.'/tmp/backups/'.$nom));
            self::assertStringContainsString('tmp/backups/'.$nom,$dump->corps);
            $exp=$c->post('/admin/exporte.php',['action'=>'go','TypeActes'=>'N','Destin'=>'B','ComDep'=>'BACKUP [TOUS]','maxmega'=>'100']);
            self::assertSame(200,$exp->statut,$exp->corps);
            $exports=glob($s->racine.'/tmp/backups/backup_*.bea');self::assertNotEmpty($exports,$exp->corps);
            $c->get('/eclair.php');self::assertNotEmpty(glob($s->racine.'/tmp/logs/eclair-*.log'));
            file_put_contents($bin.'/entree.txt', "TEMOIN INERTE\n");
            $charge=$c->post('/admin/charge.php',['action'=>'submitted','Origine'=>'N','TypeActes'=>'N','bkfile'=>'NULL'],['Actes'=>$bin.'/entree.txt']);
            self::assertSame(200,$charge->statut,$charge->corps);
            self::assertFileExists($s->racine.'/admin/_upload/admin.csv');
            file_put_contents($bin.'/entree.csv', "ALICE;TEMOIN\n");
            $commun=['TypeActes'=>'N','Commune'=>'Lieu','Depart'=>'Dept'];
            $csv=$c->post('/admin/chargecsv.php',$commun+['action'=>'phase_1','submitD'=>'1'],['Actes'=>$bin.'/entree.csv']);
            self::assertSame(200,$csv->statut,$csv->corps);
            self::assertSame("ALICE;TEMOIN\n",file_get_contents($s->racine.'/admin/_upload/admin.csv'));
            preg_match_all('/name="ZID(\d+)" value="(\d+)"/',$csv->corps,$zones,PREG_SET_ORDER);
            self::assertNotEmpty($zones);
            $modele=$commun+['action'=>'phase_2','submitS'=>'1','ModeleS'=>'Temoin','fileuploaded'=>'_upload/admin.csv'];
            foreach ($zones as [,$rang,$zid]) { $modele['ZID'.$rang]=$zid;$modele['Zone'.$rang]='0';$modele['Trait'.$rang]='0'; }
            $sauve=$c->post('/admin/chargecsv.php',$modele);
            self::assertSame(200,$sauve->statut,$sauve->corps);
            self::assertFileExists($s->racine.'/admin/_upload/N-Temoin.m_csv');
            $i=Inventaire::lire($s->racine);$regles=new Conservation($i,$s->connexion['nom'],'_upload');
            $inconnus=array_column(iterator_to_array((new SignalementFichiers($s->racine,$i,$regles))->lister(),false),'chemin');
            foreach ([$nom,basename($exports[0])] as $p) { self::assertNotContains('tmp/backups/'.$p,$inconnus); }
            self::assertNotContains('admin/_upload/admin.csv',$inconnus);
            self::assertNotContains('admin/_upload/N-Temoin.m_csv',$inconnus);
            self::assertNotContains('tmp/actalibre/protection.json',$inconnus);
            self::assertNotContains('tmp/.htaccess',$inconnus);
            file_put_contents($s->racine.'/inconnu-<img>.php','SENTINELLE INERTE');
            $page=$c->get('/admin/protection.php');self::assertStringContainsString('inconnu-&lt;img&gt;.php',$page->corps);
            self::assertStringNotContainsString('inconnu-<img>.php',$page->corps);
            self::assertSame('SENTINELLE INERTE',file_get_contents($s->racine.'/inconnu-<img>.php'));
            self::assertSame('',$s->journal());
        } finally { $s->arreter();foreach (glob($bin.'/*') as $f) { unlink($f); }rmdir($bin); }
    }
}
