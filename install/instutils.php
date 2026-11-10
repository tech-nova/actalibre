<?php

//-------- utilitaire pour l'installation et la mise à jour ---------------

class Parametre 
			{
	    public $param;  // nom du parametre et de la constante associéé
	    public $groupe; // groupe de paramètres
	    public $ordre;  // ordre pour classement dans l'écran
	    public $type;   // B(ooleen), N(umérique), C(aractère) ou T(exte)
	    public $valeur; // valeur effective (par défaut)
	    public $listval; // liste des valeurs admissibles (lisbox)
	    public $libelle;// texte dans l'ecran de saisie
	    public $aide;   // aide associée
	
	    public function __construct ($arg) 
	    	{
	        foreach ($arg as $k=>$v)
	            $this->$k = $arg[$k];
	    	}
			}
//------------------------------------------------------------------------------

function xml_write_table($table,$zones,&$nb,?callable $inclure=null)
		{
		// retorune sous forme XML les zones de la table designée
		$doc = "";
		$request = "select * from ".$table;
		//echo $request;
		$result = EA_sql_query($request);
		$nbdocs = EA_sql_num_rows($result);
		$fields_cnt = EA_sql_num_fields($result);
		//$nb=0; passé par référence !!
		while ($row = EA_sql_fetch_array($result))
			{
			if ($inclure !== null && !$inclure($row)) continue;
			$doc .= "  <".$table.">\n";
			foreach ($zones as $zone => $zoneval) 
				{
				$doc .= "    <".$zoneval.">";
				$tvalue = (string)($row[$zoneval] ?? '');
				$tvalue= str_replace(chr(13).chr(10),'\n',$tvalue);      // codage des retours chariots    	
				$doc .= (htmlspecialchars(html_entity_decode($tvalue, ENTITY_REPLACE_FLAGS, ENTITY_CHARSET), ENTITY_REPLACE_FLAGS, ENTITY_CHARSET));  
				$doc .= "</".$zoneval.">\n";
				} // end foreach
			$doc .= "  </".$table.">\n";
			$nb++;
			}
		return $doc;
		}
		
//------------------------------------------------------------------------------

function readDatabase($filename, $masterkey)
{
    $famille = preg_replace('/^.*_/', '', (string)$masterkey);
    $lignes = \ActaLibre\Parametres\Sauvegarde::lire($filename)->lignes($famille);
    return array_map(static fn (array $ligne) => new Parametre($ligne), $lignes);
}

// L'adresse de ce site ne dépend pas des requêtes d'un visiteur.
function fixer_url_publique()
{
    EA_sql_query("UPDATE ".EA_DB."_params SET valeur='".sql_quote(EA_URL_SITE)."' WHERE param='URL_PUBLIQUE' AND valeur=''");
}

function update_params($paramfile, $modifvaleur=0)
{
    global $par_add, $par_mod;
    $doc = \ActaLibre\Parametres\Sauvegarde::lire($paramfile);
    $partiel = \ActaLibre\Parametres\Sauvegarde::famille('params', $doc->lignes('params'));
    $r = new \ActaLibre\Parametres\Restauration(EA_sql_which_link(), EA_DB);
    $r->preparer($partiel, (bool)$modifvaleur);
    $bilan = $r->appliquer();
    $par_add += $bilan['ajouts'];
    $par_mod += $bilan['modifications'];
}

function xml_readDatabase($filename, $masterkey)
{
    $famille = preg_replace('/^.*_/', '', (string)$masterkey);
    return \ActaLibre\Parametres\Sauvegarde::lire($filename)->lignes($famille);
}

function update_metafile($tabdata, $tabkeys, $table, &$radd, &$rmod)
{
    if (!preg_match('/\A([A-Za-z0-9_]+)_(metadb|metalg|mgrplg)\z/D', $table, $m)
        || $tabkeys !== \ActaLibre\Parametres\Sauvegarde::CLES[$m[2]]) {
        throw new RuntimeException('Destination de restauration incorrecte.');
    }
    $doc = \ActaLibre\Parametres\Sauvegarde::famille($m[2], $tabdata ?? []);
    $r = new \ActaLibre\Parametres\Restauration(EA_sql_which_link(), $m[1]);
    $r->preparer($doc, true);
    $bilan = $r->appliquer();
    $radd += $bilan['ajouts'];
    $rmod += $bilan['modifications'];
}
