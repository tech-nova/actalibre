<?php
declare(strict_types=1);

namespace ActaLibre\Securite;

use ActaLibre\Compte\Session;

/** Jeton des écritures : dérivé de la session, il ne demande aucun stockage. */
final class Jeton
{
    public const CHAMP = 'jeton';

    /** Chaîne vide sans session. */
    public static function valeur(?string $session = null): string
    {
        $session ??= $_COOKIE[Session::COOKIE] ?? '';
        if (!is_string($session) || $session === '') {
            return '';
        }
        return hash_hmac('sha256', 'ecriture', $session);
    }

    public static function champ(): string
    {
        return '<input type="hidden" name="' . self::CHAMP . '" value="' . self::valeur() . '" />' . "\n";
    }

    public static function lien(): string
    {
        return self::CHAMP . '=' . self::valeur();
    }

    public static function verifier(): bool
    {
        $attendu = self::valeur();
        $recu = $_POST[self::CHAMP] ?? $_GET[self::CHAMP] ?? '';
        return $attendu !== '' && is_string($recu) && hash_equals($attendu, $recu);
    }
}
