<?php

declare (strict_types=1);
namespace ActaLibre\Tests\Install;

use PHPUnit\Framework\TestCase;
/** Périmètre SQL et disque indépendant du banc gm_*. */
abstract class BaseInstallation extends TestCase
{
    protected \mysqli $base;
    protected string $prefixe;
    protected string $racine;
    protected function setUp(): void
    {
        $this->base = self::connexion();
        $this->prefixe = 'al3_' . bin2hex(random_bytes(6));
        $this->racine = sys_get_temp_dir() . '/' . $this->prefixe;
        mkdir($this->racine);
    }
    public static function connexion(): \mysqli
    {
        foreach (['HOST', 'USER', 'PASS', 'NAME'] as $cle) {
            if (getenv('GM_DB_' . $cle) === false) {
                self::markTestSkipped('Configurer GM_DB_HOST, USER, PASS et NAME.');
            }
        }
        $lien = new \mysqli((string) getenv('GM_DB_HOST'), (string) getenv('GM_DB_USER'), (string) getenv('GM_DB_PASS'), (string) getenv('GM_DB_NAME'));
        $lien->set_charset('utf8mb4');
        return $lien;
    }
    protected function tearDown(): void
    {
        if (!isset($this->base)) {
            return;
        }
        foreach ($this->base->query('SHOW TABLES')->fetch_all() as [$table]) {
            if (strncasecmp($table, $this->prefixe . '_', strlen($this->prefixe) + 1) === 0) {
                $this->base->query("DROP TABLE `{$table}`");
            }
        }
        $this->base->close();
        self::supprimer($this->racine);
    }
    public static function supprimer(string $chemin): void
    {
        if (is_link($chemin) || is_file($chemin)) {
            unlink($chemin);
            return;
        }
        if (!is_dir($chemin)) {
            return;
        }
        foreach (new \FilesystemIterator($chemin) as $f) {
            self::supprimer($f->getPathname());
        }
        rmdir($chemin);
    }
    protected function sources(): void
    {
        foreach (['.htaccess_init', '_config_init', 'tmp_init', 'admin/_upload_init'] as $source) {
            self::copier(dirname(__DIR__, 2) . '/' . $source, $this->racine . '/' . $source);
        }
    }
    public static function copier(string $source, string $cible): void
    {
        if (is_file($source)) {
            if (!is_dir(dirname($cible))) {
                mkdir(dirname($cible), 0777, true);
            }
            copy($source, $cible);
            return;
        }
        if (!is_dir($cible)) {
            mkdir($cible, 0777, true);
        }
        foreach (new \FilesystemIterator($source) as $f) {
            self::copier($f->getPathname(), $cible . '/' . $f->getFilename());
        }
    }
    protected function empreinte(): string
    {
        $resultat = [];
        foreach ($this->base->query('SHOW TABLES')->fetch_all() as [$table]) {
            if (strncasecmp($table, $this->prefixe . '_', strlen($this->prefixe) + 1) !== 0) {
                continue;
            }
            $lignes = array_map('serialize', $this->base->query("SELECT * FROM `{$table}`")->fetch_all());
            sort($lignes);
            $resultat[$table] = [$this->base->query("SHOW CREATE TABLE `{$table}`")->fetch_row()[1], $lignes];
        }
        ksort($resultat);
        return hash('sha256', serialize($resultat));
    }
}
