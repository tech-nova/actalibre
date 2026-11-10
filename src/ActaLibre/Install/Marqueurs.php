<?php
declare(strict_types=1);

namespace ActaLibre\Install;

/** Noms réservés à l'état installé, jamais chargés comme paramètres ordinaires. */
final class Marqueurs
{
    public const NOMS = [
        'ACTALIBRE_VERSION',
        'ACTALIBRE_SCHEMA',
        'ACTALIBRE_INSTALLATION',
        'ACTALIBRE_MIGRATION',
    ];

    public static function est(string $param): bool
    {
        return in_array($param, self::NOMS, true);
    }

    /** Réserve aussi les variantes normalisées par l'import (casse et blancs périphériques). */
    public static function ressemble(string $param): bool
    {
        return self::est(strtoupper(trim($param)));
    }
}
