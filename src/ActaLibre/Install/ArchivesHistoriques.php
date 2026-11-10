<?php
declare(strict_types=1);
namespace ActaLibre\Install;

/** Au plus deux rename de dossiers entiers : aucune lecture ou suppression de leur contenu. */
final class ArchivesHistoriques
{
    /** @return array{archives:list<string>,erreurs:list<string>} */
    public static function deplacer(string $racine): array
    {
        $r = ['archives'=>[], 'erreurs'=>[]]; $sources = [];
        foreach (['_logs'=>'logs', '_backup'=>'backups'] as $source=>$nom) {
            $p = $racine.'/'.$source;
            if (!file_exists($p) && !is_link($p)) { continue; }
            $stat = @lstat($p);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0040000) { $r['erreurs'][] = $source.' : dossier physique attendu, source conservée.'; continue; }
            $sources[$source] = [$nom, $stat['dev']];
        }
        if ($sources === []) { return $r; }
        $destination = 'tmp/archive/'.gmdate('ymdHis');
        if (!EspacePrive::dossier($racine, 'tmp/archive') || !@mkdir($racine.'/'.$destination, 0755)) {
            $r['erreurs'][] = 'Archive non réservée : sources historiques conservées.'; return $r;
        }
        $stat = @lstat($racine.'/'.$destination);
        foreach ($sources as $source=>[$nom,$dev]) {
            $cible = $destination.'/'.$nom;
            if ($stat === false || $stat['dev'] !== $dev || file_exists($racine.'/'.$cible) || is_link($racine.'/'.$cible) || !@rename($racine.'/'.$source, $racine.'/'.$cible)) {
                $r['erreurs'][] = $source.' : archivage impossible, source conservée.';
            } else { $r['archives'][] = $cible; }
        }
        return $r;
    }
}
