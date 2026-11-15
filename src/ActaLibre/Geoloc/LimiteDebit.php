<?php
declare(strict_types=1);

namespace ActaLibre\Geoloc;

use ActaLibre\Carte\RefusDebit;

/**
 * Un appel au plus par intervalle pour tout le site : l'heure permise est dans debit.json, sous le verrou debit.lock,
 * tenu pendant l'attente et l'appel. Une pause demandée par le service (429, 503) est respectée sans nouvel appel.
 */
final class LimiteDebit
{
    private const PAUSE_PAR_DEFAUT = 60;

    /**
     * @param \Closure(): float $maintenant
     * @param \Closure(float): void $attendre
     */
    public function __construct(
        private readonly Stockage $stockage,
        private readonly \Closure $maintenant,
        private readonly \Closure $attendre,
        private readonly float $intervalle = 1.0
    ) {
    }

    /**
     * @template T
     * @param callable(): T $appel
     * @return T
     */
    public function executer(callable $appel): mixed
    {
        return $this->stockage->verrouiller('debit.lock', function () use ($appel): mixed {
            $pasAvant = (float) ($this->stockage->lire('debit.json')['pasAvant'] ?? 0);
            $reste = $pasAvant - ($this->maintenant)();
            if ($reste > $this->intervalle) {
                throw new RefusDebit('Le service de géocodage a demandé une pause.', (int) ceil($reste));
            }
            if ($reste > 0) {
                ($this->attendre)($reste);
            }
            $suivant = null;
            try {
                return $appel();
            } catch (RefusDebit $refus) {
                $suivant = ($this->maintenant)() + max($this->intervalle, (float) ($refus->attente ?? self::PAUSE_PAR_DEFAUT));
                throw $refus;
            } finally {
                $this->stockage->ecrire('debit.json', ['pasAvant' => $suivant ?? ($this->maintenant)() + $this->intervalle]);
            }
        }, true);
    }
}
