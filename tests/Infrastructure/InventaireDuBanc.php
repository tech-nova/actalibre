<?php
declare(strict_types=1);
namespace ActaLibre\Tests\Infrastructure;
use ActaLibre\Livraison;
use ActaLibre\Install\Inventaire;

/** Inventorie uniquement la copie du code de test, avant installation et ajout de témoins étrangers. */
final class InventaireDuBanc
{
    public static function livrable(string $p): bool
    {
        $parties=explode('/',$p);
        foreach ($parties as $partie) {
            if (in_array($partie,['vendor','tests','docs','graft','tmp','_config','_logs','_backup','dist','__pycache__'],true) || str_starts_with($partie,'.git') || str_starts_with($partie,'.superpowers') || str_starts_with($partie,'.php')) { return false; }
        }
        return !in_array($p,['.htaccess','composer.lock','phpunit.xml.dist','tools/construire_distribution.py','inventaire-actalibre.json','install/configuration.php','_config_init/connect.inc.php','tests/Install/LitteralConfigTest.php'],true) && !str_starts_with($p,'admin/_upload/');
    }
    public static function ecrire(string $racine): void
    {
        $fichiers=[Inventaire::NOM]; $repertoires=[];
        $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($racine,\FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->isLink()) { continue; }
            $p=substr($f->getPathname(),strlen($racine)+1);
            if ($p===Inventaire::NOM) { continue; }
            $fichiers[]=$p;
            $d=dirname($p);
            while ($d!=='.') { $repertoires[$d]=true; $d=dirname($d); }
        }
        sort($fichiers); $dirs=array_keys($repertoires); sort($dirs);
        $l=Livraison::lire($racine.'/composer.json');
        file_put_contents($racine.'/'.Inventaire::NOM,json_encode(['format'=>1,'version'=>$l->version,'schema'=>$l->schema,'revision'=>str_repeat('0',40),'fichiers'=>$fichiers,'repertoires'=>$dirs],JSON_THROW_ON_ERROR));
    }
}
