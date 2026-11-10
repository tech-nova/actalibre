<?php
declare(strict_types=1);
namespace ActaLibre\Parametres;

/** Lecture du format historique ; aucun identifiant fourni par le XML n'atteint SQL. */
final class Sauvegarde
{
    public const COLONNES = [
        'params' => ['param','groupe','ordre','type','valeur','listval','libelle','aide'],
        'metadb' => ['ZID','dtable','zone','groupe','bloc','typ','taille','OV2','OV3','oblig','affich'],
        'metalg' => ['ZID','lg','etiq','aide'],
        'mgrplg' => ['grp','dtable','lg','sigle','getiq'],
    ];
    public const CLES = ['params'=>['param'], 'metadb'=>['ZID'], 'metalg'=>['ZID','lg'], 'mgrplg'=>['grp','dtable','lg','sigle']];
    /** @param array<string,list<array<string,string>>> $donnees */
    private function __construct(private array $donnees = []) {}

    public static function lire(string $fichier): self
    {
        $taille = @filesize($fichier);
        if ($taille === false || $taille > 8 * 1024 * 1024) { throw new \RuntimeException('Sauvegarde absente ou trop volumineuse.'); }
        $xml = @file_get_contents($fichier);
        if ($xml === false || str_contains($xml, "\0") || preg_match('//u', $xml) !== 1 || preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $xml) === 1 || preg_match('/<\?xml[^>]*encoding\s*=\s*[\'"](?!UTF-8[\'"])/i', $xml) === 1) {
            throw new \RuntimeException('La sauvegarde doit être un XML UTF-8 sans DTD.');
        }
        $xml = str_starts_with($xml, "\xEF\xBB\xBF") ? substr($xml, 3) : $xml;
        $doc = new self();
        $doc->analyser($xml);
        return $doc;
    }
    private int $profondeur = 0;
    private ?string $prefixe = null;
    private string $famille = '';
    private string $colonne = '';
    /** @var array<string,string> */
    private array $ligne = [];
    private function analyser(string $xml): void
    {
        $parser = xml_parser_create('UTF-8');
        xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, false);
        xml_set_external_entity_ref_handler($parser, static fn (): bool => false);
        xml_set_element_handler($parser,
            function (\XMLParser $p, string $nom, array $attributs): void { $this->ouvrir($nom, $attributs); },
            function (\XMLParser $p, string $nom): void {
                if ($this->profondeur === 2) {
                    foreach (self::CLES[$this->famille] as $cle) {
                        if (!array_key_exists($cle, $this->ligne)) { throw new \RuntimeException('Clé manquante dans la sauvegarde.'); }
                    }
                    $this->donnees[$this->famille][] = $this->ligne;
                }
                $this->profondeur--;
            }
        );
        xml_set_character_data_handler($parser, function (\XMLParser $p, string $texte): void {
            if ($this->profondeur === 3) { $this->ligne[$this->colonne] .= $texte; }
            elseif (trim($texte) !== '') { throw new \RuntimeException('Texte hors colonne.'); }
        });
        try {
            if (!xml_parse($parser, $xml, true) || $this->donnees === []) { throw new \RuntimeException('Sauvegarde XML vide ou mal formée.'); }
        } finally { xml_parser_free($parser); }
    }
    /** @param array<string,string> $attributs */
    private function ouvrir(string $nom, array $attributs): void
    {
        $this->profondeur++;
        if ($attributs !== []) { throw new \RuntimeException('Attribut XML non admis.'); }
        if ($this->profondeur === 1) {
            if ($nom !== 'expoactes') { throw new \RuntimeException('Racine de sauvegarde incorrecte.'); }
        } elseif ($this->profondeur === 2) {
            if (preg_match('/\A([A-Za-z0-9_]+)_(params|metadb|metalg|mgrplg)\z/D', $nom, $m) !== 1 || ($this->prefixe !== null && $this->prefixe !== $m[1])) { throw new \RuntimeException('Famille ou préfixe de sauvegarde incorrect.'); }
            $this->prefixe = $m[1]; $this->famille = $m[2]; $this->ligne = [];
        } elseif ($this->profondeur === 3) {
            if (!in_array($nom, self::COLONNES[$this->famille], true) || array_key_exists($nom, $this->ligne)) { throw new \RuntimeException('Colonne inconnue ou répétée.'); }
            $this->colonne = $nom; $this->ligne[$nom] = '';
        } else { throw new \RuntimeException('Structure de sauvegarde incorrecte.'); }
    }
    /** @return list<array<string,string>> */
    public function lignes(string $famille): array { return $this->donnees[$famille] ?? []; }

    /**
     * Compatibilité des anciennes API ; valide aussi les données déjà décodées.
     * @param list<array<string,string>> $lignes
     */
    public static function famille(string $famille, array $lignes): self
    {
        if (!isset(self::COLONNES[$famille])) { throw new \RuntimeException('Famille inconnue.'); }
        foreach ($lignes as $ligne) {
            if (array_diff(array_keys($ligne), self::COLONNES[$famille]) !== [] || array_diff(self::CLES[$famille], array_keys($ligne)) !== []) { throw new \RuntimeException('Colonnes de sauvegarde incorrectes.'); }
        }
        return new self([$famille => $lignes]);
    }
}
