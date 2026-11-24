<?php
declare(strict_types=1);
namespace ActaLibre\Tests\Install;
use ActaLibre\Install\Inventaire;
use PHPUnit\Framework\TestCase;
abstract class BaseDiagnostic extends TestCase
{
    protected string $root;
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/al5-diagnostic-'.bin2hex(random_bytes(5)); mkdir($this->root);
        copy(dirname(__DIR__,2).'/composer.json', $this->root.'/composer.json');
        $this->poser('_config_init/actes.css','modele');
        $this->poser('tmp_init/.htaccess','refus');
        foreach (['logs','backups','archive'] as $d) { $this->poser('tmp_init/'.$d.'/.keep',''); }
        $fichiers = ['composer.json','inventaire-actalibre.json','_config_init/actes.css','tmp_init/.htaccess','tmp_init/logs/.keep','tmp_init/backups/.keep','tmp_init/archive/.keep'];
        $repertoires = ['_config_init','tmp_init','tmp_init/logs','tmp_init/backups','tmp_init/archive'];
        file_put_contents($this->root.'/inventaire-actalibre.json', json_encode(['format'=>1,'version'=>'1.1.2','schema'=>'1.0.0','revision'=>str_repeat('a',40),'fichiers'=>$fichiers,'repertoires'=>$repertoires], JSON_THROW_ON_ERROR));
    }
    protected function poser(string $p, string $texte): void
    {
        if (!is_dir(dirname($this->root.'/'.$p))) { mkdir(dirname($this->root.'/'.$p),0755,true); }
        file_put_contents($this->root.'/'.$p,$texte);
    }
    protected function inventaire(): Inventaire { return Inventaire::lire($this->root); }
    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
        rmdir($this->root);
    }
}
