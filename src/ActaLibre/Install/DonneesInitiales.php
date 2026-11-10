<?php

declare (strict_types=1);
namespace ActaLibre\Install;

use ActaLibre\Db\Requete;
/** Interprète uniquement les trois sources livrées, sans importer le bootstrap historique. */
final class DonneesInitiales
{
    /** @var array<string,string> */
    private array $definitions = [];
    /** @var list<array{string,list<string>,list<string>}> */
    private array $insertions = [];
    /** @var list<array<string,string>> */
    private array $parametres = [];
    /** @var list<string> */
    private array $prenoms = [];
    /** @var array<string,array<string,array{type:string,collation:string}>> */
    private array $colonnes = [];
    private const CLES = ['metadb' => ['ZID'], 'metalg' => ['ZID', 'lg'], 'mgrplg' => ['grp', 'dtable', 'lg', 'sigle']];
    public function __construct(string $racine)
    {
        $sources = new SourcesLivrees($racine);
        $this->definitions = $sources->tables();
        $this->insertions = $sources->metadonnees();
        $this->parametres = $sources->parametres();
        $this->prenoms = $sources->prenoms();
    }
    public function creerParametres(\mysqli $lien, string $prefixe): void
    {
        $this->creer($lien, $prefixe, 'params');
    }
    /** @param null|\Closure(string):void $apresEtape */
    public function completer(\mysqli $lien, string $prefixe, string $courriel, string $urlPublique, ?\Closure $apresEtape = null): void
    {
        foreach (array_keys($this->definitions) as $suffixe) {
            $this->creer($lien, $prefixe, $suffixe);
        }
        $rangs = [];
        foreach ($this->insertions as [$suffixe, $colonnes, $tuples]) {
            foreach ($tuples as $tuple) {
                if (!str_starts_with($tuple, '(') || !str_ends_with($tuple, ')')) {
                    self::erreur();
                }
                $resultat = self::requete($lien, 'SELECT ' . substr($tuple, 1, -1));
                $valeurs = $resultat instanceof \mysqli_result ? $resultat->fetch_row() : null;
                if ($valeurs === null || count($colonnes) !== count($valeurs)) {
                    self::erreur();
                }
                $donnees = [];
                foreach ($colonnes as $i => $colonne) {
                    $valeur = $valeurs[$i];
                    if (!is_string($valeur) && !is_int($valeur) && !is_float($valeur) && $valeur !== null) {
                        self::erreur();
                    }
                    $donnees[$colonne] = $valeur;
                }
                $rang = ($rangs[$suffixe] ?? 0) + 1;
                $rangs[$suffixe] = $rang;
                if ($this->insererManquant($lien, $prefixe . '_' . $suffixe, $donnees, self::CLES[$suffixe])) {
                    $apresEtape?->__invoke("donnee:{$suffixe}:{$rang}");
                }
            }
        }
        foreach ($this->prenoms as $i => $prenom) {
            if ($this->insererManquant($lien, $prefixe . '_prenom', ['prenom' => $prenom], ['prenom'])) {
                $apresEtape?->__invoke('donnee:prenom:' . ($i + 1));
            }
        }
        foreach ($this->parametres as $i => $parametre) {
            $parametre['valeur'] = match ($parametre['param']) {
                'LOC_MAIL' => $courriel,
                'URL_PUBLIQUE' => $urlPublique,
                default => $parametre['valeur'],
            };
            if ($this->insererManquant($lien, $prefixe . '_params', $parametre, ['param'])) {
                $apresEtape?->__invoke('donnee:params:' . ($i + 1));
            }
        }
    }
    private function creer(\mysqli $lien, string $prefixe, string $suffixe): void
    {
        if (preg_match('/\A[A-Za-z0-9_]{1,55}\z/', $prefixe) !== 1) {
            throw new \InvalidArgumentException('Préfixe de tables invalide.');
        }
        $sql = preg_replace('/\ACREATE TABLE IF NOT EXISTS EA_DB_[a-z0-9_]+/', "CREATE TABLE IF NOT EXISTS `{$prefixe}_{$suffixe}`", $this->definitions[$suffixe]);
        if ($sql === null) {
            self::erreur();
        }
        self::requete($lien, $sql);
    }
    /**
     * @param array<string,string|int|float|null> $donnees
     * @param list<string> $cles
     */
    private function insererManquant(\mysqli $lien, string $table, array $donnees, array $cles): bool
    {
        $conditions = [];
        $valeurs = [];
        foreach ($cles as $cle) {
            $conditions[] = "`{$cle}` <=> ?";
            $valeurs[] = $donnees[$cle];
        }
        $existant = self::requete($lien, "SELECT 1 FROM `{$table}` WHERE " . implode(' AND ', $conditions), $valeurs);
        if (!$existant instanceof \mysqli_result || $existant->num_rows > 1) {
            self::erreur();
        }
        if ($existant->num_rows === 1) {
            return false;
        }
        if (!isset($this->colonnes[$table])) {
            $schema = self::requete($lien, "SHOW FULL COLUMNS FROM `{$table}`");
            if (!$schema instanceof \mysqli_result) {
                self::erreur();
            }
            $this->colonnes[$table] = [];
            foreach ($schema->fetch_all(MYSQLI_ASSOC) as $c) {
                $this->colonnes[$table][(string) $c['Field']] = ['type' => (string) $c['Type'], 'collation' => (string) ($c['Collation'] ?? '')];
            }
        }
        foreach ($donnees as $nom => $valeur) {
            if (!isset($this->colonnes[$table][$nom])) {
                self::erreur();
            }
            if (!is_string($valeur)) {
                continue;
            }
            $c = $this->colonnes[$table][$nom];
            $octets = $valeur;
            if (str_starts_with($c['collation'], 'latin1_')) {
                $octets = @iconv('UTF-8', 'Windows-1252', $valeur);
                if ($octets === false) {
                    self::erreur();
                }
            }
            if (preg_match('/\A(?:var)?char\((\d+)\)/', $c['type'], $m) === 1 && mb_strlen($valeur, 'UTF-8') > (int) $m[1]) {
                self::erreur();
            }
            if ($c['type'] === 'text' && strlen($octets) > 65535) {
                self::erreur();
            }
        }
        $colonnes = '`' . implode('`,`', array_keys($donnees)) . '`';
        self::requete($lien, "INSERT INTO `{$table}` ({$colonnes}) VALUES (" . implode(',', array_fill(0, count($donnees), '?')) . ')', array_values($donnees));
        return true;
    }
    /** @param list<string|int|float|null> $valeurs */
    private static function requete(\mysqli $lien, string $sql, array $valeurs = []): \mysqli_result|bool
    {
        try {
            $r = Requete::executer($lien, $sql, $valeurs);
        } catch (\mysqli_sql_exception) {
            self::erreur();
        }
        if ($r === false) {
            self::erreur();
        }
        return $r;
    }
    private static function erreur(): never
    {
        throw new \RuntimeException('Impossible de charger les données initiales.');
    }
}
