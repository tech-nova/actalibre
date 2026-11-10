<?php
declare(strict_types=1);

namespace ActaLibre\Import;

/**
 * Filtre de chargement CSV : compare la valeur d'un champ à la valeur saisie,
 * selon les codes de $trait['TST'] (tools/traitements.inc.php).
 */
final class Comparateur
{
    private const MOIS_REPUBLICAINS = ['VEN', 'BRU', 'FRI', 'NIV', 'GER', 'FLO', 'PRA', 'MES', 'TER', 'THE', 'FRU', 'COM'];

    public static function compare(string $a, string $code, string $b): bool
    {
        // Comparaisons souples volontaires : numériques quand les deux chaînes sont numériques.
        return match ($code) {
            '=' => $a == $b,
            '>' => $a >= $b,
            '<' => $a <= $b,
            'D' => $a != $b,
            'C' => self::contient($a, $b),
            'P' => !self::contient($a, $b),
            'V' => trim($a) === '',
            'N' => trim($a) !== '',
            'R' => self::estDateRepublicaine($a),
            'G' => !self::estDateRepublicaine($a),
            default => false,
        };
    }

    /** Même règle que isin() : une valeur cherchée vide n'est jamais contenue. */
    private static function contient(string $texte, string $cherche): bool
    {
        return $cherche !== '' && mb_strpos($texte, $cherche) !== false;
    }

    private static function estDateRepublicaine(string $date): bool
    {
        $majuscules = strtoupper($date);
        foreach (self::MOIS_REPUBLICAINS as $mois) {
            if (str_contains($majuscules, $mois)) {
                return true;
            }
        }
        return false;
    }
}
