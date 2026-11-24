<?php
if (file_exists('tools/_COMMUN_env.inc.php')){
	$EA_Appel_dOu = '';
} else {
	$EA_Appel_dOu = '../';
}
include($EA_Appel_dOu.'tools/_COMMUN_env.inc.php');
$maxsize ??= null; $udbaddr ??= null; $udbuser ??= null;

function show_grp($grp,$current,$barre)
	{
	if ($barre)
		echo ' | ';	  
	if ($grp==$current)
		echo '<strong>'.\ActaLibre\Securite\Filtre::stocke($grp).'</strong>';
	 else
		echo '<a href="'.\ActaLibre\Securite\Filtre::html('gest_params.php?grp='.urlencode($grp)).'">'.\ActaLibre\Securite\Filtre::stocke($grp).'</a>';
	}			

function alaligne($texte)
  {
  // insert des BR pour provoquer des retour à la ligne
  $order   = ["\r\n", "\n", "\r"];
	$replace = '<br />';
	// Traitement du premier \r\n, ils ne seront pas convertis deux fois.
	return str_replace($order, $replace, $texte);
  }

$root = "";
$path = "";

$js_show_help = "";
$js_show_help .= "function show(id) \n ";
$js_show_help .= "{ \n ";
$js_show_help .= "	el = document.getElementById(id); \n ";
$js_show_help .= "	if (el.style.display == 'none') \n ";
$js_show_help .= "	{ \n ";
$js_show_help .= "		el.style.display = ''; \n ";
$js_show_help .= "		el = document.getElementById('help' + id); \n ";
$js_show_help .= "	} else { \n ";
$js_show_help .= "		el.style.display = 'none'; \n ";
$js_show_help .= "		el = document.getElementById('help' + id); \n ";
$js_show_help .= "	} \n ";
$js_show_help .= "} \n ";


//**************************** ADMIN **************************

pathroot($root,$path,$xcomm,$xpatr,$page);

$userlogin="";
$userlevel=logonok(9);
while ($userlevel<9)
  {
  login($root);
  }
exiger_jeton_si(getparam('xconfirm') == 'confirmed');

open_page("Paramétrage du logiciel",$root,$js_show_help);
navadmin($root,"Paramétrage du logiciel");

//{ print '<pre>';  print_r($_REQUEST); echo '</pre>'; }

echo '<div id="col_menu">';
form_recherche();
menu_admin($root,$userlevel);
echo '</div>';

echo '<div id="col_main_adm">';

menu_software('P');

echo '<h2>Paramétrage du site "'.\ActaLibre\Securite\Filtre::stocke(SITENAME).'"</h2>';


$ok = false;
$missingargs=false;

$xgroupe = is_string($_REQUEST['grp'] ?? null) ? $_REQUEST['grp'] : '';
$xconfirm = getparam('xconfirm');

if ($xgroupe == '')
	{
	// Données postées
	$xgroupe="Affichage";
  $missingargs=true;  // par défaut
  }

//$request = "select distinct groupe from ".EA_DB."_params order by groupe";
$request = "select distinct groupe from ".EA_DB."_params where not (groupe in ('Hidden','Deleted')) order by groupe";
$result = EA_sql_query($request);
//echo $request;
$barre = false;
echo '<p align="center"><strong>Paramètres : </strong>';
while ($row = EA_sql_fetch_array($result))
  {
	show_grp($row["groupe"],$xgroupe,$barre);
	$barre = true;
  }
echo ' || <a href="update_params.php">Backup</a>';
echo '</p>';

//{ print '<pre>';  print_r($_REQUEST); echo '</pre>'; }

if (!$missingargs)
	{
	$oktype=true;

	if ($xconfirm == 'confirmed')
		{
        // Le nombre de champs vient du groupe en base, jamais de parnbr reçu. Conversion nécessaire : la table est en latin1, la connexion en UTF-8.
        $compte = \ActaLibre\Db\Requete::executer(EA_sql_which_link(), "SELECT COUNT(*) FROM ".EA_DB."_params WHERE BINARY CONVERT(groupe USING utf8mb4) = BINARY CONVERT(? USING utf8mb4)", [$xgroupe]);
        $limite = $compte instanceof mysqli_result ? (int)$compte->fetch_row()[0] : 0;
        $propositions = [];
        for ($i = 1; $i <= $limite; $i++) {
            $nom = $_POST["parname$i"] ?? null;
            $valeur = $_POST["parvalue$i"] ?? '';
            if (is_string($nom) && is_string($valeur)) {
                $propositions[] = ['nom' => $nom, 'valeur' => $valeur];
            }
        }
        $cpt = \ActaLibre\Parametres\EditionGroupe::enregistrer(EA_sql_which_link(), EA_DB, $xgroupe, $propositions);
		if ($cpt > 0)
		  msg("Sauvegarde : ".$cpt." paramètre(s) modifié(s).","info");
		}
	}	

$request = "select * from ".EA_DB."_params where groupe=? order by ordre";
$result = \ActaLibre\Db\Requete::executer(EA_sql_which_link(),$request,[$xgroupe]);
//echo $request;
echo '<h2 align="center">'.\ActaLibre\Securite\Filtre::html($xgroupe).'</h2>';
if ($xgroupe=="Mail")
	{
	echo '<p align="center"><a href="test_mail.php"><b>Tester l\'envoi d\'e-mail</b></a></p>';
	}
if ($xgroupe=="Utilisateurs" and isset($udbname))
	{
	msg('ATTENTION : Base des utilisateurs déportée sur '.\ActaLibre\Securite\Filtre::stocke($udbaddr."/".$udbuser."/".$udbname."/".EA_UDB)."</p>",'info');
	}
	
echo '<form method="post" action="">'."\n";
echo \ActaLibre\Securite\Jeton::champ();
echo '<table cellspacing="0" cellpadding="1" border="0" summary="Formulaire">'."\n";
$i=0;
$prog = "gest_params.php?grp=".urlencode($xgroupe);
while ($row = EA_sql_fetch_array($result))
	{
	$i++;
	echo ' <tr>'."\n";
	// Deux libellés livrés avec le logiciel contiennent un saut de ligne.
	echo '  <td align="right"><b>'.str_replace('&lt;br /&gt;', '<br />', \ActaLibre\Securite\Filtre::stocke($row["libelle"]))."</b>";
	echo ' <a href="'.\ActaLibre\Securite\Filtre::html($prog).'" id="help'.$i.'" onclick="javascript:show(\'aide'.$i.'\');return false;"><b>(?)</b></a>';
	// Aide livrée avec le logiciel, rédigée en HTML.
	echo '<span id="aide'.$i.'" style="display: none" class="aide"><br />'.\ActaLibre\Securite\Filtre::stocke($row["param"])." : ".alaligne($row["aide"]).'</span>';
	echo " : </td>\n";
	echo '  <td>';
	echo '<input type="hidden" name="parname'.$i.'"  value="'.\ActaLibre\Securite\Filtre::stocke($row["param"]).'" />'."\n";
	switch ($row["type"])
		{
		case "B":
			$size = 1;
			break;
		case "N":
		case "L":
			$size = 5;
			$maxsize = 5;
			break;
		case "C":
			$size = 50;
			$maxsize = 250;
			break;
		case "T":
			$size = 1000;
			$maxsize = 0;
			break;
        default:
            $size = 1;
            $maxsize = 0;
            break;
		}
	if ($row["type"]=="B")
		{
		echo '<input type="checkbox" name="parvalue'.$i.'" value="1"'.checked($row["valeur"]).' />';
		}
	 elseif ($row["type"]=="L")
		{
		$leschoix=explode(";",$row["listval"]);
		echo '<select name="parvalue'.$i.'">'."\n";
		foreach($leschoix as $lechoix)
			{
			echo '<option '.selected_option(intval(mb_substr($lechoix,0,isin($lechoix,"-",0)-1)),$row["valeur"]).'>'.\ActaLibre\Securite\Filtre::stocke($lechoix).'</option>'."\n";
			}
		echo " </select>\n";
		}
	 else
		{
		if ($size <= 100)
			{
			echo '<input type="text" name="parvalue'.$i.'" size="'.$size.'" maxlength="'.$maxsize.'" value="'.\ActaLibre\Securite\Filtre::stocke($row["valeur"]).'" />';
			}
		 else
			{
			echo '<textarea name="parvalue'.$i.'" cols="40" rows="6">'.\ActaLibre\Securite\Filtre::html(html_entity_decode($row["valeur"], ENTITY_REPLACE_FLAGS, ENTITY_CHARSET)).'</textarea>';
			}
		}
	echo '  </td>';
	echo " </tr>\n";
	}
if ($xgroupe === 'Protection')
	{
	// Lignes informatives, hors formulaire d'enregistrement : aucun champ parname/parvalue.
	global $dbname;
	$resume = \ActaLibre\Install\DiagnosticInstallation::resume(dirname(__DIR__), (string)$dbname, UPLOAD_DIR);
	foreach (['Contrôle des protections des fichiers' => $resume['protection'], 'Fichiers non reconnus' => $resume['fichiers']] as $libelle => $valeur)
		{
		echo ' <tr>'."\n".'  <td align="right"><b>'.\ActaLibre\Securite\Filtre::html($libelle).'</b> : </td>'."\n";
		echo '  <td>'.\ActaLibre\Securite\Filtre::html($valeur).' &nbsp; <a href="protection.php">Détail et nouveau contrôle</a></td>'."\n </tr>\n";
		}
	}
echo ' <tr><td align="right">'."\n";
echo '<input type="hidden" name="parnbr"  value="'.$i.'" />'."\n";
echo '<input type="hidden" name="grp"  value="'.\ActaLibre\Securite\Filtre::html($xgroupe).'" />'."\n";
echo '<input type="hidden" name="xconfirm" value="confirmed" />'."\n";
echo '<a href="index.php">Annuler</a> &nbsp; &nbsp;</td>'."\n";
echo '<td><input type="submit" value="ENREGISTRER" /></td>'."\n";
echo "</tr></table>\n";
echo "</form>\n";
echo '</div>';
close_page(1,$root);
?>
