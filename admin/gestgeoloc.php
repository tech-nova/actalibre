<?php
if (file_exists('tools/_COMMUN_env.inc.php')){
	$EA_Appel_dOu = '';
} else {
	$EA_Appel_dOu = '../';
}
include($EA_Appel_dOu.'tools/_COMMUN_env.inc.php');
$a_db ??= null; $action ??= null; $commune ??= null; $depart ??= null; $row ??= null; $statut ??= null;

pathroot($root,$path,$xcomm,$xpatr,$page);

$id  = getparam('id');
$act = getparam('act');

$userlogin="";
$userlevel=logonok(9);
while ($userlevel<9)
  {
  login($root);
  }
exiger_jeton_si(getparam('action') == 'submitted' or getparam('act') == 'del');

$lon    = getparam('lon');
$lat    = getparam('lat');
$noteN  = getparam('noteN');
$noteM  = getparam('noteM');
$noteD  = getparam('noteD');
$noteV  = getparam('noteV');

$missingargs=true;
$JSheader = "";
//print '<pre>';  print_r($_REQUEST); echo '</pre>';

$leid=getparam('id');
	 
if ($id > 0)  // édition
	{  //
	$action = 'Modification';
	$request = "select ID, COMMUNE, DEPART, CAST(LON AS CHAR) as LON, CAST(LAT AS CHAR) as LAT, STATUT, NOTE_N, NOTE_M, NOTE_D, NOTE_V"
				." from ".EA_DB."_geoloc where ID = ?";
	if ($result = \ActaLibre\Db\Requete::executer(EA_sql_which_link(), $request, [(int)$id]))
		{
		// Identifiant inconnu : fiche vide.
		$row = (EA_sql_fetch_array($result) ?: []) + array_fill_keys(['COMMUNE','DEPART','LON','LAT','STATUT','NOTE_N','NOTE_M','NOTE_D','NOTE_V'],'');
		$commune   = $row["COMMUNE"];
		$depart    = $row["DEPART"];
		$lon       = $row["LON"];
		$lat       = $row["LAT"];
		$statut    = $row["STATUT"];
		$noteN     = $row["NOTE_N"];
		$noteM     = $row["NOTE_M"];
		$noteD     = $row["NOTE_D"];
		$noteV     = $row["NOTE_V"];
		}
	   else
		{
		echo "<p>*** FICHE NON TROUVEE***</p>";
		}
	$zoom = 11;	
	if ($lon==0 and $lat==0 and GEO_CENTRE_CARTE <> "")
		{
		$geores = \ActaLibre\Db\Requete::executer(EA_sql_which_link(), "select CAST(LON AS CHAR) as LON, CAST(LAT AS CHAR) as LAT from ".EA_DB."_geoloc where COMMUNE = ? and STATUT in ('A','M')", [GEO_CENTRE_CARTE]);
		if ($geores and $geo = EA_sql_fetch_array($geores))
			{
			$lon = $geo['LON'];
			$lat = $geo['LAT'];
			$zoom = 5;
			}
		}
	if ($lon==0 and $lat==0)
		{
		$lon = 5;
		$lat = 50; // Froidfontaine !!
		$zoom = 5;
		}
		
	$carto = new \ActaLibre\Carte\Carte("500",300);
	global $root;
	$image = EA_URL_SITE.$root.'/img/pin_eye.png';
	$Xanchor = 10;
	$Yanchor = 35;
	$carto->icone($image,$Xanchor,$Yanchor); // défini le décalage du pied de la punaise
	$carto->marqueur((float)$lon,(float)$lat);
	$carto->marqueurDeplacable();
	$carto->zoom((int)$zoom);

	$JSheader = $carto->enTete($root);
	$JSheader .= \ActaLibre\Geoloc\Vue::entete($root);
	}

open_page("Gestion des localités",$root,null,null,$JSheader);

navadmin($root,"Gestion d'une localité");

echo '<div id="col_menu">';
form_recherche();
menu_admin($root,$userlevel);
echo '</div>';
echo '<div id="col_main_adm">';
menu_datas('L');


if ($id > 0 and $act=="del")
	{
	if (\ActaLibre\Db\Requete::executer(EA_sql_which_link(), "delete from ".EA_DB."_geoloc where ID = ?", [(int)$id]))
		{
		echo '<p><b>FICHE SUPPRIMEE.</b></p>';
		$id=0;
		}
	 else
		{
		echo ' -> Erreur : ';
		erreur_sql("delete from ".EA_DB."_geoloc");
		}
	}

	// Données postées -> ajouter ou modifier
if (getparam('action') == 'submitted')
	{
	$missingargs = false;
	$version = [
		'statut' => (string)getparam('version_statut'),
		'lon' => getparam('version_lon') === '' ? null : (string)getparam('version_lon'),
		'lat' => getparam('version_lat') === '' ? null : (string)getparam('version_lat'),
		];
	$lonSaisie = trim((string)getparam('lon'));
	$latSaisie = trim((string)getparam('lat'));
	if (!is_numeric($lonSaisie) or !is_numeric($latSaisie) or !\ActaLibre\Geoloc\Moteur::valides((float)$lonSaisie, (float)$latSaisie))
		{
		echo '<p><b>Coordonnées invalides : la longitude doit être comprise entre -180 et 180, la latitude entre -90 et 90. Rien n\'a été enregistré.</b></p>';
		}
	 else
		{
		$deplacee = $version['lon'] === null || $version['lat'] === null
			|| abs((float)$lonSaisie - (float)$version['lon']) > 1e-7 || abs((float)$latSaisie - (float)$version['lat']) > 1e-7;
		$notes = ['N' => (string)getparam('noteN'), 'M' => (string)getparam('noteM'), 'D' => (string)getparam('noteD'), 'V' => (string)getparam('noteV')];
		if (geoloc_fiches()->enregistrerFormulaire((int)$id, $version, (float)$lonSaisie, (float)$latSaisie, $deplacee ? 'M' : $version['statut'], $notes))
			{
			echo '<p><b>Fiche enregistrée.</b></p>';
			}
		 else
			{
			$actuelle = geoloc_fiches()->lire((int)$id);
			echo '<p><b>La fiche a été modifiée depuis son ouverture, par exemple par une géolocalisation : rien n\'a été enregistré.</b><br />';
			if ($actuelle !== null)
				echo 'Valeurs actuelles : '.\ActaLibre\Securite\Filtre::html(\ActaLibre\Geoloc\Statut::libelle($actuelle['statut'])).', longitude '.\ActaLibre\Securite\Filtre::html((string)$actuelle['lon']).', latitude '.\ActaLibre\Securite\Filtre::html((string)$actuelle['lat']).'.<br />';
			echo 'Valeurs que vous aviez saisies : longitude '.\ActaLibre\Securite\Filtre::html($lonSaisie).', latitude '.\ActaLibre\Securite\Filtre::html($latSaisie).'. Rouvrez la fiche pour recommencer.</p>';
			}
		}
	$id = 0;
	}

//Si pas tout les arguments nécessaire, on affiche le formulaire
if((int)$id<>0 and $missingargs)
	{
	echo '<h2>'.$action." d'une fiche de localité</h2> \n";
  echo '<form method="post" id="fiche" name="eaform" action="gestgeoloc.php">'."\n";
  echo \ActaLibre\Securite\Jeton::champ();
	echo '<table cellspacing="0" cellpadding="1" border="0" summary="Formulaire">'."\n";

	echo " <tr>\n";
	echo "  <td align='right'>Localité : </td>\n";
	echo '  <td colspan="2"><b>'.\ActaLibre\Securite\Filtre::stocke($commune." [".$depart."]")."</b></td>\n";
	echo " </tr>\n";

	echo " <tr>\n";
	echo "  <td align='right'>Longitude : </td>\n";
	echo '  <td><input type="text" name="lon" id="lon" size="10" value="'.\ActaLibre\Securite\Filtre::stocke($row['LON']).'" />'."</td>\n";
	echo '  <td rowspan="5">';
	if (isset($carto)) echo $carto->afficher();
	echo "</td>\n";
	echo " </tr>\n";

	echo " <tr>\n";
	echo "  <td align='right'>Latitude : </td>\n";
	echo '  <td><input type="text" name="lat" id="lat" size="10" value="'.\ActaLibre\Securite\Filtre::stocke($row['LAT']).'" />'."</td>\n";
	echo " </tr>\n";
	echo " <tr>\n";
	echo "  <td></td>\n";
	echo '  <td><button type="button" id="geoloc-rechercher" data-id="'.(int)$id.'" hidden>Rechercher les coordonnées avec Nominatim</button>'
		.' <span id="geoloc-fiche-message" role="status"></span></td>'."\n";
	echo " </tr>\n";

	echo " <tr>\n";
	echo "  <td align='right'>Géolocalisation : </td>\n";
	echo '  <td id="geoloc-fiche-statut">'.\ActaLibre\Geoloc\Statut::libelle((string)$statut)."</td>\n";
	echo " </tr>\n";

	echo " <tr>\n";
	echo "  <td align='right' colspan='2'><b>Déplacer la punaise pour &nbsp; <br/ >corriger la localisation --> </b></td>\n";
	echo " </tr>\n";

	echo " <tr>\n";
	echo "  <td align='right'>Commentaire<br />Naissances : </td>\n";
	echo '  <td colspan="2"><textarea name="noteN" cols="60" rows="2">'.\ActaLibre\Securite\Filtre::html(html_entity_decode((string)$noteN, ENTITY_REPLACE_FLAGS, ENTITY_CHARSET)).'</textarea></td>';
	echo " </tr>\n";

	echo " <tr>\n";
	echo "  <td align='right'>Commentaire<br />Mariages : </td>\n";
	echo '  <td colspan="2"><textarea name="noteM" cols="60" rows="2">'.\ActaLibre\Securite\Filtre::html(html_entity_decode((string)$noteM, ENTITY_REPLACE_FLAGS, ENTITY_CHARSET)).'</textarea></td>';
	echo " </tr>\n";

	echo " <tr>\n";
	echo "  <td align='right'>Commentaire<br />Décès : </td>\n";
	echo '  <td colspan="2"><textarea name="noteD" cols="60" rows="2">'.\ActaLibre\Securite\Filtre::html(html_entity_decode((string)$noteD, ENTITY_REPLACE_FLAGS, ENTITY_CHARSET)).'</textarea></td>';
	echo " </tr>\n";

	echo " <tr>\n";
	echo "  <td align='right'>Commentaire<br />Actes divers : </td>\n";
	echo '  <td colspan="2"><textarea name="noteV" cols="60" rows="2">'.\ActaLibre\Securite\Filtre::html(html_entity_decode((string)$noteV, ENTITY_REPLACE_FLAGS, ENTITY_CHARSET)).'</textarea></td>';
	echo " </tr>\n";

	 echo " <tr><td align=\"right\">\n";
	 echo '  <input type="hidden" name="id" value="'.\ActaLibre\Securite\Filtre::html($id).'" />';
	 echo '  <input type="hidden" name="commune" value="'.\ActaLibre\Securite\Filtre::stocke($commune).'" />';
	 echo '  <input type="hidden" name="action" value="submitted" />';
	 $versionLue = geoloc_fiches()->lire((int)$id);
	 foreach (['statut', 'lon', 'lat'] as $champ)
	   echo '  <input type="hidden" name="version_'.$champ.'" id="version_'.$champ.'" value="'.\ActaLibre\Securite\Filtre::html((string)($versionLue[$champ] ?? '')).'" />';
	 echo '  <a href="aide/geoloc.html" target="_blank">Aide</a>&nbsp;';
	 echo '  <input type="reset" value=" Effacer " />'."\n";
	 echo " </td><td  colspan='2' align=\"left\">\n";
	 echo ' &nbsp; <input type="submit" value=" *** ENREGISTRER *** " />'."\n";
	 if ($id>0)
	   {
	   echo ' &nbsp; &nbsp; &nbsp; <a href="gestgeoloc.php?id='.(int)$id.'&amp;act=del&amp;'.\ActaLibre\Securite\Jeton::lien().'">Supprimer cette localité</a>'."\n";
	   }
	 echo " </td></tr>\n";
	 echo "</table>\n";
	 echo "</form>\n";
	 echo \ActaLibre\Geoloc\Vue::dialogue($root.'/admin/geoloc_travail.php');
  }
 else
  {
	 echo '<p align="center"><a href="listgeolocs.php">Retour à la liste des localités</a>';
	 if ($leid > 0 and $act!="del")
	   echo '&nbsp;|&nbsp; <a href="gestgeoloc.php?id='.(int)$leid.'">Retour à la fiche de '.\ActaLibre\Securite\Filtre::html(getparam('commune')).'</a>';
	 echo '</p>';
  }
echo '</div>';
close_page(1);
?>
