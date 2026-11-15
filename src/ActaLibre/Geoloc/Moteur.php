<?php
declare(strict_types=1);

namespace ActaLibre\Geoloc;

/**
 * Règles d'enregistrement des coordonnées (spécification, §8 et §10). Le moteur ne connaît ni le tableau ni le navigateur.
 * @phpstan-import-type Fiche from DepotFiches
 */
final class Moteur
{
    private const EGALITE = 1e-9;

    /** @param \Closure(string): (array{lon:float,lat:float}|null) $chercher lève une \RuntimeException en cas de panne */
    public function __construct(
        private readonly DepotFiches $fiches,
        private readonly Cache $cache,
        private readonly LimiteDebit $limite,
        private readonly \Closure $chercher
    ) {
    }

    /** Texte envoyé au fournisseur : les libellés historiques sont décodés, leur stockage n'est pas modifié. */
    public static function requete(string $commune, string $depart): string
    {
        $commune = trim(html_entity_decode($commune, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $depart = trim(html_entity_decode($depart, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return $depart === '' ? $commune : $commune . ', ' . $depart;
    }

    public static function valides(float $lon, float $lat): bool
    {
        return is_finite($lon) && is_finite($lat) && $lon >= -180 && $lon <= 180 && $lat >= -90 && $lat <= 90;
    }

    /**
     * @return array{0: array{lon:float,lat:float}|null, 1: bool} coordonnées (null : introuvable) et provenance du cache
     * @throws \RuntimeException panne, refus de débit ou réponse invalide : rien n'est mis en cache
     */
    public function rechercher(string $requete): array
    {
        $memorise = $this->cache->lire($requete);
        if ($memorise !== null) {
            return [$memorise['trouve'] ? ['lon' => $memorise['lon'], 'lat' => $memorise['lat']] : null, true];
        }
        $coordonnees = $this->limite->executer(fn (): ?array => ($this->chercher)($requete));
        if ($coordonnees !== null && !self::valides($coordonnees['lon'], $coordonnees['lat'])) {
            throw new \RuntimeException('Le service de géocodage a rendu des coordonnées hors bornes.');
        }
        $this->cache->ecrire($requete, $coordonnees);
        return [$coordonnees, false];
    }

    /** Traitement multiple d'une fiche. @throws \RuntimeException en cas de panne : la fiche n'est pas modifiée */
    public function traiter(int $id): Resultat
    {
        $fiche = $this->fiches->lire($id);
        if ($fiche === null) {
            return new Resultat(Issue::Supprimee, '#' . $id);
        }
        $libelle = self::libelle($fiche);
        if ($fiche['statut'] === Statut::MANUEL) {
            return new Resultat(Issue::ManuelleConservee, $libelle);
        }
        [$coordonnees, $depuisCache] = $this->rechercher(self::requete($fiche['commune'], $fiche['depart']));
        if ($coordonnees !== null) {
            return $this->fiches->remplacerSiInchangee($fiche, Statut::AUTOMATIQUE, $coordonnees['lon'], $coordonnees['lat'])
                ? new Resultat(Issue::Trouvee, $libelle, $depuisCache)
                : new Resultat($this->echec($id), $libelle, $depuisCache);
        }
        if ($fiche['statut'] === Statut::AUTOMATIQUE) {
            return new Resultat(Issue::SansNouveauResultat, $libelle, $depuisCache);
        }
        return $this->fiches->remplacerSiInchangee($fiche, Statut::INTROUVABLE, null, null)
            ? new Resultat(Issue::Introuvable, $libelle, $depuisCache)
            : new Resultat($this->echec($id), $libelle, $depuisCache);
    }

    /**
     * Recherche depuis la fiche : enregistre pour une fiche N, I ou A ; seulement propose pour une fiche M.
     * @return array{issue:string, cache?:bool, fiche?:Fiche|null, proposition?:array{lon:float,lat:float}|null}
     */
    public function rechercherPourFiche(int $id): array
    {
        $fiche = $this->fiches->lire($id);
        if ($fiche === null) {
            return ['issue' => Issue::Supprimee->value];
        }
        if ($fiche['statut'] !== Statut::MANUEL) {
            $resultat = $this->traiter($id);
            return ['issue' => $resultat->issue->value, 'cache' => $resultat->depuisCache, 'fiche' => $this->fiches->lire($id)];
        }
        [$coordonnees, $depuisCache] = $this->rechercher(self::requete($fiche['commune'], $fiche['depart']));
        return [
            'issue' => $coordonnees === null ? Issue::Introuvable->value : 'proposition',
            'cache' => $depuisCache,
            'fiche' => $fiche,
            'proposition' => $coordonnees,
        ];
    }

    /**
     * Validation explicite du remplacement d'une fiche M : les coordonnées viennent du cache du serveur, pas du navigateur.
     * @param array{statut:string,lon:?string,lat:?string} $version fiche telle qu'elle était affichée
     * @return array{issue:string, fiche?:Fiche|null}
     * @throws \InvalidArgumentException si le serveur n'a pas obtenu ces coordonnées
     */
    public function remplacerManuelle(int $id, array $version, float $lon, float $lat): array
    {
        $fiche = $this->fiches->lire($id);
        if ($fiche === null) {
            return ['issue' => Issue::Supprimee->value];
        }
        if ($fiche['statut'] !== $version['statut'] || $fiche['lon'] !== $version['lon'] || $fiche['lat'] !== $version['lat']) {
            return ['issue' => Issue::Modifiee->value, 'fiche' => $fiche];
        }
        [$coordonnees] = $this->rechercher(self::requete($fiche['commune'], $fiche['depart']));
        if ($coordonnees === null || abs($coordonnees['lon'] - $lon) > self::EGALITE || abs($coordonnees['lat'] - $lat) > self::EGALITE) {
            throw new \InvalidArgumentException('Ce résultat n’est plus disponible : relancez la recherche.');
        }
        $ecrit = $this->fiches->remplacerSiInchangee($fiche, Statut::AUTOMATIQUE, $coordonnees['lon'], $coordonnees['lat']);
        return ['issue' => $ecrit ? Issue::Trouvee->value : $this->echec($id)->value, 'fiche' => $this->fiches->lire($id)];
    }

    private function echec(int $id): Issue
    {
        return $this->fiches->lire($id) === null ? Issue::Supprimee : Issue::Modifiee;
    }

    /** @param Fiche $fiche */
    private static function libelle(array $fiche): string
    {
        $commune = html_entity_decode($fiche['commune'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $depart = html_entity_decode($fiche['depart'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return $depart === '' ? $commune : $commune . ' [' . $depart . ']';
    }
}
