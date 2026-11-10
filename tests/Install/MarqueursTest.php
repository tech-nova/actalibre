<?php
declare(strict_types=1);

namespace ActaLibre\Tests\Install;

use ActaLibre\Install\Marqueurs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarqueursTest extends TestCase
{
    public function testLaListeContientExactementLesQuatreMarqueurs(): void
    {
        self::assertSame([
            'ACTALIBRE_VERSION',
            'ACTALIBRE_SCHEMA',
            'ACTALIBRE_INSTALLATION',
            'ACTALIBRE_MIGRATION',
        ], Marqueurs::NOMS);
        self::assertSame(Marqueurs::NOMS, array_values(array_unique(Marqueurs::NOMS)));
    }

    /** @return iterable<string,array{string,bool}> */
    public static function noms(): iterable
    {
        foreach (['ACTALIBRE_VERSION', 'ACTALIBRE_SCHEMA', 'ACTALIBRE_INSTALLATION', 'ACTALIBRE_MIGRATION'] as $nom) {
            yield $nom => [$nom, true];
        }
        foreach (['EA_VERSION', 'SITENAME', '', 'actalibre_version', 'ACTALIBRE_VERSION_SUITE', ' ACTALIBRE_VERSION', 'ACTALIBRE_VERSION '] as $nom) {
            yield 'ordinaire : ' . $nom => [$nom, false];
        }
    }

    #[DataProvider('noms')]
    public function testLaReconnaissanceEstExacte(string $nom, bool $attendu): void
    {
        self::assertSame($attendu, Marqueurs::est($nom));
    }

    public static function ressemblances(): iterable
    {
        foreach (Marqueurs::NOMS as $nom) {
            foreach ([$nom, strtolower($nom), $nom . ' ', strtolower($nom) . '  ', ' ' . $nom, "\t" . strtolower($nom) . ' '] as $i => $variante) {
                yield $nom . ' / ' . $i => [$variante, true];
            }
        }
        foreach (['EA_VERSION', 'SITENAME', '', 'ACTALIBRE_VERSION_SUITE'] as $nom) {
            yield 'ordinaire ' . $nom => [$nom, false];
        }
    }

    #[DataProvider('ressemblances')]
    public function testLesVariantesReserveesSontReconnuesAvantUneEcriture(string $nom, bool $attendu): void
    {
        self::assertSame($attendu, Marqueurs::ressemble($nom));
    }
}
