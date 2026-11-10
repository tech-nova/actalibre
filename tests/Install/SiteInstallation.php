<?php

declare (strict_types=1);
namespace ActaLibre\Tests\Install;

use ActaLibre\Tests\GoldenMaster\Client;
/** Site nu, sans données ni fichiers préparés par l'installateur. */
final class SiteInstallation
{
    public readonly string $racine;
    public readonly string $prefixe;
    public readonly \mysqli $base;
    private readonly string $dossier;
    /** @var list<resource> */
    private array $serveurs = [];
    private string $adresse = '';
    /** @param array{hote:string,nom:string,utilisateur:string,passe:string} $connexion */
    public function __construct(private readonly string $depot, public readonly array $connexion)
    {
        $this->prefixe = 'al3_' . bin2hex(random_bytes(6));
        $this->dossier = sys_get_temp_dir() . '/' . $this->prefixe;
        $this->racine = $this->dossier . '/site';
        $this->base = new \mysqli($connexion['hote'], $connexion['utilisateur'], $connexion['passe'], $connexion['nom']);
        $this->base->set_charset('utf8mb4');
    }
    /** @param list<string> $reglages */
    public function demarrer(bool $sousDossier = false, array $reglages = []): void
    {
        mkdir($this->dossier);
        mkdir($this->dossier . '/infra');
        mkdir($this->racine);
        $it=new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($this->depot,\FilesystemIterator::SKIP_DOTS),
            fn(\SplFileInfo $f):bool=>!$f->isLink() && \ActaLibre\Tests\Infrastructure\InventaireDuBanc::livrable(substr($f->getPathname(),strlen($this->depot)+1))
        ),\RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $f) {
            $cible=$this->racine.substr($f->getPathname(),strlen($this->depot));
            $f->isDir() ? mkdir($cible) : copy($f->getPathname(),$cible);
        }
        \ActaLibre\Tests\Infrastructure\InventaireDuBanc::ecrire($this->racine);
        if (is_dir($this->racine . '/admin/_upload')) {
            BaseInstallation::supprimer($this->racine . '/admin/_upload');
        }
        $this->adresse = $this->serveur($sousDossier, $reglages);
    }
    /** @param list<string> $reglages */
    private function serveur(bool $sousDossier, array $reglages): string
    {
        $sonde = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($sonde, false), ':'), 1);
        fclose($sonde);
        $options = [];
        foreach ($reglages as $r) {
            array_push($options, '-d', $r);
        }
        $p = proc_open([PHP_BINARY, ...$options, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . $this->dossier . '/infra/php.log', '-d', 'session.save_path=' . $this->dossier . '/infra', '-S', '127.0.0.1:' . $port, '-t', $sousDossier ? $this->dossier : $this->racine], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $t);
        if (!is_resource($p)) {
            throw new \RuntimeException('Serveur non lancé');
        }
        $this->serveurs[] = $p;
        for ($i = 0; $i < 50; $i++) {
            $s = @fsockopen('127.0.0.1', $port, $e, $m, 0.1);
            if ($s !== false) {
                fclose($s);
                return 'http://127.0.0.1:' . $port . ($sousDossier ? '/site' : '');
            }
            usleep(100000);
        }
        throw new \RuntimeException('Serveur indisponible');
    }
    public function url(): string
    {
        return $this->adresse;
    }
    public function client(): Client
    {
        return new Client($this->adresse);
    }
    public function deuxiemeClient(): Client
    {
        return new Client($this->serveur(false, []));
    }
    public function journal(): string
    {
        return is_file($this->dossier . '/infra/php.log') ? (string) file_get_contents($this->dossier . '/infra/php.log') : '';
    }
    public function arreter(): void
    {
        foreach ($this->serveurs as $s) {
            proc_terminate($s);
            proc_close($s);
        }
        $this->serveurs = [];
        foreach ($this->base->query('SHOW TABLES')->fetch_all() as [$table]) {
            if (strncasecmp($table, $this->prefixe . '_', strlen($this->prefixe) + 1) === 0) {
                $this->base->query("DROP TABLE `{$table}`");
            }
        }
        $this->base->close();
        BaseInstallation::supprimer($this->dossier);
    }
    /** @return array<string,string> */
    public function formulaire(): array
    {
        return ['url_site' => $this->url() . '/', 'serveur' => $this->connexion['hote'], 'base' => $this->connexion['nom'], 'utilisateur' => $this->connexion['utilisateur'], 'mot_de_passe_mysql' => $this->connexion['passe'], 'prefixe' => $this->prefixe, 'login' => 'admin', 'courriel' => 'admin@example.org', 'mot_de_passe' => 'Secret-2026', 'confirmation' => 'Secret-2026'];
    }
}
