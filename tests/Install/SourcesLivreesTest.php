<?php
declare(strict_types=1);
namespace ActaLibre\Tests\Install;
use ActaLibre\Install\SourcesLivrees;
use PHPUnit\Framework\TestCase;
final class SourcesLivreesTest extends TestCase
{
    public function testLesDeuxConsommateursOntLesMemesSources(): void
    {
        $s=new SourcesLivrees(dirname(__DIR__,2));
        self::assertCount(19,$s->tables());
        $p=array_column($s->parametres(),'valeur','param');
        self::assertArrayNotHasKey('EA_VERSION',$p);self::assertSame('Mon site principal',$p['SITENAME']);
        self::assertArrayHasKey('URL_PUBLIQUE',$p);self::assertSame(['metadb','metalg','mgrplg'],array_values(array_unique(array_column($s->metadonnees(),0))));
        self::assertNotEmpty($s->prenoms());
    }
}
