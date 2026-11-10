<?php
declare(strict_types=1);

namespace ActaLibre\Db;

/** Exécute une requête préparée sur un lien mysqli existant. */
final class Requete
{
    // Sa fermeture effacerait du lien le message d'erreur et le nombre de lignes modifiées.
    /** @phpstan-ignore property.onlyWritten (seul son maintien en vie compte) */
    private static ?\mysqli_stmt $derniere = null;

    /**
     * @param list<string|int|float|null> $parametres valeurs des marqueurs `?`, dans l'ordre
     * @return \mysqli_result|bool résultat d'un SELECT, true pour une écriture réussie, false en cas d'erreur
     */
    public static function executer(\mysqli $lien, string $sql, array $parametres = []): \mysqli_result|bool
    {
        if (!extension_loaded('mysqlnd')) {
            throw new \RuntimeException('Le pilote mysqlnd est requis.');
        }

        self::$derniere = null;
        $requete = $lien->prepare($sql);
        if ($requete === false) {
            return false;
        }
        self::$derniere = $requete;

        if ($requete->param_count !== count($parametres)) {
            throw new \InvalidArgumentException(
                sprintf('%d marqueurs pour %d valeurs.', $requete->param_count, count($parametres))
            );
        }
        if ($parametres !== []) {
            $requete->bind_param(self::types($parametres), ...$parametres);
        }
        if (!$requete->execute()) {
            return false;
        }

        $resultat = $requete->get_result();
        return $resultat instanceof \mysqli_result ? $resultat : $requete->errno === 0;
    }

    /** @param list<string|int|float|null> $parametres */
    private static function types(array $parametres): string
    {
        $types = '';
        foreach ($parametres as $valeur) {
            $types .= is_int($valeur) ? 'i' : (is_float($valeur) ? 'd' : 's');
        }
        return $types;
    }
}
