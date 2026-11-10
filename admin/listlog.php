<?php
if (file_exists('tools/_COMMUN_env.inc.php')){
	$EA_Appel_dOu = '';
} else {
	$EA_Appel_dOu = '../';
}
include($EA_Appel_dOu.'tools/_COMMUN_env.inc.php');
$u_db ??= null;

//define ("OPTIMIZE",1);
$MT0 = microtime_float();
$root = "";
$path = "";
$xcomm = "";
$xpatr = "";
$page = 1;

pathroot($root,$path,$xcomm,$xpatr,$page);

$xord  = getparam('xord');
if ($xord == "")
  {$xord = "D";}   // N = Nom
$page  = getparam('pg');
$xdel  = \ActaLibre\Securite\Filtre::entier(getparam('xdel')) ?? 0;
$xfilter = getparam('xfilter');

$userlogin="";
$userlevel=logonok(9);
while ($userlevel<9)
	{
	login($root);
	}

exiger_jeton_si($xdel>31);

open_page(SITENAME." : Activité du site",$root);
//{ print '<pre>';  print_r($_REQUEST); echo '</pre>'; }

navadmin($root,"Activité du site");

echo '<div id="col_menu">';
form_recherche();
menu_admin($root,$userlevel);
echo '</div>';

echo '<div id="col_main_adm">';
menu_software('J');

// Suppression des informations anciennes
if ($xdel>31)
	{
	$request = "delete from ".EA_DB."_log where datediff(curdate(),DATE)>?";
	$result = \ActaLibre\Db\Requete::executer(EA_sql_which_link(),$request,[$xdel]);
	$nb = EA_sql_affected_rows();
	echo $nb." ligne(s) suprimée(s)."; // .$datedel;
	}
echo '<p><a href="?xdel=365&amp;'.\ActaLibre\Securite\Jeton::lien().'">'."Supprimer les événements âgés de plus d'un an</a></p>";
// Lister les actions
echo '<h2>Activité sur les données du site '.\ActaLibre\Securite\Filtre::stocke(SITENAME).'</h2>';

echo '<center><form method="post" action="">'."\n";
echo '<input type="text" name="xfilter" value="" />'."\n";
echo '&nbsp; &nbsp;<input type="submit" value="FILTRER" /></td>'."\n";
echo '</form></center>';

$baselink = $root.'/admin/listlog.php'."?xfilter=".urlencode($xfilter);
if ($xord =="N")
	{
	$order = "NOM, PRENOM, DATE desc";
	$hdate = '<a href="'.\ActaLibre\Securite\Filtre::html($baselink.'&xord=D').'">Date et heure</a>';
	$hcomm = '<a href="'.\ActaLibre\Securite\Filtre::html($baselink.'&xord=C').'">Commune/Paroisse</a>';
	$baselink = $baselink.'&xord=N';
	$hnoms = '<b>Utilisateur</b>';
	}
 elseif ($xord =="D")
	{
	$order = "DATE desc";
	$hcomm = '<a href="'.\ActaLibre\Securite\Filtre::html($baselink.'&xord=C').'">Commune/Paroisse</a>';
	$hnoms = '<a href="'.\ActaLibre\Securite\Filtre::html($baselink.'&xord=N').'">Utilisateur</a>';
	$baselink = $baselink.'&xord=D';
	$hdate = '<b>Date et heure</b>';
	}
 else
	{
	$order = "COMMUNE, DATE desc";
	$hdate = '<a href="'.\ActaLibre\Securite\Filtre::html($baselink.'&xord=D').'">Date et heure</a>';
	$hnoms = '<a href="'.\ActaLibre\Securite\Filtre::html($baselink.'&xord=N').'">Utilisateur</a>';
	$baselink = $baselink.'&xord=L';
	$hcomm = '<b>Commune/Paroisse</b>';
	}
	$baselink .= "&xfilter=".urlencode($xfilter);
	
	$request = "create temporary table temp_user3 (ID int(11), nom varchar(30), prenom varchar(30), PRIMARY KEY (ID))";
	$result = EA_sql_query($request);

	$request = "select ID,NOM,PRENOM from ".EA_UDB."_user3";
	$result = EA_sql_query($request,$u_db);
	while ($ligne = EA_sql_fetch_row($result))
			{
			$treq = "insert into temp_user3 values (?,?,?)";
			$tres = \ActaLibre\Db\Requete::executer(EA_sql_which_link(),$treq,[(int)$ligne[0],$ligne[1],$ligne[2]]);
			//echo "<br>".$treq;
			}

	$request = "select NOM, PRENOM, ID, DATE, ACTION, COMMUNE, NB_ACTES"
				." from ".EA_DB."_log left join temp_user3 on (temp_user3.id=".EA_DB."_log.user)";
	$parametres = [];
	if ($xfilter <> "")
		{
		$request .= " where COMMUNE like ? or ACTION like ? or NOM like ?";
		$parametres = array_fill(0,3,'%'.$xfilter.'%');
		}
	$request .= " order by ".$order;

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
	  echo '<p>'.$listpages.'</p>';
	  $i = 1+($page-1)*MAX_PAGE;
	  echo '<table summary="Liste des actions">';
	  echo '<tr class="rowheader">';
	 // echo '<th> Tri : </th>';
	  echo '<th>'.$hdate.'</th>';
	  echo '<th>'.$hnoms.'</th>';
	  echo '<th>'.$hcomm.'</th>';
	  echo '<th>Action</th>';
	  echo '<th>Actes</th>';
	  echo '</tr>';

	  while ($ligne = EA_sql_fetch_row($result))
			{
			echo '<tr class="row'.(fmod($i,2)).'">';
	//		echo '<td>'.$i.'. </td>';
			echo '<td>'.\ActaLibre\Securite\Filtre::stocke($ligne[3]).' </td>';
			echo '<td align="center">'.\ActaLibre\Securite\Filtre::stocke($ligne[0].' '.$ligne[1]).'</td>';
			echo '<td align="center">'.\ActaLibre\Securite\Filtre::stocke($ligne[5]).'</td>';
			echo '<td>'.\ActaLibre\Securite\Filtre::stocke($ligne[4]).' </td>';
			echo '<td>'.(int)$ligne[6].' </td>';
			echo '</tr>';
			$i++;
			}
	  echo '</table>';
	  echo '<p>'.$listpages.'</p>';
	  }
	 else
	  {
	  msg('Aucune action enregistrée');
	  }

echo '</div>';
echo '<p>Durée du traitement  : '.round(microtime_float()-$MT0,3).' sec.</p>'."\n";
close_page(1);
