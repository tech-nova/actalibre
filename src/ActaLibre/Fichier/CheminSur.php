<?php
declare(strict_types=1);

namespace ActaLibre\Fichier;

/**
 * Construit un chemin de fichier confiné dans un dossier à partir d'un nom reçu
 * de la requête. Seul le dernier segment du nom est retenu.
 */
final class CheminSur
{
    /**
     * Rend un nom utilisable comme partie d'un nom de fichier : sans barre oblique ni caractère spécial,
     * identique pour un nom ordinaire, différent pour deux noms différents.
     */
    public static function segment(string $nom): string
    {
        return rawurlencode($nom);
    }

    /**
     * @param string $dossier     dossier autorisé (relatif ou absolu)
     * @param string $nom         nom ou chemin reçu (seul le dernier segment est gardé)
     * @param string $motif       expression régulière que doit vérifier le nom de base
     * @param bool   $doitExister si vrai, le fichier doit exister dans le dossier (liens symboliques résolus)
     * @return string|null        $dossier . '/' . nom de base, ou null si refusé
     */
    public static function dans(string $dossier, string $nom, string $motif, bool $doitExister = true): ?string
    {
        if ($nom === '' || str_contains($nom, "\0")) {
            return null;
        }
        // Pas de basename() : il dépend de la locale et peut altérer les noms UTF-8.
        $normalise = str_replace('\\', '/', $nom);
        $position = strrpos($normalise, '/');
        $base = $position === false ? $normalise : substr($normalise, $position + 1);
        if ($base === '' || $base === '.' || $base === '..' || preg_match($motif, $base) !== 1) {
            return null;
        }
        $chemin = rtrim($dossier, '/') . '/' . $base;
        if ($doitExister) {
            $reel = realpath($chemin);
            $racine = realpath($dossier);
            if ($reel === false || $racine === false || !is_file($reel)
                || !str_starts_with($reel, $racine . DIRECTORY_SEPARATOR)) {
                return null;
            }
        }
        return $chemin;
    }
}
