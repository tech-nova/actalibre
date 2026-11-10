<?php
declare(strict_types=1);

namespace ActaLibre\Securite;

/** Texte tiré au hasard, sans caractère que l'on confond à la lecture. */
final class Hasard
{
    private const ALPHABET = 'abcdefghkmnprstuvwxyz23456789';

    /** @param positive-int $longueur */
    public static function texte(int $longueur): string
    {
        $texte = '';
        for ($i = 0; $i < $longueur; $i++) {
            $texte .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        return $texte;
    }
}
