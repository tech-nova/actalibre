<?php
declare(strict_types=1);

namespace ActaLibre\Geoloc;

final class Resultat
{
    public function __construct(public readonly Issue $issue, public readonly string $libelle, public readonly bool $depuisCache = false)
    {
    }
}
