<?php
declare(strict_types=1);
namespace ActaLibre\Install;

/**
 * Preuve de contrôle du serveur : un fichier vide déposé par FTP dans _config/,
 * sous un nom aléatoire lié à la session. Elle autorise la création d'un administrateur.
 * Les appelants passent $_SESSION, ouverte.
 */
final class PreuveFtp
{
    public const DUREE = 3600;
    private const CLE = 'preuve_ftp';

    /**
     * Nom attendu, renouvelé à expiration.
     * @param array<mixed> $session
     */
    public static function nom(array &$session, ?int $maintenant = null): string
    {
        $maintenant ??= time();
        $preuve = self::lire($session, $maintenant);
        if ($preuve === null) {
            $preuve = ['nom' => 'admin-' . bin2hex(random_bytes(16)) . '.txt', 'debut' => $maintenant];
            $session[self::CLE] = $preuve;
        }
        return $preuve['nom'];
    }

    /**
     * Le fichier attendu par cette session est présent, non expiré, et n'est pas un lien.
     * @param array<mixed> $session
     */
    public static function presente(string $racine, array $session, ?int $maintenant = null): bool
    {
        $preuve = self::lire($session, $maintenant ?? time());
        if ($preuve === null) {
            return false;
        }
        $chemin = self::chemin($racine, $preuve['nom']);
        clearstatcache(true, $chemin);
        return !is_link($chemin) && is_file($chemin);
    }

    /**
     * Usage unique : le fichier est retiré et la session oublie son nom.
     * @param array<mixed> $session
     */
    public static function consommer(string $racine, array &$session): void
    {
        $preuve = $session[self::CLE] ?? null;
        unset($session[self::CLE]);
        if (is_array($preuve) && is_string($preuve['nom'] ?? null) && self::nomValide($preuve['nom'])) {
            @unlink(self::chemin($racine, $preuve['nom']));
        }
    }

    /**
     * @param array<mixed> $session
     * @return array{nom:string,debut:int}|null
     */
    private static function lire(array $session, int $maintenant): ?array
    {
        $preuve = $session[self::CLE] ?? null;
        if (!is_array($preuve) || !is_string($preuve['nom'] ?? null) || !is_int($preuve['debut'] ?? null)
            || !self::nomValide($preuve['nom']) || $preuve['debut'] > $maintenant || $maintenant - $preuve['debut'] >= self::DUREE) {
            return null;
        }
        return ['nom' => $preuve['nom'], 'debut' => $preuve['debut']];
    }

    private static function nomValide(string $nom): bool
    {
        return preg_match('/\Aadmin-[0-9a-f]{32}\.txt\z/', $nom) === 1;
    }

    private static function chemin(string $racine, string $nom): string
    {
        return rtrim($racine, '/') . '/_config/' . $nom;
    }
}
