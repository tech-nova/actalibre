<?php
declare(strict_types=1);

namespace ActaLibre\Tests\Import;

use ActaLibre\Import\Comparateur;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ComparateurTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, bool}> */
    public static function casComparaisons(): iterable
    {
        yield 'nombres égaux' => ['10', '=', '10.0', true];
        yield 'notation scientifique' => ['10', '=', '1e1', true];
        yield 'espaces autour d\'un nombre' => [' 12 ', '=', '12', true];
        yield 'casse distinguée' => ['abc', '=', 'ABC', false];
        yield 'zéro et vide' => ['0', '=', '', false];
        yield 'vides égaux' => ['', '=', '', true];
        yield 'supérieur numérique' => ['10', '>', '9', true];
        yield 'supérieur alphabétique' => ['abc', '>', 'abd', false];
        yield 'minuscule après majuscule' => ['abc', '>', 'ABC', true];
        yield 'inférieur numérique' => ['9', '<', '10', true];
        yield 'inférieur alphabétique' => ['Dupont', '<', 'abc', true];
        yield 'nombres non différents' => ['10', 'D', '10.0', false];
        yield 'textes différents' => ['abc', 'D', 'abd', true];
        yield 'zéro et vide différents' => ['0', 'D', '', true];
    }

    #[DataProvider('casComparaisons')]
    public function testLesComparaisons(string $a, string $code, string $b, bool $attendu): void
    {
        self::assertSame($attendu, Comparateur::compare($a, $code, $b));
    }

    public function testSuperieurEtInferieurSontInclusifs(): void
    {
        self::assertTrue(Comparateur::compare('1850', '>', '1850'));
        self::assertTrue(Comparateur::compare('1850', '<', '1850'));
        self::assertTrue(Comparateur::compare('10', '>', '9'));
    }

    public function testUneApostropheEstUnCaractereOrdinaire(): void
    {
        self::assertTrue(Comparateur::compare("L'Isle", '=', "L'Isle"));
        self::assertFalse(Comparateur::compare("L'Isle", '=', 'Lisle'));
        self::assertTrue(Comparateur::compare("L'Isle", 'D', 'Lisle'));
    }

    public function testContientEtNeContientPas(): void
    {
        self::assertTrue(Comparateur::compare('Jean Dupont', 'C', 'Dup'));
        self::assertFalse(Comparateur::compare('Jean Dupont', 'C', 'dup'));
        self::assertFalse(Comparateur::compare('Jean Dupont', 'C', ''));
        self::assertTrue(Comparateur::compare('Jean Dupont', 'P', 'Martin'));
        self::assertTrue(Comparateur::compare('Jean Dupont', 'P', ''));
        self::assertTrue(Comparateur::compare('Éloïse', 'C', 'loï'));
    }

    public function testVideEtNonVide(): void
    {
        self::assertTrue(Comparateur::compare("  \t", 'V', ''));
        self::assertFalse(Comparateur::compare(' a ', 'V', ''));
        self::assertTrue(Comparateur::compare(' a ', 'N', ''));
        self::assertFalse(Comparateur::compare('', 'N', ''));
    }

    public function testDatesRepublicaines(): void
    {
        self::assertTrue(Comparateur::compare('12 brumaire an III', 'R', ''));
        self::assertTrue(Comparateur::compare('1 VENDEMIAIRE AN 2', 'R', ''));
        self::assertFalse(Comparateur::compare('12/03/1793', 'R', ''));
        self::assertTrue(Comparateur::compare('12/03/1793', 'G', ''));
        self::assertFalse(Comparateur::compare('3 nivose an V', 'G', ''));
    }

    public function testUnCodeInconnuRenvoieFaux(): void
    {
        self::assertFalse(Comparateur::compare('a', '?', 'a'));
        self::assertFalse(Comparateur::compare('a', '', 'a'));
    }
}
