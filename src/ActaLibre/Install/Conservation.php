<?php
declare(strict_types=1);
namespace ActaLibre\Install;

/** Une seule politique pour le diagnostic et les producteurs de données installées. */
final class Conservation
{
    /** @var array<string,true> */
    private array $livres;
    /** @var array<string,true> */
    private array $dossiers;
    /** @var array<string,true> */
    private array $projetes = [];
    /** @var array<string,true> */
    private array $conteneurs = [];
    private readonly string $depot;
    public function __construct(Inventaire $inventaire, private readonly string $nomBase, string $uploadDir)
    {
        if ($uploadDir === '' || str_starts_with($uploadDir,'/') || str_contains($uploadDir,'\\') || str_contains($uploadDir,"\0") || array_intersect(explode('/',$uploadDir),['','.','..']) !== []) { throw new \RuntimeException('Répertoire de dépôt invalide pour le diagnostic.'); }
        $this->depot = 'admin/'.$uploadDir;
        $this->livres = array_fill_keys($inventaire->fichiers(),true);
        $this->dossiers = array_fill_keys($inventaire->repertoires(),true);
        foreach ($inventaire->fichiers() as $p) {
            foreach (['_config_init/'=>'_config/', 'tmp_init/'=>'tmp/', 'admin/_upload_init/'=>$this->depot.'/'] as $source=>$cible) {
                if (str_starts_with($p,$source)) { $this->projetes[$cible.substr($p,strlen($source))] = true; }
            }
        }
        foreach (['tmp/logs','tmp/backups','tmp/archive','tmp/actalibre','tmp/actalibre/geocodage','_config',$this->depot,...array_map('dirname',array_keys($this->projetes))] as $d) {
            while ($d !== '.') { $this->conteneurs[$d]=true; $d=dirname($d); }
        }
    }
    /** @param array<array-key,int> $stat */
    public function classer(string $chemin, array $stat): string
    {
        $type = ($stat['mode'] ?? 0) & 0170000;
        if ($type !== 0040000 && $type !== 0100000) { return 'inconnu'; }
        if ($type === 0040000) {
            if (isset($this->dossiers[$chemin])) { return 'livre'; }
            if (isset($this->conteneurs[$chemin])) { return 'conteneur'; }
            if (in_array($chemin,['_logs','_backup'],true) || preg_match('~\Atmp/archive/[0-9]{12}\z~D',$chemin) === 1) { return 'conserve'; }
            return 'inconnu';
        }
        if (isset($this->livres[$chemin])) { return 'livre'; }
        if (isset($this->projetes[$chemin]) || $chemin === '.htaccess' || $chemin === '_config/connect.inc.php' || $chemin === '_config/.actalibre-installation.lock') { return 'conserve'; }
        if (preg_match('~\A_config/BD-(.+)-connect\.inc\.php\z~D',$chemin,$m) === 1 && InstallGuard::nomHoteValide($m[1])) { return 'conserve'; }
        if (preg_match('~\A_config/\.actalibre-connect-[a-f0-9]+\.tmp\z~D',$chemin) === 1) { return 'conserve'; }
        if (preg_match('~\Atmp/logs/(?:[^/]+\.log|courriels\.txt)\z~D',$chemin) === 1) { return 'conserve'; }
        $base = preg_quote($this->nomBase,'~');
        if (preg_match('~\Atmp/backups/(?:backup_[^/]+\.bea|[^/]+\.txt|'.$base.'_[0-9]{4}-[0-9]{2}-[0-9]{2}\.sql)\z~D',$chemin) === 1) { return 'conserve'; }
        if ($chemin === 'tmp/actalibre/protection.json' || preg_match('~\Atmp/actalibre/\.protection-[a-f0-9]{24}\.tmp\z~D',$chemin) === 1) { return 'conserve'; }
        if (preg_match('~\Atmp/actalibre/geocodage/(?:travail-[a-f0-9]{32}\.json|cache-[a-f0-9]{64}\.json|debit\.json|debit\.lock|travaux\.lock|\.ecriture-[a-f0-9]{24}\.tmp)\z~D',$chemin) === 1) { return 'conserve'; }
        if (dirname($chemin) === $this->depot && preg_match('/(?:\.(?:csv|bea)|^[NMDV]-.+\.m_csv)$/ui',basename($chemin)) === 1) { return 'conserve'; }
        return 'inconnu';
    }
    public function doitDescendre(string $chemin): bool
    {
        return isset($this->dossiers[$chemin]) || isset($this->conteneurs[$chemin]);
    }
}
