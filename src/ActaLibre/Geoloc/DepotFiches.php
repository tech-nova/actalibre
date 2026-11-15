<?php
declare(strict_types=1);

namespace ActaLibre\Geoloc;

/**
 * Ce dont le moteur a besoin pour une fiche de localité.
 * @phpstan-type Fiche array{id:int,commune:string,depart:string,lon:?string,lat:?string,statut:string}
 */
interface DepotFiches
{
    /** @return Fiche|null */
    public function lire(int $id): ?array;

    /**
     * Écrit le statut, et les coordonnées si elles sont données, seulement si la fiche est encore celle qui a été lue.
     * Réussit aussi quand la fiche porte déjà exactement ce résultat (reprise après une interruption).
     * @param Fiche $lue
     */
    public function remplacerSiInchangee(array $lue, string $statut, ?float $lon, ?float $lat): bool;
}
