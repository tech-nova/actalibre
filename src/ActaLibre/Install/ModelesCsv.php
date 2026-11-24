<?php
declare(strict_types=1);
namespace ActaLibre\Install;

/** Modèles de chargement CSV livrés : copie des seuls absents dans le dépôt d'un site. */
final class ModelesCsv
{
    /** @return list<string> avis ; un échec ne bloque jamais la mise à jour */
    public static function completer(string $racine, string $depot = '_upload'): array
    {
        $cible = $racine . '/admin/' . $depot;
        $invalide = str_contains($depot, '\\') || str_contains($depot, "\0")
            || array_intersect(explode('/', $depot), ['', '.', '..']) !== [];
        if ($invalide || !is_dir($cible)) {
            return ['Modèles de chargement CSV non copiés : dépôt des fichiers téléversés invalide ou absent.'];
        }
        $echecs = [];
        foreach (glob($racine . '/admin/_upload_init/*.m_csv') ?: [] as $source) {
            $nom = basename($source);
            $fichier = $cible . '/' . $nom;
            // Un modèle personnel de même nom est conservé ; un lien, même rompu, n'est pas suivi.
            if (is_link($fichier) || file_exists($fichier)) {
                continue;
            }
            $contenu = is_link($source) ? false : @file_get_contents($source);
            $flux = $contenu === false ? false : @fopen($fichier, 'x');
            if ($contenu === false || $flux === false) {
                $echecs[] = $nom;
                continue;
            }
            $ecrit = @fwrite($flux, $contenu) === strlen($contenu);
            fclose($flux);
            if (!$ecrit) {
                @unlink($fichier);
                $echecs[] = $nom;
            }
        }
        return $echecs === [] ? [] : ['Modèles de chargement CSV non copiés : ' . implode(', ', $echecs) . '.'];
    }

    /** @return list<string> */
    public static function completerSite(string $racine, ConnexionsSite $c): array
    {
        try {
            return self::completer($racine, DiagnosticInstallation::depot($c->actes, $c->configuration->actes->prefixe));
        } catch (\Throwable) {
            return ['Modèles de chargement CSV non copiés : réglage du dépôt illisible.'];
        }
    }
}
