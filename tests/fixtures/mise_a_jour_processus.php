<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use ActaLibre\Install\{ConfigurationSite,EtatExploitation,MiseAJour,SessionMiseAJour};
try {
    $racine=$argv[1];session_save_path($racine.'/sessions');session_id($argv[2]);SessionMiseAJour::ouvrir('/',false);
    $configuration=ConfigurationSite::charger($racine,'localhost');$c=$configuration->ouvrir();
    $a=SessionMiseAJour::autorisation($configuration->perimetre());SessionMiseAJour::fermer();
    $m=new MiseAJour($racine,static function(string $etape) use($racine): void {
        if ($etape!=='capacite:mar3') return;
        file_put_contents($racine.'/verrou-pris',$etape);
        while (true) usleep(100000);
    });
    for ($i=0;$i<100;$i++) {
        $e=EtatExploitation::lire($c,$racine.'/composer.json');
        $m->executer($c,$a,$e->action==='expoactes'?'demarrer':'continuer','https://exemple.test');
    }
} catch (Throwable $e) { fwrite(STDERR,$e::class.' ('.basename($e->getFile()).':'.$e->getLine().')');exit(51); }
