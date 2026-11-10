<?php
use ActaLibre\Securite\Filtre;
// Page d'accueil publique du programmes ExpoActes
// Copyright (C) : André Delacharlerie, 2005-2006
// Ce programme est libre, vous pouvez le redistribuer et/ou le modifier selon les termes de la
// Licence Publique Générale GNU, version 2 (GPLv2), publiée par la Free Software Foundation
// Texte de la licence : https://www.gnu.org/licenses/old-licenses/gpl-2.0.fr.html
//-------------------------------------------------------------------
if (file_exists('tools/_COMMUN_env.inc.php')){
	$EA_Appel_dOu = '';
} else {
	$EA_Appel_dOu = '../';
}
include($EA_Appel_dOu.'tools/_COMMUN_env.inc.php');
$carto ??= null;

$xtyp = getparam('xtyp');
$act = getparam('act');
$init = getparam('init');
$vue = getparam('vue'); // T = Tableau / C = Carte
if ($vue!="C" and $vue!="T") $vue = "";
if ($vue == "" and GEO_MODE_PUBLIC%2==1)
	$vue = "C";
if (($vue == "" and GEO_MODE_PUBLIC%2==0) or $init<>"")
	$vue = "T";

$root = "";
$xpatr= "";
$page = "";
pathroot($root,$path,$xtyp,$xpatr,$page);


if ($act=="logout")
	{
	exiger_jeton_si(isset($_COOKIE[\ActaLibre\Compte\Session::COOKIE]));
	fermer_session();
	header("Location: ".$root."/index.php");
	die();
	}

$userlogin="";

//{ print '<pre>INDEX:';  print_r($_SERVER); echo '</pre>'; }
//{ print '<pre>INDEX:';  print_r($_REQUEST); echo '</pre>'; }
global $u_db;

$userlevel=logonok(1);
if ($userlevel==0)
	{
	login($root);
	}
	
if ($xtyp=="")
	$xtyp  = getparam('xtyp');
$xtyp = Filtre::typeActe($xtyp);

if ($xtyp=="" or $xtyp=='A')
	{
  if (SHOW_ALLTYPES==1) 
  	$xtyp='A'; 
    else 
    $xtyp='N';
  }

$chemin="/";
if (GEO_MODE_PUBLIC==5 or $vue=="C")  // si pas localité isolée et avec carte
	{
	if (GEO_HAUT_CARTE=="") $geo_haut_carte=400;
		else $geo_haut_carte=GEO_HAUT_CARTE;
	$carto = new \ActaLibre\Carte\Carte("100%",(int)$geo_haut_carte);
	include("tools/carto_index.php");
	if (GEO_ZOOM_DEGROUPAGE=="") $geo_degroupage=10; 
	  else $geo_degroupage=(int)GEO_ZOOM_DEGROUPAGE;
	$carto->regrouper($geo_degroupage); // plus de regroupement au dela de ce niveau de zoom
  if (GEO_CENTRE_CARTE <> "")
		{
		$georeq = "select LON,LAT from ".EA_DB."_geoloc where COMMUNE = '".sql_quote(GEO_CENTRE_CARTE)."' and STATUT in ('A','M')";
		$geores =  EA_sql_query($georeq);
		if ($geo = EA_sql_fetch_array($geores))
			{
			$carto->centre((float)$geo['LON'],(float)$geo['LAT']);
			}
		}
	if (GEO_ZOOM_INITIAL=="") $geo_zoom=0; 
		else $geo_zoom=(int)GEO_ZOOM_INITIAL;
	if ($geo_zoom > 0) 
		{
		$carto->zoom($geo_zoom);
		}

	$JSheader = $carto->enTete($root);
	}
	else
	$JSheader = "";

open_page(SITENAME." : Dépouillement d'actes de l'état-civil et des registres paroissiaux",$root,null,null,$JSheader,'../index.htm','rss.php');
navigation($root,1);

echoln('<div id="col_menu">');
form_recherche();
$menu_actes = statistiques($vue);
menu_public();
show_pub_menu();
show_certifications();

echo '</div>';

echo '<div id="col_main">';

if (strlen(trim(AVERTISMT))>0)
  {
  if (isin(AVERTISMT,"</p>")>0)
  	echo AVERTISMT; // HTML rédigé par un compte de niveau 9
  else
  	echo '<p>'.AVERTISMT.'</p>'; // HTML rédigé par un compte de niveau 9
  }	
echo '<h2>Communes et paroisses';
if (GEO_MODE_PUBLIC>=3 and GEO_MODE_PUBLIC<5)
	{
	echo " : ";
	if ($xtyp=="")
		$argtyp = "";
		else
		$argtyp = "&xtyp=".$xtyp;
	if ($vue=="C")
		echo "Carte";
		else
		echo '<a href="'.Filtre::html($root.$chemin.'index.php?vue=C'.$argtyp).'">Carte</a>';
	echo " | ";
	if ($vue=="T")
		echo "Tableau";
		else
		echo '<a href="'.Filtre::html($root.$chemin.'index.php?vue=T'.$argtyp).'">Tableau</a>';
	}
echo '</h2>';

if (GEO_MODE_PUBLIC==5 or $vue=="C")  // si pas localité isolée et avec carte
	{
	echo '<p><b>'.$menu_actes.'</b></p>';
	//--- Carte
	echo $carto->afficher();
	}

// --- module principal
if (GEO_MODE_PUBLIC==5 or $vue=="T")  // si pas localité isolée et avec carte
	{
	// $menu_actes calculé dans le module statistiques
	echo '<p><b>'.$menu_actes.'</b></p>';
	include("tools/tableau_index.php");
	}
echo '<p>&nbsp;</p>';
include("_config/commentaire.htm");

echo '</div>';
close_page(1,$root);
