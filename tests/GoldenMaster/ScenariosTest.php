<?php
declare(strict_types=1);

namespace ActaLibre\Tests\GoldenMaster;

use PHPUnit\Framework\TestCase;

final class ScenariosTest extends TestCase
{
    private const RACINE = __DIR__ . '/../..';
    // L’installation et la mise à jour ont leurs propres parcours. Les pages de diagnostic dépendent du serveur.
    private const PAGES_EXCLUES = ['/install.php', '/update.php', '/admin/protection.php', '/admin/serv_params.php', '/admin/serv_params_accents.inc.php'];

    /** @return list<array<string,mixed>> */
    private static function scenarios(): array
    {
        return require __DIR__ . '/scenarios.php';
    }

    public function testChaquePageEstCouverte(): void
    {
        $pages = array_merge(glob(self::RACINE . '/*.php'), glob(self::RACINE . '/admin/*.php'));
        $pages = array_map(static fn (string $chemin): string => substr($chemin, strlen(self::RACINE)), $pages);
        $couvertes = array_column(self::scenarios(), 'url');
        self::assertSame([], array_values(array_diff($pages, $couvertes, self::PAGES_EXCLUES)));
    }

    public function testNomsUniques(): void
    {
        $noms = array_column(self::scenarios(), 'nom');
        self::assertSame(array_values(array_unique($noms)), $noms);
    }

    public function testProfilsConnus(): void
    {
        $profils = array_unique(array_column(self::scenarios(), 'profil'));
        self::assertSame([], array_values(array_diff($profils, ['anonyme', 'nouveau', 'niveau1', 'niveau6', 'niveau9', 'sortant', 'changepw', 'temoin'])));
    }

    public function testEcrituresEnFinDeCatalogue(): void
    {
        $noms = array_column(self::scenarios(), 'nom');
        $premiere = min(array_keys(array_filter($noms, static fn (string $nom): bool => str_starts_with($nom, 'ecriture/'))));
        foreach (array_slice($noms, $premiere) as $nom) {
            self::assertStringStartsWith('ecriture/', $nom);
        }
    }
}
