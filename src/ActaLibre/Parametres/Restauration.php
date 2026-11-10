<?php
declare(strict_types=1);
namespace ActaLibre\Parametres;

use ActaLibre\Db\Requete;
use ActaLibre\Install\EtatSite;
use ActaLibre\Install\Marqueurs;

/** Prépare toutes les lectures avant la première écriture ; MyISAM ne permet pas un rollback global. */
final class Restauration
{
    /** @var list<array{sql:string,params:list<string|int|float|null>,ajout:bool}> */
    private array $operations = [];
    private bool $prete = false;
    public function __construct(private readonly \mysqli $lien, private readonly string $prefixe)
    {
        if (preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixe) !== 1) { throw new \RuntimeException('Préfixe de destination incorrect.'); }
    }
    public function preparer(Sauvegarde $doc, bool $valeurs): void
    {
        $this->prete = false; $this->operations = [];
        foreach (Sauvegarde::CLES as $famille => $cles) {
            $table = '`' . $this->prefixe . '_' . $famille . '`'; $vus = [];
            $lignes = $doc->lignes($famille);
            if ($lignes === []) { continue; }
            $metadata = $this->lien->query('SHOW FULL COLUMNS FROM '.$table);
            if (!$metadata instanceof \mysqli_result) { throw new \RuntimeException('Structure de restauration illisible.'); }
            $definitions = array_column($metadata->fetch_all(MYSQLI_ASSOC), null, 'Field');
            foreach ($lignes as $ligne) {
                if ($famille === 'params' && self::reserve($ligne['param'])) { continue; }
                $empreinte = $this->cleCanonique($cles, $ligne, $definitions);
                if (isset($vus[$empreinte])) { throw new \RuntimeException('Clé répétée dans la sauvegarde.'); }
                $vus[$empreinte] = true;
                $conditions = []; $parametres = [];
                foreach ($cles as $cle) {
                    if ($ligne[$cle] === '') { $conditions[] = '(`'.$cle.'` IS NULL OR `'.$cle."` = '')"; }
                    else { $conditions[] = '`'.$cle.'` <=> ?'; $parametres[] = $ligne[$cle]; }
                }
                $where = implode(' AND ', $conditions);
                $r = Requete::executer($this->lien, 'SELECT * FROM '.$table.' WHERE '.$where, $parametres);
                if (!$r instanceof \mysqli_result) { throw new \RuntimeException('Lecture de restauration impossible.'); }
                $existantes = $r->fetch_all(MYSQLI_ASSOC);
                if (count($existantes) > 1) { throw new \RuntimeException('Clé ambiguë entre NULL et valeur vide.'); }
                $avant = $existantes[0] ?? null;
                if ($famille === 'params' && $avant !== null && (self::reserve((string)$avant['param']) || ($valeurs && $avant['groupe'] === 'Hidden'))) { continue; }
                $insertion = $avant === null;
                $colonnes = [];
                foreach ($ligne as $cle => $v) {
                    if (!$insertion && in_array($cle, $cles, true)) { continue; }
                    if ($famille === 'params' && $cle === 'valeur') {
                        if (!$insertion && (!$valeurs || $ligne['param'] === 'URL_PUBLIQUE' || strcasecmp((string)$avant['param'], 'URL_PUBLIQUE') === 0)) { continue; }
                        if ($insertion && $valeurs && strcasecmp($ligne['param'], 'URL_PUBLIQUE') === 0) { $v = ''; }
                        elseif ($insertion && defined($ligne['param'])) { $v = (string) constant($ligne['param']); }
                    }
                    $v = str_replace('\\n', "\r\n", $v);
                    if ($insertion || (string) $avant[$cle] !== $v) { $colonnes[$cle] = $v; }
                }
                if ($colonnes === []) { continue; }
                if ($insertion) {
                    $sql = 'INSERT INTO '.$table.' (`'.implode('`,`', array_keys($colonnes)).'`) VALUES ('.implode(',', array_fill(0, count($colonnes), '?')).')';
                    $params = array_values($colonnes);
                } else {
                    $exacts = []; $params = array_values($colonnes);
                    foreach ($cles as $cle) { $exacts[] = 'BINARY `'.$cle.'` <=> BINARY ?'; $params[] = $avant[$cle]; }
                    $sql = 'UPDATE '.$table.' SET '.implode(',', array_map(static fn (string $c): string => '`'.$c.'` = ?', array_keys($colonnes))).' WHERE '.implode(' AND ', $exacts);
                }
                $this->operations[] = ['sql'=>$sql,'params'=>$params,'ajout'=>$insertion];
            }
        }
        $this->prete = true;
    }
    /**
     * Utilise la collation réelle : casse et accents peuvent identifier la même clé SQL.
     * @param list<string> $cles
     * @param array<string,string> $ligne
     * @param array<string,array<string,mixed>> $definitions
     */
    private function cleCanonique(array $cles,array $ligne,array $definitions): string
    {
        $expressions=[];$valeurs=[];
        foreach ($cles as $cle) {
            $collation=$definitions[$cle]['Collation'] ?? null;
            if ($collation===null) {
                if (preg_match('/\A[+-]?[0-9]{1,20}\z/D',$ligne[$cle])!==1) { throw new \RuntimeException('Clé numérique incorrecte.'); }
                $expressions[]='CAST(? AS DECIMAL(65,0))';$valeurs[]=$ligne[$cle];
            } else {
                if (!is_string($collation) || preg_match('/\A([a-zA-Z0-9]+)_[a-zA-Z0-9_]+\z/D',$collation,$m)!==1) { throw new \RuntimeException('Collation de clé non prise en charge.'); }
                // Le nom de collation vient de SHOW, jamais du document XML.
                $expressions[]='HEX(WEIGHT_STRING(CONVERT(? USING '.$m[1].') COLLATE '.$collation.'))';
                $valeurs[]=rtrim($ligne[$cle],' ');
            }
        }
        $r=Requete::executer($this->lien,'SELECT '.implode(',',$expressions),$valeurs);
        $valeur=$r instanceof \mysqli_result ? $r->fetch_row() : null;
        if ($valeur===null) { throw new \RuntimeException('Comparaison des clés impossible.'); }
        return json_encode($valeur,JSON_THROW_ON_ERROR);
    }
    private static function reserve(string $nom): bool
    {
        return Marqueurs::ressemble($nom) || strcasecmp(trim($nom), EtatSite::PARAMETRE_HISTORIQUE) === 0;
    }
    /** @return array{ajouts:int,modifications:int} */
    public function appliquer(): array
    {
        if (!$this->prete) { throw new \RuntimeException('Restauration non préparée.'); }
        $this->prete = false;
        $bilan = ['ajouts'=>0,'modifications'=>0];
        try {
            foreach ($this->operations as $op) {
                if (Requete::executer($this->lien, $op['sql'], $op['params']) !== true) { throw new \RuntimeException('Écriture de restauration impossible.'); }
                $nombre = $this->lien->affected_rows;
                if (!is_int($nombre) || $nombre < 0) { throw new \RuntimeException('Comptage de restauration impossible.'); }
                $bilan[$op['ajout'] ? 'ajouts' : 'modifications'] += $nombre;
            }
        } catch (\mysqli_sql_exception) { throw new \RuntimeException('Écriture de restauration impossible. Les lignes déjà appliquées sont conservées.'); }
        return $bilan;
    }
}
