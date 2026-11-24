<?php
declare(strict_types=1);
namespace ActaLibre\Tests\Install;
use ActaLibre\Install\Avancement;
use ActaLibre\Livraison;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class AvancementTest extends TestCase
{
    public function testLeFormatEstStableEtCompareLesDeuxCibles(): void
    {
        $json='{"format":1,"nature":"expoactes","source":"3.2.4","cible":{"produit":"1.1.2","schema":"1.0.0"},"etape":"preparation","position":null}';
        $a=Avancement::commencer(Livraison::lire(dirname(__DIR__,2).'/composer.json'));
        self::assertSame($json,$a->encoder());
        self::assertSame($json,Avancement::decoder($json)->encoder());
        self::assertTrue($a->terminePour('1.1.2','1.0.0'));
        self::assertFalse($a->terminePour('9.9.9','1.0.0'));
        self::assertFalse($a->terminePour('1.1.2','1.0.1'));
    }
    public static function invalides(): iterable
    {
        yield ['pas du json']; yield ['null']; yield ['{}'];
        $base=['format'=>1,'nature'=>'expoactes','source'=>'3.2.4','cible'=>['produit'=>'1.0.0','schema'=>'1.0.0'],'etape'=>'preparation','position'=>null];
        foreach (['format'=>2,'source'=>'3.2.2','nature'=>'autre','etape'=>'inconnue','position'=>-1] as $k=>$v) yield [json_encode(array_replace($base,[$k=>$v]))];
        yield [json_encode($base+['secret'=>'mot de passe'])];
        yield [json_encode(array_replace($base,['cible'=>['produit'=>'1.0.0']]))];
        yield [json_encode(array_replace($base,['cible'=>['produit'=>'1.0.0-dev','schema'=>'1.0.0']]))];
        yield [json_encode(array_replace($base,['etape'=>'dates:actes:log:date']))];
        yield [json_encode(array_replace($base,['etape'=>'dates:actes:nai3:LADATE']))];
        yield [json_encode(array_replace($base,['position'=>1]))];
    }
    #[DataProvider('invalides')]
    public function testUneProgressionInconnueNePeutPasEtreReinitialisee(string $json): void
    { $this->expectException(\RuntimeException::class); Avancement::decoder($json); }
}
