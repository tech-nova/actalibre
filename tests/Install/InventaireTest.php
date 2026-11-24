<?php
declare(strict_types=1);
namespace ActaLibre\Tests\Install;

use ActaLibre\Install\Inventaire;
use PHPUnit\Framework\TestCase;

final class InventaireTest extends TestCase
{
    private string $racine;
    protected function setUp(): void
    {
        $this->racine = sys_get_temp_dir() . '/al-inventaire-' . bin2hex(random_bytes(6));
        mkdir($this->racine);
        copy(dirname(__DIR__, 2) . '/composer.json', $this->racine . '/composer.json');
        mkdir($this->racine . '/src');
        file_put_contents($this->racine . '/src/application.php', 'temoin');
    }
    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->racine, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->racine);
    }
    /** @param array<string,mixed> $modifications */
    private function ecrire(array $modifications = []): void
    {
        file_put_contents($this->racine . '/inventaire-actalibre.json', json_encode(array_replace([
            'format' => 1, 'version' => '1.1.2', 'schema' => '1.0.0', 'revision' => str_repeat('a', 40),
            'fichiers' => ['composer.json', 'inventaire-actalibre.json', 'src/application.php'], 'repertoires' => ['src'],
        ], $modifications), JSON_THROW_ON_ERROR));
    }
    public function testLitEtReverifieSansToucherAuxFichiers(): void
    {
        $this->ecrire();
        $i = Inventaire::lire($this->racine);
        self::assertSame('1.1.2', $i->version);
        self::assertSame(['src'], $i->repertoires());
        self::assertCount(3, $i->fichiers());
        unlink($this->racine . '/src/application.php');
        $this->expectException(\RuntimeException::class);
        $i->verifierPresence();
    }
    public function testRefuseUneVersionDifferenteEtUnFichierObligatoireAbsent(): void
    {
        foreach ([['version' => '2.0.0'], ['fichiers' => ['absent', 'composer.json', 'inventaire-actalibre.json']], ['schema' => '2.0.0']] as $m) {
            $this->ecrire($m);
            try { Inventaire::lire($this->racine); self::fail('Inventaire accepte'); } catch (\RuntimeException) { self::assertFileExists($this->racine . '/src/application.php'); }
        }
    }
    public function testRefuseLesCheminsEtSchemasAmbigus(): void
    {
        foreach (['../x', '/x', 'src/../x', 'C:/x', 'src\\x', "x\0y", 'src//x', './x'] as $p) {
            $this->ecrire(['fichiers' => ['composer.json', 'inventaire-actalibre.json', $p]]);
            try { Inventaire::lire($this->racine); self::fail($p); } catch (\RuntimeException) { self::assertTrue(true); }
        }
        foreach ([['fichiers' => ['composer.json', 'composer.json']], ['repertoires' => ['src', 'composer.json']], ['intrus' => true], ['format' => '1']] as $m) {
            $this->ecrire($m);
            try { Inventaire::lire($this->racine); self::fail('Schema accepte'); } catch (\RuntimeException) { self::assertTrue(true); }
        }
    }
    public function testRefuseLeManifesteAbsentOuInvalide(): void
    {
        file_put_contents($this->racine . '/inventaire-actalibre.json', '{');
        $this->expectException(\RuntimeException::class);
        Inventaire::lire($this->racine);
    }
}
