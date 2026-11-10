<?php
declare(strict_types=1);
namespace ActaLibre\Install;

/** Lecture unique des sources livrées, partagée par installation et migration. */
final class SourcesLivrees
{
    /** @var array<string,string> */
    private array $definitions = [];
    /** @var list<array{string,list<string>,list<string>}> */
    private array $insertions = [];
    /** @var list<array<string,string>> */
    private array $parametres = [];
    /** @var list<string> */
    private array $prenoms = [];
    public function __construct(string $racine)
    {
        foreach (self::decouper(self::lire($racine . '/install/creetables.sql'), ';') as $instruction) {
            if (preg_match('/\ACREATE TABLE IF NOT EXISTS EA_DB_([a-z0-9_]+)\s*\(/', $instruction, $m) === 1) {
                if (isset($this->definitions[$m[1]])) {
                    self::erreur();
                }
                $this->definitions[$m[1]] = $instruction;
            } elseif (preg_match('/\AINSERT INTO EA_DB_(metadb|metalg|mgrplg)\s*\(([A-Za-z0-9_, ]+)\)\s*VALUES\s*(.+)\z/s', $instruction, $m) === 1) {
                $this->insertions[] = [$m[1], array_map('trim', explode(',', $m[2])), self::decouper($m[3], ',')];
            } else {
                self::erreur();
            }
        }
        if (!isset($this->definitions['params'], $this->definitions['user3'], $this->definitions['metadb'], $this->definitions['metalg'], $this->definitions['mgrplg'])) {
            self::erreur();
        }
        $xml = self::lire($racine . '/install/act_params.xml');
        if (stripos($xml, '<!DOCTYPE') !== false) {
            self::erreur();
        }
        $parser = xml_parser_create('UTF-8');
        xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
        $elements = [];
        if (xml_parse_into_struct($parser, $xml, $elements) !== 1) {
            self::erreur();
        }
        $ligne = null;
        $noms = [];
        foreach ($elements as $element) {
            if ($element['tag'] === 'act_params' && $element['type'] === 'open') {
                $ligne = [];
            } elseif ($element['tag'] === 'act_params' && $element['type'] === 'close') {
                if (!is_array($ligne) || !isset($ligne['param']) || isset($noms[$ligne['param']]) || Marqueurs::ressemble($ligne['param']) || $ligne['param'] === EtatSite::PARAMETRE_HISTORIQUE) {
                    self::erreur();
                }
                $noms[$ligne['param']] = true;
                $this->parametres[] = array_replace(['param' => '', 'groupe' => '', 'ordre' => '100', 'type' => 'C', 'valeur' => '', 'listval' => '', 'libelle' => '', 'aide' => ''], $ligne);
                $ligne = null;
            } elseif (is_array($ligne) && $element['type'] === 'complete') {
                if (!in_array($element['tag'], ['param', 'groupe', 'ordre', 'type', 'valeur', 'listval', 'libelle', 'aide'], true)) {
                    self::erreur();
                }
                $valeur = $element['value'] ?? '';
                if ($element['tag'] === 'valeur') {
                    $valeur = str_replace('\\n', "\r\n", $valeur);
                }
                // Normaliser avant les contrôles des noms réservés et après
                // décodage, sans conserver l'indentation du document XML.
                $ligne[$element['tag']] = trim($valeur);
            }
        }
        if ($this->parametres === [] || $ligne !== null) {
            self::erreur();
        }
        foreach (preg_split('/\r\n|\r|\n/', self::lire($racine . '/install/liste_prenoms.csv')) ?: [] as $prenom) {
            $prenom = trim($prenom);
            if ($prenom !== '' && !str_starts_with($prenom, '#')) {
                $this->prenoms[] = $prenom;
            }
        }
    }
    /** @return array<string,string> */
    public function tables(): array { return $this->definitions; }
    /** @return list<array<string,string>> */
    public function parametres(): array { return $this->parametres; }
    /** @return list<array{string,list<string>,list<string>}> */
    public function metadonnees(): array { return $this->insertions; }
    /** @return list<string> */
    public function prenoms(): array { return $this->prenoms; }
    private static function lire(string $chemin): string
    {
        $texte = @file_get_contents($chemin);
        if ($texte === false) {
            self::erreur();
        }
        return $texte;
    }
    private static function erreur(): never
    {
        throw new \RuntimeException('Impossible de charger les données initiales.');
    }
    /**
     * Découpe seulement hors chaînes et parenthèses ; les littéraux restent interprétés par MySQL.
     * @return list<string>
     */
    private static function decouper(string $texte, string $separateur): array
    {
        $morceaux = [];
        $debut = 0;
        $profondeur = 0;
        $quote = null;
        $longueur = strlen($texte);
        for ($i = 0; $i < $longueur; $i++) {
            $c = $texte[$i];
            if ($quote !== null) {
                if ($c === '\\') {
                    $i++;
                    continue;
                }
                if ($c === $quote) {
                    if (($texte[$i + 1] ?? '') === $quote) {
                        $i++;
                    } else {
                        $quote = null;
                    }
                }
            } elseif (in_array($c, ["'", '"', '`'], true)) {
                $quote = $c;
            } elseif ($c === '(') {
                $profondeur++;
            } elseif ($c === ')') {
                if (--$profondeur < 0) {
                    self::erreur();
                }
            } elseif ($c === $separateur && $profondeur === 0) {
                $s = trim(substr($texte, $debut, $i - $debut));
                if ($s !== '') {
                    $morceaux[] = $s;
                }
                $debut = $i + 1;
            }
        }
        if ($quote !== null || $profondeur !== 0) {
            self::erreur();
        }
        $s = trim(substr($texte, $debut));
        if ($s !== '') {
            $morceaux[] = $s;
        }
        return $morceaux;
    }
}
