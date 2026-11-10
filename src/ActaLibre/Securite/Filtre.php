<?php
declare(strict_types=1);

namespace ActaLibre\Securite;

/** Contraint une valeur selon son usage. */
final class Filtre
{
    private const TYPES_ACTE = ['N', 'M', 'D', 'V', 'A'];

    /** Échappe un texte brut pour une page HTML, y compris dans un attribut. */
    public static function html(?string $valeur): string
    {
        return htmlspecialchars((string) $valeur, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Rend l'entier si la valeur en est un en entier, signe compris, sinon null : `12abc` ne vaut pas 12. */
    public static function entier(?string $valeur): ?int
    {
        $entier = filter_var((string) $valeur, FILTER_VALIDATE_INT);
        return $entier === false ? null : $entier;
    }

    /** Retourne le type d'acte s'il est connu, sinon une chaîne vide. */
    public static function typeActe(string $valeur): string
    {
        return in_array($valeur, self::TYPES_ACTE, true) ? $valeur : '';
    }

    /** Retourne le chemin s'il reste sur le site, sinon la valeur par défaut. */
    public static function cheminLocal(string $valeur, string $defaut): string
    {
        $externe = $valeur === ''
            || trim($valeur) !== $valeur
            || preg_match('/[\x00-\x1F\x7F]/', $valeur) === 1
            || preg_match('#^[/\\\\]{2}#', $valeur) === 1
            || preg_match('#^[a-z][a-z0-9+.-]*:#i', $valeur) === 1;

        return $externe ? $defaut : $valeur;
    }

    /** Échappe une valeur lue en base : des entités ont pu y être enregistrées, et elles restent lisibles. */
    public static function stocke(?string $valeur): string
    {
        return htmlspecialchars((string) $valeur, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
    }

    /** Rend une chaîne JavaScript, guillemets compris. */
    public static function js(?string $valeur): string
    {
        return (string) json_encode(
            (string) $valeur,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    /** Rend une adresse relative ou en http(s), sinon une chaîne vide. À échapper ensuite avec html(). */
    public static function url(?string $valeur): string
    {
        $adresse = (string) $valeur;
        if ($adresse === ''
            || trim($adresse) !== $adresse
            || !self::ligneUnique($adresse)
            || str_contains($adresse, '\\')
            || str_starts_with($adresse, '//')) {
            return '';
        }
        if (preg_match('#^https?://#i', $adresse) === 1) {
            $parties = parse_url($adresse);
            $valide = is_array($parties) && ($parties['host'] ?? '') !== '' && !isset($parties['user']) && !isset($parties['pass']);
            return $valide ? $adresse : '';
        }
        return str_contains(substr($adresse, 0, strcspn($adresse, '/?#')), ':') ? '' : $adresse;
    }

    public static function courriel(mixed $valeur): bool
    {
        return is_string($valeur) && filter_var($valeur, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** Une valeur destinée à un en-tête ne contient ni retour à la ligne ni caractère de contrôle. */
    public static function ligneUnique(string $valeur): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $valeur) !== 1;
    }
}
