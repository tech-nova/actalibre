<?php
declare(strict_types=1);

namespace ActaLibre\Tests\GoldenMaster;

final class Reponse
{
    public function __construct(
        public readonly int $statut,
        public readonly string $entetes,
        public readonly string $corps
    ) {
    }

    /** Statut, Location et Content-Type, puis le corps : le format des captures. */
    public function capture(): string
    {
        $lignes = ['HTTP ' . $this->statut];
        foreach (['Location', 'Content-Type'] as $nom) {
            if (preg_match('/^' . $nom . ':\s*(.*?)\s*$/mi', $this->entetes, $trouve) === 1) {
                $lignes[] = $nom . ': ' . $trouve[1];
            }
        }
        return implode("\n", $lignes) . "\n\n" . $this->corps;
    }
}
