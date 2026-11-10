<?php
declare(strict_types=1);
namespace ActaLibre\Install;

final class EspacePrive
{
    private const MODELES = ['.htaccess','logs/.keep','backups/.keep','archive/.keep'];
    /** Crée seulement les chemins possédés par ActaLibre, sans suivre de lien. */
    public static function dossier(string $racine, string $relatif): bool
    {
        $p = rtrim($racine, '/');
        foreach (explode('/', $relatif) as $composante) {
            if ($composante === '' || $composante === '.' || $composante === '..' || str_contains($composante, '\\')) { return false; }
            $p .= '/' . $composante;
            clearstatcache(true, $p);
            if (is_link($p) || (file_exists($p) && !is_dir($p))) { return false; }
            if (!is_dir($p) && !@mkdir($p, 0755)) { return false; }
        }
        return true;
    }
    /** @return array{crees:list<string>,erreurs:list<string>} */
    public static function preparer(string $racine): array
    {
        $r = ['crees'=>[], 'erreurs'=>[]];
        foreach (self::MODELES as $modele) {
            $relatif = 'tmp/'.$modele;
            if (!self::dossier($racine, dirname($relatif))) { $r['erreurs'][] = $relatif; continue; }
            $cible = $racine.'/'.$relatif;
            if (is_link($cible) || (file_exists($cible) && !is_file($cible))) { $r['erreurs'][] = $relatif; continue; }
            if (is_file($cible)) { continue; }
            $source = $racine.'/tmp_init/'.$modele;
            $flux = @fopen($cible, 'x');
            if ($flux === false) { $r['erreurs'][] = $relatif; continue; }
            $contenu = !is_link($racine.'/tmp_init') && !is_link(dirname($source)) && !is_link($source) && is_file($source) ? @file_get_contents($source) : false;
            $ok = $contenu !== false && fwrite($flux, $contenu) === strlen($contenu);
            fclose($flux);
            if ($ok) { $r['crees'][] = $relatif; }
            else { @unlink($cible); $r['erreurs'][] = $relatif; }
        }
        return $r;
    }
    public static function chemin(string $racine, string $usage): string
    {
        $d = match ($usage) { 'logs'=>'logs', 'backups'=>'backups', 'etat'=>'actalibre', default=>throw new \InvalidArgumentException('Usage privé inconnu.') };
        return rtrim($racine, '/').'/tmp/'.$d;
    }
}
