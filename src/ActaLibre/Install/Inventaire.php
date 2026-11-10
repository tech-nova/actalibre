<?php
declare(strict_types=1);
namespace ActaLibre\Install;

use ActaLibre\Livraison;

/** Inventaire de diffusion : contrôle des noms et types, jamais des contenus du site. */
final class Inventaire
{
    public const NOM = 'inventaire-actalibre.json';
    /**
     * @param list<string> $listeFichiers
     * @param list<string> $listeRepertoires
     */
    private function __construct(
        private readonly string $racine,
        public readonly string $version,
        public readonly string $schema,
        public readonly string $revision,
        private readonly array $listeFichiers,
        private readonly array $listeRepertoires,
    ) {
    }

    public static function lire(string $racine): self
    {
        $racine = rtrim($racine, '/');
        $chemin = $racine . '/' . self::NOM;
        self::exigerType($chemin, false);
        self::exigerType($racine . '/composer.json', false);
        $taille = filesize($chemin);
        if ($taille === false || $taille > 16 * 1024 * 1024) {
            throw new \RuntimeException('Inventaire trop volumineux ou illisible.');
        }
        $json = @file_get_contents($chemin);
        try {
            $d = json_decode($json === false ? '' : $json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('Inventaire JSON invalide.');
        }
        $cles = ['fichiers', 'format', 'repertoires', 'revision', 'schema', 'version'];
        $reelles = is_array($d) ? array_keys($d) : [];
        sort($reelles);
        if ($reelles !== $cles || $d['format'] !== 1 || !is_string($d['revision']) || preg_match('/\A[a-f0-9]{40}\z/D', $d['revision']) !== 1) {
            throw new \RuntimeException('Format de l’inventaire invalide.');
        }
        $livraison = Livraison::lire($racine . '/composer.json');
        if ($d['version'] !== $livraison->version || $d['schema'] !== $livraison->schema) {
            throw new \RuntimeException('Inventaire et livraison ne correspondent pas.');
        }
        $fichiers = self::liste($d['fichiers']);
        $repertoires = self::liste($d['repertoires']);
        if (count($fichiers) + count($repertoires) > 100000 || !in_array(self::NOM, $fichiers, true) || !in_array('composer.json', $fichiers, true) || array_intersect($fichiers, $repertoires) !== []) {
            throw new \RuntimeException('Inventaire incomplet ou ambigu.');
        }
        $parents = array_fill_keys($repertoires, true);
        foreach (array_merge($fichiers, $repertoires) as $p) {
            $parent = dirname($p);
            if ($parent !== '.' && !isset($parents[$parent])) {
                throw new \RuntimeException('Parent absent de l’inventaire.');
            }
        }
        $i = new self($racine, $livraison->version, $livraison->schema, $d['revision'], $fichiers, $repertoires);
        $i->verifierPresence();
        return $i;
    }

    /** @return list<string> */
    private static function liste(mixed $liste): array
    {
        if (!is_array($liste) || !array_is_list($liste) || count($liste) > 100000) {
            throw new \RuntimeException('Liste de chemins invalide.');
        }
        $vus = [];
        foreach ($liste as $p) {
            if (!is_string($p) || $p === '' || strlen($p) > 4096 || preg_match('//u', $p) !== 1 || str_contains($p, '\\') || str_contains($p, "\0") || str_starts_with($p, '/') || preg_match('/\A[A-Za-z]:/', $p) === 1 || array_intersect(explode('/', $p), ['', '.', '..']) !== [] || isset($vus[$p])) {
                throw new \RuntimeException('Chemin invalide ou répété dans l’inventaire.');
            }
            $vus[$p] = true;
        }
        return $liste;
    }

    private static function exigerType(string $p, bool $repertoire): void
    {
        clearstatcache(true, $p);
        $s = @lstat($p);
        if ($s === false || ($s['mode'] & 0170000) !== ($repertoire ? 0040000 : 0100000)) {
            throw new \RuntimeException('Copie incomplète ou type de fichier inattendu.');
        }
    }

    public function verifierPresence(): void
    {
        // Parents avant fichiers : aucun chemin attendu n'est suivi à travers un lien.
        $parents = $this->listeRepertoires;
        usort($parents, static fn (string $a, string $b): int => substr_count($a, '/') <=> substr_count($b, '/'));
        foreach ($parents as $p) {
            self::exigerType($this->racine . '/' . $p, true);
        }
        foreach ($this->listeFichiers as $p) {
            self::exigerType($this->racine . '/' . $p, false);
        }
    }
    /** @return list<string> */
    public function fichiers(): array { return $this->listeFichiers; }
    /** @return list<string> */
    public function repertoires(): array { return $this->listeRepertoires; }
}
