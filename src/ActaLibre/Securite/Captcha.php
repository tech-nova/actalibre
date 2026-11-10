<?php
declare(strict_types=1);

namespace ActaLibre\Securite;

/** Le code attendu reste en session sous forme d'empreinte et ne sert qu'à une seule vérification. */
final class Captcha
{
    public const CLE = 'valeur_image';

    public static function disponible(): bool
    {
        return function_exists('imagettftext');
    }

    public static function verifier(mixed $saisie, ?bool $disponible = null): bool
    {
        $attendu = $_SESSION[self::CLE] ?? null;
        unset($_SESSION[self::CLE]);
        if (!($disponible ?? self::disponible())) {
            return false;
        }
        return is_string($attendu) && is_string($saisie) && $saisie !== '' && hash_equals($attendu, hash('sha256', $saisie));
    }
}
