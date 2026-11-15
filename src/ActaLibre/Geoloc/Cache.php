<?php
declare(strict_types=1);

namespace ActaLibre\Geoloc;

/**
 * Réponses récentes du fournisseur, pour reprendre un travail sans nouvel appel. Le cache durable des lieux trouvés
 * est la table _geoloc elle-même. Les pannes ne sont jamais mémorisées.
 */
final class Cache
{
    public const DUREE_TROUVE = 7 * 86400;
    public const DUREE_ABSENT = 86400;
    private const OPTIONS = 'format=jsonv2&limit=1&accept-language=fr';

    /** @param \Closure(): float $maintenant */
    public function __construct(private readonly Stockage $stockage, private readonly \Closure $maintenant, private readonly string $fournisseur)
    {
    }

    /** @return array{trouve:bool,lon:float,lat:float}|null null si la requête n'est pas en cache ou a expiré */
    public function lire(string $requete): ?array
    {
        $entree = $this->stockage->lire($this->nom($requete));
        if ($entree === null || !is_numeric($entree['expire'] ?? null) || (float) $entree['expire'] <= ($this->maintenant)()) {
            return null;
        }
        return ['trouve' => (bool) ($entree['trouve'] ?? false), 'lon' => (float) ($entree['lon'] ?? 0), 'lat' => (float) ($entree['lat'] ?? 0)];
    }

    /** @param array{lon:float,lat:float}|null $coordonnees null : lieu introuvable */
    public function ecrire(string $requete, ?array $coordonnees): void
    {
        $this->stockage->ecrire($this->nom($requete), [
            'expire' => ($this->maintenant)() + ($coordonnees === null ? self::DUREE_ABSENT : self::DUREE_TROUVE),
            'trouve' => $coordonnees !== null,
            'lon' => $coordonnees['lon'] ?? null,
            'lat' => $coordonnees['lat'] ?? null,
        ]);
    }

    private function nom(string $requete): string
    {
        return 'cache-' . hash('sha256', $this->fournisseur . "\n" . $requete . "\n" . self::OPTIONS) . '.json';
    }
}
