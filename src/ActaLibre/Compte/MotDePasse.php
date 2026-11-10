<?php
declare(strict_types=1);

namespace ActaLibre\Compte;

/** Seul endroit qui hache et vérifie un mot de passe. */
final class MotDePasse
{
    public const LONGUEUR_MINIMALE = 6;
    /** Empreinte d'une valeur aléatoire oubliée aussitôt : aucun mot de passe ne lui correspond. */
    private const EMPREINTE_FACTICE = '$2y$12$yAbiy9AsphX0f9nEYrlS.uFMgG3MCCPM892HosNcXZjyVctNDbIZG';

    public static function hacher(string $motDePasse): string
    {
        return password_hash(sha1($motDePasse), PASSWORD_DEFAULT);
    }

    /** Une sauvegarde contient une valeur déjà hachée, un fichier préparé à la main un mot de passe. */
    public static function depuisImport(string $valeur): string
    {
        if (self::estMigre($valeur) || self::estAncienFormat($valeur)) {
            return self::migrer($valeur);
        }
        return self::hacher($valeur);
    }

    public static function migrer(string $stocke): string
    {
        if (!self::estAncienFormat($stocke)) {
            return $stocke;
        }
        return password_hash(strtolower($stocke), PASSWORD_DEFAULT);
    }

    public static function verifier(string $motDePasse, string $stocke): bool
    {
        foreach (self::empreintes($motDePasse) as $empreinte) {
            if (self::estMigre($stocke) && password_verify($empreinte, $stocke)) {
                return true;
            }
            if (self::estAncienFormat($stocke) && hash_equals(strtolower($stocke), $empreinte)) {
                return true;
            }
        }
        return false;
    }

    /** Un identifiant inconnu prend le même temps qu'un mot de passe faux. */
    public static function verifierPourRien(string $motDePasse): void
    {
        self::verifier($motDePasse, self::EMPREINTE_FACTICE);
    }

    /** L'ancien format est réécrit par la migration : la colonne peut encore être trop courte. */
    public static function aRehacher(string $motDePasse, string $stocke): bool
    {
        if (!self::estMigre($stocke)) {
            return false;
        }
        return password_needs_rehash($stocke, PASSWORD_DEFAULT) || !password_verify(sha1($motDePasse), $stocke);
    }

    public static function estAncienFormat(string $stocke): bool
    {
        return preg_match('/^[0-9a-f]{40}$/i', $stocke) === 1;
    }

    /** Sans passer par getparam(), qui lit aussi l'adresse et filtre le contenu. */
    public static function lirePost(string $champ): string
    {
        $valeur = $_POST[$champ] ?? '';
        return is_string($valeur) ? $valeur : '';
    }

    private static function estMigre(string $stocke): bool
    {
        return password_get_info($stocke)['algo'] !== null;
    }

    /** @return list<string> */
    private static function empreintes(string $motDePasse): array
    {
        $empreintes = [sha1($motDePasse)];
        if (preg_match('/[^\x00-\x7F]/', $motDePasse) === 1 && mb_check_encoding($motDePasse, 'UTF-8')) {
            // L'ancien script du navigateur ne gardait que 8 bits par caractère.
            $octets = '';
            foreach (mb_str_split($motDePasse, 1, 'UTF-8') as $caractere) {
                $octets .= chr(mb_ord($caractere, 'UTF-8') & 0xFF);
            }
            $empreintes[] = sha1($octets);
        }
        return $empreintes;
    }
}
