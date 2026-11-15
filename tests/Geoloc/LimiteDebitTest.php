<?php
declare(strict_types=1);

namespace ActaLibre\Tests\Geoloc;

use ActaLibre\Carte\RefusDebit;
use ActaLibre\Geoloc\LimiteDebit;
use PHPUnit\Framework\TestCase;

final class LimiteDebitTest extends TestCase
{
    private DossierEssai $essai;

    protected function setUp(): void
    {
        $this->essai = new DossierEssai();
    }

    protected function tearDown(): void
    {
        $this->essai->supprimer();
    }

    private function limite(): LimiteDebit
    {
        return new LimiteDebit($this->essai->stockage(), $this->essai->horloge(), $this->essai->sommeil());
    }

    public function testDeuxAppelsSontEspacesDUneSecondeMemeEntreDeuxRequetesPhp(): void
    {
        self::assertSame('a', $this->limite()->executer(static fn (): string => 'a'));
        self::assertSame('b', $this->limite()->executer(static fn (): string => 'b'), 'Une autre instance partage le même débit');
        self::assertSame([1.0], $this->essai->pauses);
        $this->essai->temps += 5;
        $this->limite()->executer(static fn (): string => 'c');
        self::assertSame([1.0], $this->essai->pauses, 'Pas d’attente après un long silence');
    }

    public function testUnRefusImposeLaPauseDemandeeSansNouvelAppel(): void
    {
        try {
            $this->limite()->executer(static function (): never {
                throw new RefusDebit('trop vite', 120);
            });
            self::fail('Le refus est transmis');
        } catch (RefusDebit) {
            $this->addToAssertionCount(1);
        }
        $appels = 0;
        try {
            $this->limite()->executer(function () use (&$appels): int {
                return ++$appels;
            });
            self::fail('La pause est respectée');
        } catch (RefusDebit $refus) {
            self::assertSame(0, $appels);
            self::assertSame(120, $refus->attente);
        }
        $this->essai->temps += 121;
        self::assertSame(1, $this->limite()->executer(function () use (&$appels): int {
            return ++$appels;
        }));
    }

    public function testUnRefusSansDelaiImposeUneMinute(): void
    {
        try {
            $this->limite()->executer(static function (): never {
                throw new RefusDebit('indisponible');
            });
        } catch (RefusDebit) {
        }
        $this->essai->temps += 30;
        $this->expectException(RefusDebit::class);
        $this->limite()->executer(static fn (): int => 1);
    }

    public function testUnePanneOrdinaireNeBloquePasPlusDUneSeconde(): void
    {
        try {
            $this->limite()->executer(static function (): never {
                throw new \RuntimeException('coupure');
            });
        } catch (\RuntimeException) {
        }
        self::assertSame(1, $this->limite()->executer(static fn (): int => 1));
        self::assertSame([1.0], $this->essai->pauses);
    }
}
