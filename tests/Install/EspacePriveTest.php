<?php
declare(strict_types=1);
namespace ActaLibre\Tests\Install;
use ActaLibre\Install\EspacePrive;
use ActaLibre\Install\ArchivesHistoriques;
use PHPUnit\Framework\TestCase;
final class EspacePriveTest extends TestCase
{
    private string $root;
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/al5-prive-'.bin2hex(random_bytes(5)); mkdir($this->root);
        mkdir($this->root.'/tmp_init', 0755);
        file_put_contents($this->root.'/tmp_init/.htaccess', 'Require all denied');
        foreach (['logs','backups','archive'] as $d) { mkdir($this->root.'/tmp_init/'.$d); file_put_contents($this->root.'/tmp_init/'.$d.'/.keep', ''); }
    }
    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { if ($f->isDir() && !$f->isLink()) { chmod($f->getPathname(), 0755); rmdir($f->getPathname()); } else { unlink($f->getPathname()); } }
        rmdir($this->root);
    }
    public function testPrepareTmpEtConserveConfigurationEtProtectionsPersonnalisees(): void
    {
        mkdir($this->root.'/_config'); file_put_contents($this->root.'/_config/connect.inc.php', 'sentinelle');
        $r = EspacePrive::preparer($this->root);
        self::assertSame([], $r['erreurs']);
        self::assertSame($this->root.'/tmp/logs', EspacePrive::chemin($this->root,'logs'));
        self::assertFileExists($this->root.'/tmp/backups/.keep');
        self::assertSame('sentinelle', file_get_contents($this->root.'/_config/connect.inc.php'));
        file_put_contents($this->root.'/tmp/.htaccess', 'personnalise');
        EspacePrive::preparer($this->root);
        self::assertSame('personnalise', file_get_contents($this->root.'/tmp/.htaccess'));
    }
    public function testArchiveDeuxDossiersEntiersEtNeLesRejouePas(): void
    {
        EspacePrive::preparer($this->root);
        foreach (['_logs','_backup'] as $d) { mkdir($this->root.'/'.$d); file_put_contents($this->root.'/'.$d.'/temoin', str_repeat('x', 1024*1024)); }
        $inode = fileinode($this->root.'/_logs/temoin');
        $r = ArchivesHistoriques::deplacer($this->root);
        self::assertSame([], $r['erreurs']); self::assertCount(2, $r['archives']);
        self::assertDirectoryDoesNotExist($this->root.'/_logs');
        self::assertSame($inode, fileinode($this->root.'/'.$r['archives'][0].'/temoin'));
        self::assertSame([], ArchivesHistoriques::deplacer($this->root)['archives']);
    }
    public function testCollisionEtLienConserventLaSource(): void
    {
        EspacePrive::preparer($this->root); mkdir($this->root.'/_logs');
        // Couvrir le passage possible à la seconde suivante sans figer l'horloge de production.
        foreach ([-1,0,1,2] as $n) { mkdir($this->root.'/tmp/archive/'.gmdate('ymdHis',time()+$n)); }
        self::assertNotEmpty(ArchivesHistoriques::deplacer($this->root)['erreurs']);
        self::assertDirectoryExists($this->root.'/_logs');
        symlink($this->root.'/_logs', $this->root.'/_backup');
        self::assertNotEmpty(ArchivesHistoriques::deplacer($this->root)['erreurs']);
        self::assertTrue(is_link($this->root.'/_backup'));
    }
}
