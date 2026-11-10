<?php
declare(strict_types=1);

namespace ActaLibre\Install;

/**
 * État d'installation fondé sur les fichiers de _config/
 * (utilisable même quand la base de données est injoignable).
 */
final class InstallGuard
{
    public const NON_INSTALLE = 'non_installe';
    public const INSTALLE = 'installe';

    public static function etat(string $dossierConfig): string
    {
        $dossier = rtrim($dossierConfig, '/');
        if ((glob($dossier . '/BD-*-connect.inc.php') ?: []) !== []) {
            return self::INSTALLE;
        }
        if (is_file($dossier . '/connect.inc.php')) {
            return self::INSTALLE;
        }
        return self::NON_INSTALLE;
    }

    /** Le nom d'hôte sert à construire un nom de fichier de configuration. */
    public static function nomHoteValide(string $nom): bool
    {
        return preg_match('/^[A-Za-z0-9.:\[\]-]{1,253}$/D', $nom) === 1
            && !str_contains($nom, '..');
    }

    public static function refuser(int $code, string $message): never
    {
        error_log('SECU : ' . $message);
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo $message, "\n";
        exit;
    }
}
