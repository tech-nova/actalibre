<?php
declare(strict_types=1);

namespace ActaLibre\Securite;

/** Choisit ce qu'un compte peut lire d'une erreur de la base. */
final class ErreurSql
{
    public const GENERIQUE = 'Une erreur est survenue. Merci de réessayer plus tard.';
    private const NIVEAU_DETAIL = 9;

    public static function message(string $erreur, string $requete, int $niveau): string
    {
        if ($niveau < self::NIVEAU_DETAIL) {
            return '<p>' . self::GENERIQUE . '</p>';
        }
        return '<p>' . Filtre::html($erreur) . '<br />' . Filtre::html($requete) . '</p>';
    }
}
