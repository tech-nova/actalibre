<?php
declare(strict_types=1);

namespace ActaLibre\Geoloc;

use ActaLibre\Db\Requete;

/**
 * Recense les couples COMMUNE/DEPART des quatre tables d'actes, par tranches de communes, sans dépendre de _sums.
 * Les index sur préfixes imposent des parcours complets : chaque appel reste court et rend son curseur.
 */
final class Recensement
{
    public const TABLES = ['_nai3', '_mar3', '_dec3', '_div3'];

    public function __construct(private readonly \mysqli $lien, private readonly string $prefixe, private readonly Fiches $fiches)
    {
    }

    /** @return array{table:int,apres:string,crees:int,termine:bool} */
    public function tranche(int $table, string $apres, int $limite = 200): array
    {
        if ($table < 0 || $table >= count(self::TABLES)) {
            return ['table' => count(self::TABLES), 'apres' => '', 'crees' => 0, 'termine' => true];
        }
        $nom = $this->prefixe . self::TABLES[$table];
        $communes = [];
        foreach ($this->lignes("SELECT DISTINCT COMMUNE FROM $nom WHERE COMMUNE > ? ORDER BY COMMUNE LIMIT ?", [$apres, max(1, $limite)]) as $ligne) {
            $communes[] = (string) $ligne[0];
        }
        if ($communes === []) {
            return ['table' => $table + 1, 'apres' => '', 'crees' => 0, 'termine' => $table + 1 >= count(self::TABLES)];
        }
        $derniere = $communes[count($communes) - 1];
        $crees = 0;
        foreach ($this->lignes("SELECT DISTINCT COMMUNE, DEPART FROM $nom WHERE COMMUNE >= ? AND COMMUNE <= ?", [$communes[0], $derniere]) as $ligne) {
            if ($this->fiches->creerManquante($ligne[0] === null ? null : (string) $ligne[0], $ligne[1] === null ? null : (string) $ligne[1])) {
                $crees++;
            }
        }
        return ['table' => $table, 'apres' => $derniere, 'crees' => $crees, 'termine' => false];
    }

    /**
     * @param list<string|int> $parametres
     * @return list<array<int,mixed>>
     */
    private function lignes(string $sql, array $parametres): array
    {
        $resultat = Requete::executer($this->lien, $sql, $parametres);
        if (!$resultat instanceof \mysqli_result) {
            throw new \RuntimeException('Recensement des localités impossible : ' . $this->lien->error);
        }
        return $resultat->fetch_all();
    }
}
