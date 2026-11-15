<?php
declare(strict_types=1);

namespace ActaLibre\Tests\Geoloc;

use ActaLibre\Geoloc\Occupe;
use ActaLibre\Geoloc\Stockage;
use PHPUnit\Framework\TestCase;

final class StockageTest extends TestCase
{
    private string $racine;
    private Stockage $stockage;

    protected function setUp(): void
    {
        $this->racine = sys_get_temp_dir() . '/actalibre-stockage-' . bin2hex(random_bytes(4));
        mkdir($this->racine);
        $this->stockage = new Stockage($this->racine . '/geocodage');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->racine . '/geocodage/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f) || is_link($f)) {
                unlink($f);
            }
        }
        @rmdir($this->racine . '/geocodage');
        @unlink($this->racine . '/ailleurs.json');
        @rmdir($this->racine);
    }

    public function testEcrireLirePuisLister(): void
    {
        self::assertNull($this->stockage->lire('debit.json'));
        $this->stockage->ecrire('debit.json', ['pasAvant' => 12.5, 'texte' => "L'Isle & Fils"]);
        self::assertSame(['pasAvant' => 12.5, 'texte' => "L'Isle & Fils"], $this->stockage->lire('debit.json'));
        self::assertSame(0700, fileperms($this->racine . '/geocodage') & 0777);
        $nom = 'travail-' . str_repeat('a', 32) . '.json';
        $this->stockage->ecrire($nom, ['etat' => 'pret']);
        self::assertSame([$nom], $this->stockage->lister('travail-'));
        self::assertSame([], glob($this->racine . '/geocodage/.ecriture-*'), 'Aucun fichier temporaire ne reste');
    }

    public function testLesNomsHorsPolitiqueSontRefuses(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->stockage->ecrire('../intrus.php', []);
    }

    public function testUnLienSymboliqueNEstPasSuivi(): void
    {
        $this->stockage->ecrire('debit.json', []);
        unlink($this->racine . '/geocodage/debit.json');
        file_put_contents($this->racine . '/ailleurs.json', '{}');
        symlink($this->racine . '/ailleurs.json', $this->racine . '/geocodage/debit.json');
        $this->expectException(\RuntimeException::class);
        $this->stockage->lire('debit.json');
    }

    public function testUnVerrouPrisRendOccupeSansAttendre(): void
    {
        $this->stockage->ecrire('debit.json', []);
        $poignee = fopen($this->racine . '/geocodage/travaux.lock', 'c');
        flock($poignee, LOCK_EX);
        try {
            $this->stockage->verrouiller('travaux.lock', static fn (): int => 1, false);
            self::fail('Le verrou est déjà pris');
        } catch (Occupe) {
            $this->addToAssertionCount(1);
        } finally {
            flock($poignee, LOCK_UN);
            fclose($poignee);
        }
        self::assertSame(2, $this->stockage->verrouiller('travaux.lock', static fn (): int => 2, false));
    }

    public function testLaPurgeNeSupprimeQueLesFichiersAnciens(): void
    {
        $ancien = 'cache-' . str_repeat('b', 64) . '.json';
        $recent = 'travail-' . str_repeat('c', 32) . '.json';
        $this->stockage->ecrire($ancien, []);
        $this->stockage->ecrire($recent, []);
        $this->stockage->ecrire('debit.json', []);
        touch($this->racine . '/geocodage/' . $ancien, time() - 8 * 86400);
        touch($this->racine . '/geocodage/debit.json', time() - 8 * 86400);
        $this->stockage->purger(time() - 7 * 86400);
        self::assertSame([], $this->stockage->lister('cache-'));
        self::assertSame([$recent], $this->stockage->lister('travail-'));
        self::assertNotNull($this->stockage->lire('debit.json'), 'L’état du débit n’est pas purgé');
    }

    public function testUnDossierNonInscriptibleEstUneErreurExplicite(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root écrit partout');
        }
        mkdir($this->racine . '/geocodage', 0500);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('écriture');
        try {
            $this->stockage->ecrire('debit.json', []);
        } finally {
            chmod($this->racine . '/geocodage', 0700);
        }
    }
}
