<?php
if (file_exists('tools/_COMMUN_env.inc.php')){
	$EA_Appel_dOu = '';
} else {
	$EA_Appel_dOu = '../';
}
include($EA_Appel_dOu.'tools/_COMMUN_env.inc.php');

$root = "";
$path = "";
$xcomm = "";
$xpatr = "";
$page = 1;

pathroot($root,$path,$xcomm,$xpatr,$page);

$xord  = getparam('xord');
if ($xord == "")
  {$xord = "N";}   // N = Nom
$page  = getparam('pg');
$init  = getparam('init');


$userlogin="";
$userlevel=logonok(9);
while ($userlevel<9)
  {
  login($root);
  }
exiger_jeton_si(getparam('action') == 'recenser');

open_page(SITENAME." : Liste des localités (communes et paroisses)",$root);

navadmin($root,"Liste des localités");

echo '<div id="col_menu">';
form_recherche();
menu_admin($root,$userlevel);
echo '</div>';

echo '<div id="col_main_adm">';

// Lister les actes

menu_datas('L');

if (getparam('action') == 'recenser' and $_SERVER['REQUEST_METHOD'] === 'POST')
	{
	$recensement = new \ActaLibre\Geoloc\Recensement(EA_sql_which_link(), EA_DB, geoloc_fiches());
	$etat = ['table' => (int)($_POST['recensement_table'] ?? 0), 'apres' => is_string($_POST['recensement_apres'] ?? null) ? $_POST['recensement_apres'] : '', 'crees' => 0, 'termine' => false];
	$crees = 0;
	$limite = time() + max(5, (int)ini_get('max_execution_time') - 10);
	while (!$etat['termine'] and time() < $limite)
		{
		$etat = $recensement->tranche($etat['table'], $etat['apres']);
		$crees += $etat['crees'];
		}
	echo '<p>'.$crees.' localité(s) ajoutée(s) sans coordonnées.</p>';
	if ($etat['termine'])
		echo '<p><b>Recensement terminé.</b></p>';
	 else
		{
		echo '<form method="post" action="listgeolocs.php">'.\ActaLibre\Securite\Jeton::champ();
		echo '<input type="hidden" name="action" value="recenser" />';
		echo '<input type="hidden" name="recensement_table" value="'.(int)$etat['table'].'" />';
		echo '<input type="hidden" name="recensement_apres" value="'.\ActaLibre\Securite\Filtre::html($etat['apres']).'" />';
		echo '<p>Recensement incomplet. <button type="submit">Continuer le recensement</button></p></form>';
		}
	}

echo '<h2>Localités connues du site '.\ActaLibre\Securite\Filtre::stocke(SITENAME).'</h2>';

$baselink = $root.'/admin/listgeolocs.php';
// $request = "select distinct upper(left(COMMUNE,1)) as init from ".EA_DB."_geoloc order by init";
// Sélectionner et grouper sur initiale de commune et ascii(initiale), ordonner code ascii ascendant pour avoir + grand code (accentué) en dernier
$request = "select  alphabet.init  from ( select upper(left(COMMUNE,1)) as init,ascii(upper(left(COMMUNE,1)))  as oo from ".EA_DB."_geoloc group by init,oo  order by init , oo asc) as alphabet group by init";

$result = EA_sql_query($request);
$alphabet = "";
while ($row = EA_sql_fetch_row($result))
  {
  if ($row[0]==$init)
  	$alphabet .= '<b>'.\ActaLibre\Securite\Filtre::stocke($row[0]).'</b> ';
  else
  	$alphabet .= '<a href="'.\ActaLibre\Securite\Filtre::html($baselink.'?xord='.urlencode($xord).'&init='.urlencode($row[0])).'">'.\ActaLibre\Securite\Filtre::stocke($row[0]).'</a> ';
  }
echo '<form method="post" action="listgeolocs.php" class="geoloc-recensement">'.\ActaLibre\Securite\Jeton::champ();
echo '<input type="hidden" name="action" value="recenser" />';
echo '<p>Des localités présentes dans les actes peuvent manquer à cette liste. <button type="submit">Recenser les localités des actes</button> (aucun service extérieur n\'est contacté)</p></form>';
echo '<p align="center">'.$alphabet.'</p>';

if ($init=="")
  $initiale = '';
 else
  $initiale = '&init='.urlencode($init);

$hcommune = '<a href="'.\ActaLibre\Securite\Filtre::html($baselink.'?xord=C'.$initiale).'">Commune</a>';
$hdepart  = '<a href="'.\ActaLibre\Securite\Filtre::html($baselink.'?xord=D'.$initiale).'">Département</a>';
$hgeoloc  = '<a href="'.\ActaLibre\Securite\Filtre::html($baselink.'?xord=S'.$initiale).'">Géolocalisation</a>';
$baselink = $baselink.'?xord='.urlencode($xord).$initiale;
  
if ($xord =="C")
	{
	$order = "COMMUNE,DEPART";
	$hcommune = '<b>Commune</b>';
	}
 elseif ($xord =="D")
	{
	$order = "DEPART, COMMUNE";
	$hdepart = '<b>Département</b>';
	}
 elseif ($xord =="S")
	{
	$order = "find_in_set(STATUT,'N,I,M,A'), COMMUNE, DEPART";
	$hgeoloc = '<b>Géolocalisation</b>';
	}
 else
	{
	$order = "COMMUNE,DEPART";
	$hcommune = '<b>Commune</b>';
	}
	$parametres = [];
	if ($init=="")
	  $condit = "";
	 else
	  {
	  $condit = " where COMMUNE like ? ";
	  $parametres[] = $init.'%';
	  }
	 

	$request = "select ID,COMMUNE,DEPART,LON,LAT,STATUT"
				." from ".EA_DB."_geoloc "
				.$condit
				." order by ".$order;
  //echo $request;
	$result = \ActaLibre\Db\Requete::executer(EA_sql_which_link(),$request,$parametres);
	$nbtot = EA_sql_num_rows($result);

	$limit="";
	$listpages="";
	pagination($nbtot,$page,$baselink,$listpages,$limit);

  if ($limit<>"")
    {
		$request = $request.$limit;
		$result = \ActaLibre\Db\Requete::executer(EA_sql_which_link(),$request,$parametres);
		$nb = EA_sql_num_rows($result);
		}
	else
	  {
	  $nb = $nbtot;
	  }

	if ($nb > 0)
	  {
	  if ($listpages<>"")
	  	echo '<p>'.$listpages.'</p>';
	  $i = 1+($page-1)*MAX_PAGE_ADM;
	  echo '<table summary="Liste des localités">';
	  echo '<tr class="rowheader">';
	  echo '<th> Tri : </th>';
	  echo '<th>'.$hcommune.'</th>';
	  echo '<th>'.$hdepart.'</th>';
	  echo '<th>'.$hgeoloc.'</th>';
	  echo '</tr>';

    
	  while ($ligne = EA_sql_fetch_array($result))
			{
			echo '<tr class="row'.(fmod($i,2)).'">';
			echo '<td>'.$i.'. </td>';
			$lenom = $ligne['COMMUNE'];
			if (trim($lenom)=="") $lenom = '&lt;non précisé&gt;';
			echo '<td><a href="'.$root.'/admin/gestgeoloc.php?id='.(int)$ligne['ID'].'">'.\ActaLibre\Securite\Filtre::stocke($lenom).'</a> </td>';
			echo '<td>'.\ActaLibre\Securite\Filtre::stocke($ligne['DEPART']).' </td>';
			echo '<td align="center">'.\ActaLibre\Geoloc\Statut::libelle((string)$ligne['STATUT']).'</td>';
			echo '</tr>';
			$i++;
			}
	  echo '</table>';
	  if ($listpages<>"")
	  	echo '<p>'.$listpages.'</p>';
	  }
	 else
	  {
	  msg('Aucune localité : utilisez « Recenser les localités des actes ».');
	  }

echo '</div>';

close_page(1);
