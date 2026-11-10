<?php

declare (strict_types=1);
namespace ActaLibre\Install;

use ActaLibre\Compte\MotDePasse;
use ActaLibre\Db\Requete;
/** Vérification sans session, compteurs ou conversion d'empreinte. */
final class Administrateur
{
    public static function existe(\mysqli $lien, string $prefixe): bool
    {
        $table = self::table($lien, $prefixe);
        if ($table === null) {
            return false;
        }
        return (int) self::lignes($lien, "SELECT COUNT(*) AS total FROM `{$table}` WHERE level=9")[0]['total'] > 0;
    }
    public static function verifier(\mysqli $lien, string $prefixe, string $login, #[\SensitiveParameter] string $motDePasse): bool
    {
        $table = self::table($lien, $prefixe);
        if ($table === null) {
            return false;
        }
        $lignes = self::lignes($lien, "SELECT ID,login,hashpass,level FROM `{$table}` WHERE login=?", [$login]);
        if (count($lignes) !== 1) {
            MotDePasse::verifierPourRien($motDePasse);
            return false;
        }
        $ligne = $lignes[0];
        $valide = MotDePasse::verifier($motDePasse, (string) $ligne['hashpass']);
        return $valide && (string) $ligne['login'] === $login && (int) $ligne['level'] === 9;
    }
    public static function valider(string $login, string $courriel, #[\SensitiveParameter] string $motDePasse): void
    {
        if ($login === '' || strlen($login) > 15 || preg_match('/[\x00-\x1F\x7F]/', $login) === 1 || !mb_check_encoding($login, 'UTF-8') || @iconv('UTF-8', 'Windows-1252', $login) === false) {
            throw new \RuntimeException('Identifiant administrateur invalide (15 octets au maximum).');
        }
        if (strlen($courriel) > 50 || filter_var($courriel, FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException('Courriel administrateur invalide.');
        }
        if (strlen($motDePasse) < MotDePasse::LONGUEUR_MINIMALE || str_contains($motDePasse, "\x00")) {
            throw new \RuntimeException('Le mot de passe doit compter au moins ' . MotDePasse::LONGUEUR_MINIMALE . ' caractères.');
        }
    }
    public static function creer(\mysqli $lien, string $prefixe, string $login, string $courriel, #[\SensitiveParameter] string $motDePasse): void
    {
        self::valider($login, $courriel, $motDePasse);
        $table = self::table($lien, $prefixe);
        if ($table === null || self::existe($lien, $prefixe) || self::lignes($lien, "SELECT ID FROM `{$table}` WHERE login=?", [$login]) !== []) {
            throw new \RuntimeException('Création du compte administrateur refusée.');
        }
        try {
            $r = Requete::executer($lien, "INSERT INTO `{$table}` (login,hashpass,email,level) VALUES (?,?,?,9)", [$login, MotDePasse::hacher($motDePasse), $courriel]);
        } catch (\mysqli_sql_exception) {
            throw new \RuntimeException('Impossible de créer le compte administrateur.');
        }
        if ($r === false) {
            throw new \RuntimeException('Impossible de créer le compte administrateur.');
        }
    }
    /**
     * @param list<string> $parametres
     * @return list<array<string,mixed>>
     */
    private static function lignes(\mysqli $lien, string $sql, array $parametres = []): array
    {
        try {
            $r = Requete::executer($lien, $sql, $parametres);
        } catch (\mysqli_sql_exception) {
            throw new \RuntimeException('Impossible de lire le compte administrateur.');
        }
        if (!$r instanceof \mysqli_result) {
            throw new \RuntimeException('Impossible de lire le compte administrateur.');
        }
        return $r->fetch_all(MYSQLI_ASSOC);
    }
    private static function table(\mysqli $lien, string $prefixe): ?string
    {
        if (preg_match('/\A[A-Za-z0-9_]{1,55}\z/', $prefixe) !== 1) {
            throw new \InvalidArgumentException('Préfixe de tables invalide.');
        }
        $trouve = null;
        foreach (self::lignes($lien, 'SHOW TABLES') as $ligne) {
            $table = (string) array_values($ligne)[0];
            if (strcasecmp($table, $prefixe . '_user3') !== 0) {
                continue;
            }
            if ($trouve !== null) {
                throw new \RuntimeException('Tables de comptes incohérentes.');
            }
            $trouve = $table;
        }
        return $trouve;
    }
}
