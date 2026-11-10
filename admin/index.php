<?php
// Page d'accueil ADMIN du programmes ExpoActes
// Copyright (C) : André Delacharlerie, 2005-2007
// Ce programme est libre, vous pouvez le redistribuer et/ou le modifier selon les termes de la
// Licence Publique Générale GNU, version 2 (GPLv2), publiée par la Free Software Foundation
// Texte de la licence : https://www.gnu.org/licenses/old-licenses/gpl-2.0.fr.html
//-------------------------------------------------------------------
//ob_start(); //Pour éviter de tout recevoir en un seul bloc
//ob_implicit_flush(1);

define ("ADM",1); // Mode admin;

if (file_exists('tools/_COMMUN_env.inc.php')){
	$EA_Appel_dOu = '';
} else {
	$EA_Appel_dOu = '../';
}
include($EA_Appel_dOu.'tools/_COMMUN_env.inc.php');
	
$root = "";
$path = "";

//**************************** ADMIN **************************
//{ print '<pre>';  print_r($_POST); echo '</pre>'; }

//$xtyp="A";
pathroot($root,$path,$xtyp,$xpatr,$page);

$init  = getparam('init');

$userlogin="";
$needlevel=6;  // niveau d'accès (anciennement 5)
$userlevel=logonok($needlevel);
while ($userlevel<$needlevel)
	{
	login($root);
	}

open_page("Administration des actes",$root);
$missingargs=true;
$emailfound=false;
$oktype=false;
$cptact = 0;
$cptfil = 0;
navadmin($root,'');

echo '<div id="col_menu">';
form_recherche();
menu_admin($root,$userlevel);

if ($xtyp=="")
	$xtyp  = getparam('xtyp');
if ($xtyp=="") 
	$xtyp='A'; 


echo '</div>';

echo '<div id="col_main">';

echo '<h1 align="center">Administration des actes &amp; tables</h1>';

//{ print '<pre>';  print_r($lines); echo '</pre>'; }

if ( (! check_version(phpversion(),"8.3.0")) or (! check_version("8.4.999",phpversion())) )
    {
    echo '<p class="erreur">Vous utilisez ExpoActes sur une version de PHP '.phpversion().' non validée.<br /></p>';
    }
$chemin="/admin/";  
$menu_actes = "";
if ($xtyp=="N") $menu_actes .= "Naissances"." | ";
	else $menu_actes .= '<a href="'.\ActaLibre\Securite\Filtre::html(mkurl($root.$chemin."index.php","N")).'">'."Naissances"."</a> | ";
if ($xtyp=="M") $menu_actes .= "Mariages"." | ";
	else $menu_actes .= '<a href="'.\ActaLibre\Securite\Filtre::html(mkurl($root.$chemin."index.php","M")).'">'."Mariages"."</a> | ";
if ($xtyp=="D") $menu_actes .= "Décès"." | ";
	else $menu_actes .= '<a href="'.\ActaLibre\Securite\Filtre::html(mkurl($root.$chemin."index.php","D")).'">'."Décès"."</a> | ";
if ($xtyp=="V") $menu_actes .= "Divers";
	else $menu_actes .= '<a href="'.\ActaLibre\Securite\Filtre::html(mkurl($root.$chemin."index.php","V")).'">'."Divers"."</a>";
if ($xtyp=="A") $menu_actes .= " | Tous";
	else $menu_actes .= ' | <a href="'.\ActaLibre\Securite\Filtre::html(mkurl($root.$chemin."index.php","A")).'">'."Tous".'</a>';
	
echo '<p><b>'.$menu_actes.'</b></p>';
	
// --- module principal
include("../tools/tableau_index.php");

// verification des statistiques
$request = "select sum(NB_TOT) as nb_sum from ".EA_DB."_sums where TYPACT='N'";
$result = EA_sql_query($request);
$row = EA_sql_fetch_row($result);
$nb_sum = $row[0];
$request = "select count(*) as nb_cnt from ".EA_DB."_nai3";
$result = EA_sql_query($request);
$row = EA_sql_fetch_row($result);
$nb_cnt = $row[0];
if ($nb_sum <> $nb_cnt and $nb_cnt>0)
  {
  msg("Attention : les statistiques doivent être recalculées");
  echo '<p><a href="maj_sums.php"><b>Calcul des statistiques</b></a></p>';
  }



echo '</div>';

close_page(1,$root);
?>
