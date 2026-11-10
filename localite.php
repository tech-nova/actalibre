<?php
use ActaLibre\Db\Requete;
if (file_exists('tools/_COMMUN_env.inc.php')){
	$EA_Appel_dOu = '';
} else {
	$EA_Appel_dOu = '../';
}
include($EA_Appel_dOu.'tools/_COMMUN_env.inc.php');
$i ??= null; $lasttyp ??= null; $lat ??= null; $lon ??= null;

pathroot($root,$path,$xcomm,$xpatr,$page);

$id  = getparam('id');

$userlogin="";
$userlevel=logonok(1);
while ($userlevel<1)
  {
  login($root);
  }

$ok=true;
$JSheader = "";
$commune = $depart = $noteN = $noteM = $noteD = $noteV = "";
$cptN = $cptM = $cptD = $cptV = 0;
//print '<pre>';  print_r($_REQUEST); echo '</pre>';
	 
if ($id > 0)  // édition
	{  //
	$request = "select *"
				." from ".EA_DB."_geoloc "
				." where ID = ?";
	if ($result = Requete::executer(EA_sql_which_link(), $request, [(int)$id]))
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
		
		// Une ligne par déposant dans _sums : on totalise par type.
		$request = "select TYPACT,LIBELLE,sum(NB_TOT) as NB_TOT"
				." from ".EA_DB."_sums where COMMUNE = '".sql_quote($commune)."' and DEPART = '".sql_quote($depart)."'"
				." group by TYPACT,LIBELLE"
				." order by INSTR('NMDV',TYPACT),LIBELLE; ";

		$cptN = $cptM = $cptD = $cptV = 0;
		$i=0;
		$lasttyp = 'eye'; // default
		if ($result = EA_sql_query($request))
			{
			while ($ligne = EA_sql_fetch_array($result))
				{
				if ($ligne['TYPACT']<>$lasttyp)
					$i++;
				$lasttyp=$ligne['TYPACT'];
				switch ($lasttyp)
					{
					case "N":
					$cptN = $ligne['NB_TOT'];
					break;
					case "M":
					$cptM = $ligne['NB_TOT'];
					break;
					case "D":
					$cptD = $ligne['NB_TOT'];
					break;
					case "V":
					$cptV += $ligne['NB_TOT'];
					break;
					}
				}
			}
		}
	   else
		{
		echo "<p>*** FICHE NON TROUVEE***</p>";
		$ok = false;
		}
		
	$carto = new \ActaLibre\Carte\Carte("600",400);
	global $root;
	$fullpath = EA_URL_SITE.$root;
	$image = $fullpath.'/img/pin_eye.png';
	$Xanchor = 10;
	$Yanchor = 35;
	global $imagePin;
	$imagePin = $fullpath.'/img/pin_';
	$carto->icone($image,$Xanchor,$Yanchor); // décalage du pied de la punaise
	if ($i == 1)
			$pin = $imagePin.$lasttyp.".png";
		else
		  {
			$pin = $imagePin.$i.".png";
			}
	$carto->marqueur((float)$lon,(float)$lat,"","",$pin);
	$carto->zoom(11);

	$JSheader = $carto->enTete($root);
	}

$localite = $commune.show_depart($depart);
$userid = current_user("ID");
open_page($localite,$root,null,null,$JSheader);
navigation($root,2,"A","Localisation d'une commune ou paroisse");
zone_menu(0,$userlevel);

echo '<div id="col_main">'."\n";
echo '<h2>Commune/Paroisse : '.\ActaLibre\Securite\Filtre::stocke($localite).'</h2>';
echo '<div id="mapzone" align="center">';
if (isset($carto)) echo $carto->afficher();
echo '</div>';

if ($noteN <>'' or $cptN>0)
	{
	if ($cptN>0)
		{
		$href = '<a href="'.\ActaLibre\Securite\Filtre::html(mkurl($root.'/tab_naiss.php',$commune.' ['.$depart.']')).'">';
		$txthtml = "<p>".$href.entier($cptN)." Naissances/Baptêmes</a><br />";
		}	
		else
		$txthtml = "<p>";
	echo $txthtml.$noteN."</p>"; // HTML rédigé par un compte de niveau 9
	}
if ($noteM <>'' or $cptM>0)
	{
	if ($cptM>0)
		{
		$href = '<a href="'.\ActaLibre\Securite\Filtre::html(mkurl($root.'/tab_mari.php',$commune.' ['.$depart.']')).'">';
		$txthtml = "<p>".$href.entier($cptM)." Mariages</a><br />";
		}	
		else
		$txthtml = "<p>";
	echo $txthtml.$noteM."</p>"; // HTML rédigé par un compte de niveau 9
	}
if ($noteD <>'' or $cptD>0)
	{
	if ($cptD>0)
		{
		$href = '<a href="'.\ActaLibre\Securite\Filtre::html(mkurl($root.'/tab_deces.php',$commune.' ['.$depart.']')).'">';
		$txthtml = "<p>".$href.entier($cptD)." Décès/Sépultures</a><br />";
		}	
		else
		$txthtml = "<p>";
	echo $txthtml.$noteD."</p>"; // HTML rédigé par un compte de niveau 9
	}
if ($noteV <>'' or $cptV>0)
	{
	if ($cptV>0)
		{
		$href = '<a href="'.\ActaLibre\Securite\Filtre::html(mkurl($root.'/tab_bans.php',$commune.' ['.$depart.']')).'">';
		$txthtml = "<p>".$href.entier($cptV)." Actes divers</a><br />";
		}	
		else
		$txthtml = "<p>";
	echo $txthtml.$noteV."</p>"; // HTML rédigé par un compte de niveau 9
	}

echo '</div>';
close_page(1);
?>
