<?php
declare(strict_types=1);

namespace ActaLibre\Geoloc;

/** Statuts d'une fiche de localité (champ STATUT de la table _geoloc). */
final class Statut
{
    public const NON_DEFINI = 'N';
    public const INTROUVABLE = 'I';
    public const AUTOMATIQUE = 'A';
    public const MANUEL = 'M';

    private const LIBELLES = ['N' => 'Non définie', 'I' => 'Introuvable', 'A' => 'Automatique', 'M' => 'Manuelle'];

    public static function libelle(string $statut): string
    {
        return self::LIBELLES[$statut] ?? 'Inconnue';
    }

    public static function sansCoordonnees(string $statut): bool
    {
        return $statut === self::NON_DEFINI || $statut === self::INTROUVABLE;
    }
}
