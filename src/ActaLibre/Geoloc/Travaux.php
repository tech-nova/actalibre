<?php
declare(strict_types=1);

namespace ActaLibre\Geoloc;

use ActaLibre\Carte\RefusDebit;

/**
 * Travaux de géolocalisation persistants : sélection figée, un lieu par avancement, reprise après suspension.
 * Un seul avancement à la fois pour tout le site (verrou travaux.lock, sans attente).
 */
final class Travaux
{
    public const EXPIRATION = 7 * 86400;
    public const MAX_IDS = 1000;
    private const VERROU = 'travaux.lock';
    private const PAUSE_PAR_DEFAUT = 60;

    /** @param \Closure(): float $maintenant */
    public function __construct(private readonly Stockage $stockage, private readonly \Closure $maintenant, private readonly string $portee)
    {
    }

    /** @param array<array-key,mixed> $ids identifiants de fiches _geoloc (entiers positifs) */
    public function creer(int $proprietaire, array $ids): string
    {
        $propres = [];
        foreach ($ids as $valeur) {
            if (!(is_int($valeur) || (is_string($valeur) && ctype_digit($valeur))) || (int) $valeur < 1) {
                throw new \InvalidArgumentException('Identifiant de localité invalide.');
            }
            $propres[(int) $valeur] = true;
        }
        if ($propres === []) {
            throw new \InvalidArgumentException('Aucune localité sélectionnée.');
        }
        if (count($propres) > self::MAX_IDS) {
            throw new \InvalidArgumentException('Au plus ' . self::MAX_IDS . ' localités par mise à jour.');
        }
        $maintenant = ($this->maintenant)();
        $this->stockage->purger($maintenant - self::EXPIRATION);
        $id = bin2hex(random_bytes(16));
        $this->stockage->ecrire(self::nom($id), [
            'id' => $id, 'proprietaire' => $proprietaire, 'portee' => $this->portee,
            'cree' => $maintenant, 'maj' => $maintenant, 'etat' => 'pret', 'cause' => null, 'reprise' => null,
            'ids' => array_keys($propres), 'resultats' => [], 'dernier' => null,
        ]);
        return $id;
    }

    /** @return array<string,mixed> */
    public function lire(string $id, int $proprietaire): array
    {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $id) !== 1) {
            throw new TravailIntrouvable('Travail introuvable.');
        }
        $travail = $this->stockage->lire(self::nom($id));
        if ($travail === null || ($travail['proprietaire'] ?? null) !== $proprietaire || ($travail['portee'] ?? null) !== $this->portee) {
            throw new TravailIntrouvable('Travail introuvable.');
        }
        if ($travail['etat'] !== 'termine' && ($this->maintenant)() - (float) $travail['maj'] > self::EXPIRATION) {
            $travail['etat'] = 'expire';
        }
        return $travail;
    }

    /** @return array<string,mixed> vue du travail après au plus un lieu traité */
    public function avancer(string $id, int $proprietaire, Moteur $moteur): array
    {
        return $this->stockage->verrouiller(self::VERROU, function () use ($id, $proprietaire, $moteur): array {
            $travail = $this->lire($id, $proprietaire);
            if (!in_array($travail['etat'], ['pret', 'actif'], true)) {
                return self::vue($travail);
            }
            $suivant = self::suivant($travail);
            if ($suivant !== null) {
                $travail['etat'] = 'actif';
                try {
                    $resultat = $moteur->traiter($suivant);
                    $travail['resultats'][$suivant] = $resultat->issue->value;
                    $travail['dernier'] = ['id' => $suivant, 'libelle' => $resultat->libelle, 'issue' => $resultat->issue->value, 'cache' => $resultat->depuisCache];
                } catch (RefusDebit $refus) {
                    $travail['etat'] = 'suspendu';
                    $travail['cause'] = 'debit';
                    $travail['reprise'] = ($this->maintenant)() + ($refus->attente ?? self::PAUSE_PAR_DEFAUT);
                } catch (\RuntimeException) {
                    $travail['etat'] = 'suspendu';
                    $travail['cause'] = 'panne';
                }
            }
            if (self::suivant($travail) === null) {
                $travail['etat'] = 'termine';
            }
            return $this->enregistrer($travail);
        }, false);
    }

    /** @return array<string,mixed> */
    public function suspendre(string $id, int $proprietaire): array
    {
        return $this->stockage->verrouiller(self::VERROU, function () use ($id, $proprietaire): array {
            $travail = $this->lire($id, $proprietaire);
            if (!in_array($travail['etat'], ['pret', 'actif'], true)) {
                return self::vue($travail);
            }
            $travail['etat'] = 'suspendu';
            $travail['cause'] = 'pause';
            return $this->enregistrer($travail);
        }, true);
    }

    /** @return array<string,mixed> */
    public function reprendre(string $id, int $proprietaire): array
    {
        return $this->stockage->verrouiller(self::VERROU, function () use ($id, $proprietaire): array {
            $travail = $this->lire($id, $proprietaire);
            if (!in_array($travail['etat'], ['suspendu', 'pret', 'actif'], true)) {
                return self::vue($travail);
            }
            $travail['etat'] = 'pret';
            $travail['cause'] = null;
            return $this->enregistrer($travail);
        }, true);
    }

    /** @return array<string,mixed>|null le travail inachevé le plus récent de ce compte */
    public function dernierActif(int $proprietaire): ?array
    {
        $choisi = null;
        foreach ($this->stockage->lister('travail-') as $nom) {
            try {
                $travail = $this->lire(substr($nom, 8, 32), $proprietaire);
            } catch (TravailIntrouvable) {
                continue;
            }
            if (!in_array($travail['etat'], ['termine', 'expire'], true) && ($choisi === null || $travail['maj'] > $choisi['maj'])) {
                $choisi = $travail;
            }
        }
        return $choisi;
    }

    /**
     * @param array<string,mixed> $travail
     * @return array<string,mixed>
     */
    public static function vue(array $travail): array
    {
        $compteurs = ['trouvee' => 0, 'introuvable' => 0, 'manuelle' => 0, 'a_verifier' => 0];
        foreach ($travail['resultats'] as $issue) {
            $compteurs[Issue::from($issue)->compteur()]++;
        }
        return [
            'travail' => $travail['id'], 'etat' => $travail['etat'], 'cause' => $travail['cause'], 'reprise' => $travail['reprise'],
            'total' => count($travail['ids']), 'traites' => count($travail['resultats']), 'compteurs' => $compteurs, 'dernier' => $travail['dernier'],
        ];
    }

    /**
     * @param array<string,mixed> $travail
     * @return array<string,mixed>
     */
    private function enregistrer(array $travail): array
    {
        $travail['maj'] = ($this->maintenant)();
        $this->stockage->ecrire(self::nom($travail['id']), $travail);
        return self::vue($travail);
    }

    /** @param array<string,mixed> $travail */
    private static function suivant(array $travail): ?int
    {
        foreach ($travail['ids'] as $id) {
            if (!isset($travail['resultats'][$id])) {
                return (int) $id;
            }
        }
        return null;
    }

    private static function nom(string $id): string
    {
        return 'travail-' . $id . '.json';
    }
}
