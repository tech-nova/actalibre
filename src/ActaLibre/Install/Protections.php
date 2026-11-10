<?php
declare(strict_types=1);

namespace ActaLibre\Install;

/** Crée ou met à jour les fichiers `.htaccess` d'un site déjà installé. */
final class Protections
{
    private const REFUS = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";

    private const REFUS_SAUF_CSS = "<IfModule mod_authz_core.c>\n    Require all denied\n"
        . "    <FilesMatch \"\\.css$\">\n        Require all granted\n    </FilesMatch>\n</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n"
        . "    <FilesMatch \"\\.css$\">\n        Allow from all\n    </FilesMatch>\n</IfModule>\n";

    private const ANCIEN_REFUS = 'deny from all';
    private const ANCIEN_DEPOT = "<Files .htaccess>\norder allow,deny\ndeny from all\n</Files>";
    private const ANCIEN_CONFIG = "<Files *.css>\nallow from all\n</Files>\n\ndeny from all";

    private const DOSSIERS = [
        'tmp' => [self::REFUS, []],
        '_config' => [self::REFUS_SAUF_CSS, [self::ANCIEN_CONFIG]],
        '_backup' => [self::REFUS, [self::ANCIEN_REFUS]],
        '_logs' => [self::REFUS, [self::ANCIEN_REFUS]],
        'admin/_upload' => [self::REFUS, [self::ANCIEN_DEPOT, self::ANCIEN_REFUS]],
    ];

    /**
     * Crée une protection absente et remplace un fichier resté identique à un ancien modèle.
     * Un fichier modifié n'est pas touché.
     *
     * @return array{crees: list<string>, remplaces: list<string>, a_verifier: list<string>, racine_cree: bool, echecs: list<string>}
     */
    public static function actualiser(string $racine, string $uploadDir = '_upload'): array
    {
        if ($uploadDir === '' || str_starts_with($uploadDir, '/') || str_contains($uploadDir, '\\') || str_contains($uploadDir, "\0") || array_intersect(explode('/', $uploadDir), ['', '.', '..']) !== []) { throw new \RuntimeException('Dépôt invalide.'); }
        $bilan = ['crees' => [], 'remplaces' => [], 'a_verifier' => [], 'racine_cree' => self::creerRacine($racine), 'echecs' => []];
        foreach (self::DOSSIERS as $dossier => [$modele, $anciens]) {
            if ($dossier === 'admin/_upload') $dossier = 'admin/' . $uploadDir;
            $chemin = $racine . '/' . $dossier;
            if (in_array($dossier, ['_backup', '_logs'], true) && !file_exists($chemin)) { continue; }
            $fichier = $chemin . '/.htaccess';
            if (!is_file($fichier)) {
                $cree = (is_dir($chemin) || @mkdir($chemin, 0755, true)) && @file_put_contents($fichier, $modele) !== false;
                $bilan[$cree ? 'crees' : 'a_verifier'][] = $dossier;
                if (!$cree) $bilan['echecs'][] = $dossier;
                continue;
            }
            $contenu = @file_get_contents($fichier);
            if ($contenu === false) {
                $bilan['echecs'][] = $dossier;
                $bilan['a_verifier'][] = $dossier;
                continue;
            }
            $normal = trim(str_replace("\r\n", "\n", $contenu));
            if ($normal === trim($modele)) {
                continue;
            }
            if (in_array($normal, $anciens, true)) {
                if (@file_put_contents($fichier, $modele) !== false && @file_get_contents($fichier) === $modele) {
                    $bilan['remplaces'][] = $dossier;
                    continue;
                }
                $bilan['echecs'][] = $dossier;
            }
            $bilan['a_verifier'][] = $dossier;
        }
        $fichierRacine = $racine . '/.htaccess';
        if (is_file($racine . '/.htaccess_init')) {
            $modeleRacine = @file_get_contents($racine . '/.htaccess_init');
            $contenuRacine = is_file($fichierRacine) ? @file_get_contents($fichierRacine) : false;
            if ($modeleRacine === false || $contenuRacine === false) {
                $bilan['echecs'][] = '.';
            } elseif (trim(str_replace("\r\n", "\n", $modeleRacine)) !== trim(str_replace("\r\n", "\n", $contenuRacine))) {
                $bilan['a_verifier'][] = '.';
            }
        }
        return $bilan;
    }

    private static function creerRacine(string $racine): bool
    {
        $fichier = $racine . '/.htaccess';
        $modele = $racine . '/.htaccess_init';
        return !file_exists($fichier) && is_file($modele) && @copy($modele, $fichier);
    }

    /** Contenu attendu du modèle d'un dossier : les modèles d'installation doivent lui être identiques. */
    public static function modele(string $dossier): string
    {
        return self::DOSSIERS[$dossier][0];
    }
}
