<?php
declare(strict_types=1);

namespace ActaLibre\Carte;

/** Le service demande de patienter (HTTP 429 ou 503) ; $attente vient de Retry-After, en secondes. */
final class RefusDebit extends \RuntimeException
{
    public function __construct(string $message, public readonly ?int $attente = null)
    {
        parent::__construct($message);
    }
}
