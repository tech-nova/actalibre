<?php
declare(strict_types=1);
namespace ActaLibre\Install;

/** Liens d’un site ; aucun état d’exploitation mémorisé. */
final class ConnexionsSite
{
    private bool $ferme = false;
    public function __construct(
        public readonly ConfigurationSite $configuration,
        public readonly \mysqli $actes,
        public readonly \mysqli $comptes,
    ) {}
    public function fermer(): void
    {
        if ($this->ferme) return;
        $this->ferme = true;
        if ($this->comptes !== $this->actes) $this->comptes->close();
        $this->actes->close();
    }
}
