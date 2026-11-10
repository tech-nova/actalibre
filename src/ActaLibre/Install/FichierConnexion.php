<?php

declare (strict_types=1);
namespace ActaLibre\Install;

/** Publication d'un fichier complet, sans remplacement ni écriture directe du chemin final. */
final class FichierConnexion
{
    public const PUBLIE = 'publie';
    public const MANUEL = 'manuel';
    public const EXISTANT = 'existant';
    private const MODELES = ['.htaccess_init' => '.htaccess', '_config_init' => '_config', 'admin/_upload_init' => 'admin/_upload'];
    public static function contenu(AccesMysql $actes, ?AccesMysql $comptes = null): string
    {
        $contenu = "<?php\n";
        foreach ([[$actes, 'db', 'EA_DB'], ...$comptes === null ? [] : [[$comptes, 'udb', 'EA_UDB']]] as [$acces, $variable, $constante]) {
            $contenu .= 'define(' . var_export($constante, true) . ', ' . var_export($acces->prefixe, true) . ");\n";
            foreach (['addr' => 'serveur', 'name' => 'base', 'user' => 'utilisateur', 'pass' => 'motDePasse'] as $suffixe => $propriete) {
                $contenu .= '$' . $variable . $suffixe . ' = ' . var_export($acces->{$propriete}, true) . ";\n";
            }
        }
        return $contenu;
    }
    /** @phpstan-impure Le fichier peut apparaître entre deux vérifications. */
    private static function existe(string $racine): bool
    {
        clearstatcache();
        return is_link($racine . '/_config/connect.inc.php') || InstallGuard::etat($racine . '/_config') === InstallGuard::INSTALLE;
    }
    public static function publier(string $racine, #[\SensitiveParameter] string $contenu): string
    {
        if (self::existe($racine)) {
            return self::EXISTANT;
        }
        $config = $racine . '/_config';
        if (!is_dir($config) && !@mkdir($config, 0755, true)) {
            return self::MANUEL;
        }
        $verrou = @fopen($config . '/.actalibre-installation.lock', 'c');
        if ($verrou === false) {
            return self::MANUEL;
        }
        if (!flock($verrou, LOCK_EX | LOCK_NB)) {
            fclose($verrou);
            throw new \RuntimeException('installation en cours');
        }
        $temporaire = null;
        try {
            if (self::existe($racine)) {
                return self::EXISTANT;
            }
            foreach (self::MODELES as $source => $cible) {
                if (!self::completer($racine . '/' . $source, $racine . '/' . $cible)) {
                    return self::MANUEL;
                }
            }
            EspacePrive::preparer($racine);
            $bilan = Protections::actualiser($racine);
            if (in_array('_config', $bilan['a_verifier'], true)) {
                return self::MANUEL;
            }
            foreach (glob($config . '/.actalibre-connect-*.tmp') ?: [] as $ancien) {
                if (!@unlink($ancien)) {
                    return self::MANUEL;
                }
            }
            if (!function_exists('link')) {
                return self::MANUEL;
            }
            $temporaire = $config . '/.actalibre-connect-' . bin2hex(random_bytes(12)) . '.tmp';
            $f = @fopen($temporaire, 'x');
            if ($f === false) {
                return self::MANUEL;
            }
            try {
                if (!@chmod($temporaire, 0600) || @fwrite($f, $contenu) !== strlen($contenu) || !@fflush($f)) {
                    return self::MANUEL;
                }
            } finally {
                fclose($f);
            }
            if (@file_get_contents($temporaire) !== $contenu) {
                return self::MANUEL;
            }
            if (self::existe($racine)) {
                return self::EXISTANT;
            }
            if (@link($temporaire, $config . '/connect.inc.php')) {
                return self::PUBLIE;
            }
            return self::existe($racine) ? self::EXISTANT : self::MANUEL;
        } finally {
            if ($temporaire !== null) {
                @unlink($temporaire);
            }
            flock($verrou, LOCK_UN);
            fclose($verrou);
        }
    }
    /** Copie des seuls éléments absents, y compris les protections cachées. */
    private static function completer(string $source, string $cible): bool
    {
        if (is_link($cible)) {
            return false;
        }
        if (is_file($source)) {
            return is_file($cible) || @copy($source, $cible);
        }
        if (!is_dir($source)) {
            return false;
        }
        if (!is_dir($cible) && !@mkdir($cible, 0755, true)) {
            return false;
        }
        $noms = @scandir($source);
        if ($noms === false) {
            return false;
        }
        foreach ($noms as $nom) {
            if ($nom === '.' || $nom === '..') {
                continue;
            }
            if (!self::completer($source . '/' . $nom, $cible . '/' . $nom)) {
                return false;
            }
        }
        return true;
    }
}
