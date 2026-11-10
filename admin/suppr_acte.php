<?php
if (file_exists('tools/_COMMUN_env.inc.php')){
	$EA_Appel_dOu = '';
} else {
	$EA_Appel_dOu = '../';
}
include($EA_Appel_dOu.'tools/_COMMUN_env.inc.php');
$T0 ??= null; $conj ??= null; $ntype ??= null; $script ??= null; $table ??= null;
my_ob_start_affichage_continu(); 

$root = "";
$path = "";
// SUPPRESSION D'UN SEUL ACTE ***
//**************************** ADMIN **************************

pathroot($root,$path,$xcomm,$xpatr,$page);

$userlogin="";
$needlevel=6;  // niveau d'accès (anciennement 5)
$userlevel=logonok($needlevel);
while ($userlevel<$needlevel)
  {
  login($root);
  }
exiger_jeton_si(getparam('xconfirm') == 'confirmed');

open_page("Suppression d'un acte",$root);
navadmin($root,"Suppression d'un acte");

echo '<div id="col_menu">';
form_recherche();
menu_admin($root,$userlevel);
echo '</div>';

echo '<div id="col_main_adm">';

$missingargs=false;
$oktype=false;

$xid  = getparam('xid');
$xid = \ActaLibre\Securite\Filtre::entier($xid) ?? '';
$xtyp = getparam('xtyp');
$xconfirm = getparam('xconfirm');
exiger_droit_sur_acte($xid,$xtyp);

//{ print '<pre>';  print_r($_REQUEST); echo '</pre>'; }

if ($xid == '' or $xtyp =='')
	{
	// Données postées
	msg("Vous devez préciser le numéro et le type de l'acte.");
  $missingargs=true;  // par défaut
  }
if (! $missingargs)
	{
	$oktype=true;
	switch ($xtyp)
		{
		case "N":
		$ntype = "de naissance";
		$table = EA_DB."_nai3";
		$script = "tab_naiss.php";
		$conj="";
		break;
		case "V":
		$ntype = "divers";
		$table = EA_DB."_div3";
		$script = "tab_bans.php";
		$conj=", C_NOM, C_PRE";
		break;
		case "M":
		$ntype = "de mariage";
		$table = EA_DB."_mar3";
		$script = "tab_mari.php";
		$conj=", C_NOM, C_PRE";
		break;
		case "D":
		$ntype = "de décès";
		$table = EA_DB."_dec3";
		$script = "tab_deces.php";
		$conj="";
		break;
		}

	if ($xconfirm == 'confirmed')
		{
		$request = "select NOM,PRE,DATETXT,COMMUNE,DEPART ".$conj." from ".$table." where ID=?";
		$result = \ActaLibre\Db\Requete::executer(EA_sql_which_link(),$request,[$xid]);
		$ligne = EA_sql_fetch_row($result);
		$request = "delete from ".$table." where ID=?";
		$result = \ActaLibre\Db\Requete::executer(EA_sql_which_link(),$request,[$xid]);
		//echo $request;
		$nb = EA_sql_affected_rows();
		if ($nb > 0)
			{
			echo '<p>'.$nb.' acte '.$ntype.' supprimé.</p>';
			writelog('Suppression '.$ntype.' #'.$xid,$ligne[3],$nb);
			echo '<p>Retourner à la liste des actes ';
			$comdep=$ligne[3].' ['.$ligne[4].']';
			echo '<a href="'.\ActaLibre\Securite\Filtre::html(mkurl($script,stripslashes($comdep),$ligne[0])).'"><b>'.\ActaLibre\Securite\Filtre::stocke($ligne[0]).'</b></a>';
			if (isset($ligne[5])) echo ' ou <a href="'.\ActaLibre\Securite\Filtre::html(mkurl($script,stripslashes($comdep),$ligne[5])).'"><b>'.\ActaLibre\Securite\Filtre::stocke($ligne[5]).'</b></a>';
			echo '</p>';
			maj_stats($xtyp, $T0, $path, "C", $ligne[3], $ligne[4]); 
			}
		 else
			{
			echo '<p>Aucun acte supprimé.</p>';
			}
		}
	else
		{
		$request = "select NOM,PRE,DATETXT,COMMUNE,DEPART".$conj." from ".$table." where ID=?";
		$result = \ActaLibre\Db\Requete::executer(EA_sql_which_link(),$request,[$xid]);
		if ($ligne = EA_sql_fetch_row($result))
		  {
			echo '<form method="post" enctype="multipart/form-data" action="">'."\n";
			echo \ActaLibre\Securite\Jeton::champ();
			echo '<h2 align="center">Confirmation de la suppression</h2>';

			echo '<p class="message">Vous allez supprimer l\'acte '.$ntype.' du '.\ActaLibre\Securite\Filtre::stocke($ligne[2])."</p>";
			echo '<p class="message">('.\ActaLibre\Securite\Filtre::stocke($ligne[0]." ".$ligne[1]);
			if (isset($ligne[5])) echo ' et '.\ActaLibre\Securite\Filtre::stocke($ligne[5]." ".$ligne[6]);
			echo ')</p>';
			echo '<p class="message">';
			echo '<input type="hidden" name="xtyp" value="'.\ActaLibre\Securite\Filtre::html($xtyp).'" />';
			echo '<input type="hidden" name="xid"  value="'.(int)$xid.'" />';
			echo '<input type="hidden" name="xconfirm" value="confirmed" />';
			echo '<input type="submit" value=" >> CONFIRMER LA SUPPRESSION >> " />'."\n";
			$comdep=$ligne[3].' ['.$ligne[4].']';
			$url=mkurl($script,stripslashes($comdep),$ligne[0]);
			echo '&nbsp; &nbsp; &nbsp; <a href="'.\ActaLibre\Securite\Filtre::html($url).'">Annuler</a></p>';
			echo "</form>\n";
			}
			else
			{
    	msg('Impossible de trouver cet acte !');
			}
		} // confirmed ??
	}
echo '</div>';
close_page(1,$root);
?>
