<?php
declare(strict_types=1);

namespace ActaLibre\Geoloc;

use ActaLibre\Db\Requete;

/**
 * Accès à la table _geoloc. LON et LAT sont des FLOAT : les versions se comparent sur leur texte (CAST AS CHAR),
 * jamais par égalité avec un paramètre décimal.
 * @phpstan-import-type Fiche from DepotFiches
 */
final class Fiches implements DepotFiches
{
    private const TOLERANCE = 1e-4;
    private readonly string $table;

    public function __construct(private readonly \mysqli $lien, string $prefixe)
    {
        $this->table = $prefixe . '_geoloc';
    }

    public function lire(int $id): ?array
    {
        $resultat = $this->executer('SELECT ID, COMMUNE, DEPART, CAST(LON AS CHAR), CAST(LAT AS CHAR), STATUT FROM ' . $this->table . ' WHERE ID = ?', [$id]);
        $ligne = $resultat instanceof \mysqli_result ? $resultat->fetch_row() : null;
        if (!is_array($ligne)) {
            return null;
        }
        return [
            'id' => (int) $ligne[0],
            'commune' => (string) $ligne[1],
            'depart' => (string) $ligne[2],
            'lon' => $ligne[3] === null ? null : (string) $ligne[3],
            'lat' => $ligne[4] === null ? null : (string) $ligne[4],
            'statut' => (string) $ligne[5],
        ];
    }

    public function remplacerSiInchangee(array $lue, string $statut, ?float $lon, ?float $lat): bool
    {
        $parametres = $lon === null || $lat === null ? [$statut] : [$statut, $lon, $lat];
        array_push($parametres, $lue['id'], $lue['statut'], $lue['lon'], $lue['lat']);
        $this->executer(
            'UPDATE ' . $this->table . ' SET STATUT = ?' . ($lon === null || $lat === null ? '' : ', LON = ?, LAT = ?')
            . ' WHERE ID = ? AND STATUT = ? AND CAST(LON AS CHAR) <=> ? AND CAST(LAT AS CHAR) <=> ?',
            $parametres
        );
        return mysqli_affected_rows($this->lien) === 1 || $this->porteDeja($lue['id'], $statut, $lon, $lat);
    }

    /** Les communes vides n'ont rien à géolocaliser ; un département NULL devient une chaîne vide. */
    public function creerManquante(?string $commune, ?string $depart): bool
    {
        if ($commune === null || trim($commune) === '') {
            return false;
        }
        $this->executer('INSERT IGNORE INTO ' . $this->table . " (COMMUNE, DEPART, LON, LAT, STATUT) VALUES (?, ?, 0, 0, 'N')", [$commune, $depart ?? '']);
        return mysqli_affected_rows($this->lien) === 1;
    }

    public function compterSansCoordonnees(): int
    {
        $resultat = $this->executer('SELECT COUNT(*) FROM ' . $this->table . " WHERE STATUT IN ('N', 'I')");
        $ligne = $resultat instanceof \mysqli_result ? $resultat->fetch_row() : null;
        return is_array($ligne) ? (int) $ligne[0] : 0;
    }

    public function estSansCoordonnees(string $commune, string $depart): bool
    {
        $resultat = $this->executer('SELECT STATUT FROM ' . $this->table . ' WHERE COMMUNE = ? AND DEPART = ?', [$commune, $depart]);
        $ligne = $resultat instanceof \mysqli_result ? $resultat->fetch_row() : null;
        return is_array($ligne) && Statut::sansCoordonnees((string) $ligne[0]);
    }

    /**
     * Enregistrement du formulaire de la fiche : refusé si la fiche a changé depuis son affichage.
     * @param array{statut:string,lon:?string,lat:?string} $version
     * @param array{N:string,M:string,D:string,V:string} $notes
     */
    public function enregistrerFormulaire(int $id, array $version, float $lon, float $lat, string $statut, array $notes): bool
    {
        $actuelle = $this->lire($id);
        if ($actuelle === null || $actuelle['statut'] !== $version['statut'] || $actuelle['lon'] !== $version['lon'] || $actuelle['lat'] !== $version['lat']) {
            return false;
        }
        $this->executer(
            'UPDATE ' . $this->table . ' SET NOTE_N = ?, NOTE_M = ?, NOTE_D = ?, NOTE_V = ?, STATUT = ?, LON = ?, LAT = ?'
            . ' WHERE ID = ? AND STATUT = ? AND CAST(LON AS CHAR) <=> ? AND CAST(LAT AS CHAR) <=> ?',
            [$notes['N'], $notes['M'], $notes['D'], $notes['V'], $statut, $lon, $lat, $id, $version['statut'], $version['lon'], $version['lat']]
        );
        return mysqli_affected_rows($this->lien) === 1 || $this->porteDeja($id, $statut, $lon, $lat);
    }

    private function porteDeja(int $id, string $statut, ?float $lon, ?float $lat): bool
    {
        $fiche = $this->lire($id);
        if ($fiche === null || $fiche['statut'] !== $statut) {
            return false;
        }
        return $lon === null || $lat === null
            || (abs((float) $fiche['lon'] - $lon) < self::TOLERANCE && abs((float) $fiche['lat'] - $lat) < self::TOLERANCE);
    }

    /** @param list<string|int|float|null> $parametres */
    private function executer(string $sql, array $parametres = []): \mysqli_result|bool
    {
        $resultat = Requete::executer($this->lien, $sql, $parametres);
        if ($resultat === false) {
            throw new \RuntimeException('Requête sur les localités impossible : ' . $this->lien->error);
        }
        return $resultat;
    }
}
