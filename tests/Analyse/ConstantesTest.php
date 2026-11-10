<?php
declare(strict_types=1);

namespace ActaLibre\Tests\Analyse;

use PHPUnit\Framework\TestCase;

final class ConstantesTest extends TestCase
{
    public function testChaqueParametreEstDeclare(): void
    {
        $xml = (string) file_get_contents(__DIR__ . '/../../install/act_params.xml');
        preg_match_all('~<param>(\w+)</param>~', $xml, $parametres);
        $declarations = (string) file_get_contents(__DIR__ . '/constantes.php');
        preg_match_all("~define\('(\w+)'~", $declarations, $declarees);

        self::assertNotEmpty($parametres[1]);
        self::assertSame([], array_values(array_diff($parametres[1], $declarees[1])));
    }
}
