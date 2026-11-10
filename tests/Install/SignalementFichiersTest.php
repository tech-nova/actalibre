<?php
declare(strict_types=1);
namespace ActaLibre\Tests\Install;
use ActaLibre\Install\Conservation;
use ActaLibre\Install\SignalementFichiers;
final class SignalementFichiersTest extends BaseDiagnostic
{
    public function testNeLitNiNeModifieLesInconnusEtNeDescendPasDansUnDossierEtranger(): void
    {
        $this->poser('install/configuration.php','SENTINELLE INERTE');
        $this->poser('etranger/debug.php','INERTE');
        for ($i=0; $i<1001; $i++) { $this->poser('etranger/'.$i.'.bin','x'); }
        $this->poser('admin/depot/imports/admin.csv','donnees');
        $this->poser('admin/depot/etranger/f.php','INERTE');
        $this->poser('tmp/.htaccess','refus');
        $this->poser("nom-\xFF.txt", 'temoin');
        symlink($this->root.'/etranger', $this->root.'/lien');
        $avant = lstat($this->root.'/install/configuration.php');
        $i=$this->inventaire(); $r=new Conservation($i,'base','depot/imports');
        $liste=iterator_to_array((new SignalementFichiers($this->root,$i,$r))->lister(),false);
        $chemins=array_column($liste,'chemin');
        self::assertContains('etranger',$chemins);
        self::assertNotContains('etranger/debug.php',$chemins);
        self::assertContains('admin/depot/etranger',$chemins);
        self::assertNotContains('admin/depot/imports/admin.csv',$chemins);
        self::assertContains('lien',$chemins);
        self::assertNotContains('tmp/.htaccess',$chemins);
        self::assertSame($avant['ino'], lstat($this->root.'/install/configuration.php')['ino']);
        self::assertSame('SENTINELLE INERTE',file_get_contents($this->root.'/install/configuration.php'));
        self::assertCount(1002,scandir($this->root.'/etranger', SCANDIR_SORT_NONE) === false ? [] : array_diff(scandir($this->root.'/etranger'),['.','..']));
        self::assertNotEmpty(array_filter($chemins,static fn(string $p):bool=>str_contains($p,'base64:')));
    }
    public function testUneCopieDevenueIncompleteRefuseTouteLaListe(): void
    {
        $i=$this->inventaire(); $r=new Conservation($i,'base','_upload');
        unlink($this->root.'/_config_init/actes.css');
        $this->expectException(\RuntimeException::class);
        iterator_to_array((new SignalementFichiers($this->root,$i,$r))->lister());
    }
}
