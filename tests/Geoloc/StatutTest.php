<?php
declare(strict_types=1);

namespace ActaLibre\Tests\Geoloc;

use ActaLibre\Geoloc\Statut;
use PHPUnit\Framework\TestCase;

final class StatutTest extends TestCase
{
    public function testChaqueStatutAUnLibelle(): void
    {
        self::assertSame('Non définie', Statut::libelle('N'));
        self::assertSame('Introuvable', Statut::libelle('I'));
        self::assertSame('Automatique', Statut::libelle('A'));
        self::assertSame('Manuelle', Statut::libelle('M'));
        self::assertSame('Inconnue', Statut::libelle('X'));
    }

    public function testSeulsNEtISontSansCoordonnees(): void
    {
        self::assertTrue(Statut::sansCoordonnees('N'));
        self::assertTrue(Statut::sansCoordonnees('I'));
        self::assertFalse(Statut::sansCoordonnees('A'));
        self::assertFalse(Statut::sansCoordonnees('M'));
    }
}
