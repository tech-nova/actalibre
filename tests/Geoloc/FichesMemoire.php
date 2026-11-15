<?php
declare(strict_types=1);

namespace ActaLibre\Tests\Geoloc;

use ActaLibre\Geoloc\DepotFiches;

/** Dépôt en mémoire qui reproduit l'écriture conditionnelle de Fiches. */
final class FichesMemoire implements DepotFiches
{
    /** @var array<int,array{id:int,commune:string,depart:string,lon:?string,lat:?string,statut:string}> */
    public array $fiches = [];

    public function ajouter(int $id, string $commune, string $depart, ?string $lon, ?string $lat, string $statut): void
    {
        $this->fiches[$id] = ['id' => $id, 'commune' => $commune, 'depart' => $depart, 'lon' => $lon, 'lat' => $lat, 'statut' => $statut];
    }

    public function lire(int $id): ?array
    {
        return $this->fiches[$id] ?? null;
    }

    public function remplacerSiInchangee(array $lue, string $statut, ?float $lon, ?float $lat): bool
    {
        $actuelle = $this->fiches[$lue['id']] ?? null;
        if ($actuelle === null) {
            return false;
        }
        $cible = $actuelle;
        $cible['statut'] = $statut;
        if ($lon !== null && $lat !== null) {
            $cible['lon'] = (string) $lon;
            $cible['lat'] = (string) $lat;
        }
        if ($actuelle === $cible) {
            return true;
        }
        if ($actuelle !== $lue) {
            return false;
        }
        $this->fiches[$lue['id']] = $cible;
        return true;
    }
}
