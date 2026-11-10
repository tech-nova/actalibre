<?php
declare(strict_types=1);

namespace ActaLibre\Tests\Fichier;

use ActaLibre\Fichier\Encodage;
use PHPUnit\Framework\TestCase;

final class EncodageTest extends TestCase
{
    public function testLesCaracteresConnusSontConvertis(): void
    {
        self::assertSame("D'Arc \xE9\xE8 \x86", Encodage::versWindows1252("D'Arc éè †"));
    }

    public function testUnCaractereSansEquivalentNeFaitPasPerdreLeTexte(): void
    {
        self::assertSame('Ma?gorzata', Encodage::versWindows1252('Małgorzata'));
    }
}
