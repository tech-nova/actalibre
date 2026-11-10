<?php
declare(strict_types=1);

namespace ActaLibre\Fichier;

final class Encodage
{
    /** Un caractère sans équivalent devient « ? » : la ligne n'est pas perdue. */
    public static function versWindows1252(string $texte): string
    {
        $avant = mb_substitute_character();
        mb_substitute_character(0x3F);
        $resultat = mb_convert_encoding($texte, 'Windows-1252', 'UTF-8');
        mb_substitute_character($avant);
        return $resultat;
    }
}
