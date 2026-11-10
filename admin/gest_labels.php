<?php
if (file_exists('tools/_COMMUN_env.inc.php')){
	$EA_Appel_dOu = '';
} else {
	$EA_Appel_dOu = '../';
}
include($EA_Appel_dOu.'tools/_COMMUN_env.inc.php');

//define('EA_MASTER',"Y"); // pour editer les zones "Techniques"

//------------------------------------------------------------------------------

function show_grp($grp,$current,$barre)
	{
	global $bases;
	if ($barre)
		echo ' | ';	  
	if ($grp==$current)
		echo '<strong>'.$bases[$grp].'</strong>';
	 else
		echo '<a href="gest_labels.php?file='.$grp.'">'.$bases[$grp].'</a>';
	}			

//------------------------------------------------------------------------------

function alaligne($texte)
  {
  // insert des BR pour provoquer des retour à la ligne
  $order   = ["\r\n", "\n", "\r"];
	$replace = '<br />';
	// Traitement du premier \r\n, ils ne seront pas convertis deux fois.
	return str_replace($order, $replace, $texte);
  }
  
//------------------------------------------------------------------------------

$root = "";
$path = "";
$lg = "fr";

//**************************** ADMIN **************************

pathroot($root,$path,$xcomm,$xpatr,$page);

$userlogin="";
$userlevel=logonok(9);
while ($userlevel<9)
  {
  login($root);
  }
exiger_jeton_si(getparam('xconfirm') <> '');

open_page("Paramétrage des étiquettes",$root);
navadmin($root,"Paramétrage des étiquettes");
?>
<script type="text/javascript">

function changesigle() 
	{
	form = document.forms["labels"];
	form.chsigle.value = "1";
	form.submit();
	return true;
	}

</script>
<?php
//{ print '<pre>';  print_r($_REQUEST); echo '</pre>'; }

echo '<div id="col_menu">';
form_recherche();
menu_admin($root,$userlevel);
echo '</div>';

echo '<div id="col_main_adm">';

menu_software('Q');

echo '<h2>Gestions des étiquettes des données</h2>';

$ok = false;
$missingargs=false;

$xfile  = getparam('file');
$xconfirm = getparam('xconfirm');
$lesigle = getparam('SIGLE');
$chsigle = getparam('chsigle'); // 1 : on vient de le changer --> reconstruire la liste

if ($xfile == '')
	{
	// Données postées
	$xfile="N";
  $missingargs=true;  // par défaut
  }
$files = ['N','M','D','V','X'];
if (!in_array($xfile,$files,true))
	{
	$xfile="N";
	$missingargs=true;
	}
$bases = ['N'=>'Naissances', 'M'=>'Mariages', 'D'=>'Décès', 'V'=>'Divers (par défaut)','X'=>'Divers spécifiques'];
$grpes = ['A0'=>'Technique','A1'=>'Document','D1'=>'Intéressé','D2'=>'Parents intéressé','F1'=>'Second intéressé','F2'=>'Parents 2d intéressé','T1'=>'Témoins','V1'=>'Références','W1'=>'Crédits','X0'=>'Gestion'];
$gspec = ['D1','D2','F1','F2','T1'];
$barre = false;
echo '<p align="center"><strong>Bases : </strong>';
foreach ($files as $file)
  {
	show_grp($file,$xfile,$barre);
	$barre = true;
  }
echo '</p>';


//{ print '<pre>';  print_r($_REQUEST); echo '</pre>'; }

if (!$missingargs)
	{
	$oktype=true;

	if ($xconfirm == 'default')  // etiquettes "normales"
		{
		// *** Vérification des données reçues
		$parnbr = getparam("parnbr");
		$i = 1;
		$cpt = 0;
		while ($i <= $parnbr)
			{
			$pzid  = getparam("zid_$i");
			$paffi = getparam("affi_$i");
			$petiq = htmlentities(getparam("etiq_$i"), ENTITY_REPLACE_FLAGS, ENTITY_CHARSET);
			if ($petiq <> "")  // interdit de mettre à blanc
				{
				$request = "update ".EA_DB."_metadb set affich = ? where ZID = ?";
				//echo "<p>".$request;
				$result = \ActaLibre\Db\Requete::executer(EA_sql_which_link(),$request,[trim($paffi),$pzid]);
				$cpt += EA_sql_affected_rows();
				$request = "update ".EA_DB."_metalg set etiq = ? where ZID = ? and LG='fr'" ;
				$result = \ActaLibre\Db\Requete::executer(EA_sql_which_link(),$request,[trim($petiq),$pzid]);
				$cpt += EA_sql_affected_rows();
				}
			$i++;
			}	
		$grpnbr = getparam("grpnbr");
		$j = 1;
		while ($j <= $grpnbr)
			{
			$grp  = getparam("grp_$j");
			$getiq = htmlentities(getparam("group_$j"), ENTITY_REPLACE_FLAGS, ENTITY_CHARSET);
			if ($getiq <> "")  // interdit de mettre à blanc
				{
				$request = "update ".EA_DB."_mgrplg set getiq = ? where grp = ? and LG='fr' and dtable=?" ;
				$result = \ActaLibre\Db\Requete::executer(EA_sql_which_link(),$request,[trim($getiq),$grp,$xfile]);
				$tt = EA_sql_affected_rows();
				//if ($tt>0) echo '<p>'.$request;
				$cpt += $tt;
				}
			$j++;
			}	
		if ($cpt > 0)
		  msg("Sauvegarde : ".$cpt." valeur(s) modifiée(s).","info");
		} // etiquettes "normale"
		
	if ($xconfirm == 'specifique' and $chsigle==0)  // etiquettes "spécifiques" des actes divers
		{
		// *** Vérification des données reçues
		$grpnbr = getparam("grpnbr");
		$j = 1;
		$cpt = 0;
		while ($j <= $grpnbr)
			{
			$grp  = getparam("grp_$j");
			$getiq = htmlentities(getparam("group_$j"), ENTITY_REPLACE_FLAGS, ENTITY_CHARSET);
			//echo "<p>!".$getiq."==".grp_label($grp,'V',$lg)."!";
			if ($getiq <> "" and $getiq<>grp_label($grp,'V',$lg)) // si non vide et pas valeur par défaut   
				{
				$request = "select count(*) as CPT from ".EA_DB."_mgrplg where lg=? and dtable='V' and grp=? and sigle=?";
				$result = \ActaLibre\Db\Requete::executer(EA_sql_which_link(),$request,[$lg,$grp,$lesigle]);
				$row = EA_sql_fetch_array($result);
				if ($row["CPT"] > 0)
					{
					$request = "update ".EA_DB."_mgrplg set getiq = ? where grp = ? and LG='fr' and dtable='V' and sigle=?" ;
					$parametres = [trim($getiq),$grp,$lesigle];
					}
					else
					{
					$request = "insert into ".EA_DB."_mgrplg (grp,dtable,lg,sigle,getiq) value (?,'V',?,?,?)";
					$parametres = [$grp,$lg,$lesigle,trim($getiq)];
					}
				$result = \ActaLibre\Db\Requete::executer(EA_sql_which_link(),$request,$parametres);
				$tt = EA_sql_affected_rows();
			  //echo '<p>'.$request;
				$cpt += $tt;
				}
			elseif ($lesigle <> "") // vide ou valeur par défaut : l'étiquette spécifique n'a plus lieu d'être
				{
				$request = "delete from ".EA_DB."_mgrplg where grp = ? and lg = ? and dtable='V' and sigle = ?";
				\ActaLibre\Db\Requete::executer(EA_sql_which_link(),$request,[$grp,$lg,$lesigle]);
				$cpt += EA_sql_affected_rows();
				}
			$j++;
			}	
		if ($cpt > 0)
		  msg("Sauvegarde : ".$cpt." valeur(s) modifiée(s).","info");
		}


		}	
echo '<h2 align="center">Etiquettes des '.$bases[$xfile].'</h2>';
echo '<form method="post" action="" name="labels">'."\n";
echo \ActaLibre\Securite\Jeton::champ();
echo '<table cellspacing="3" cellpadding="1" border="0" summary="Formulaire">'."\n";

if ($xfile == "X")
	{
	$sigle = "";
	$j=0;
	$prog = "gest_labels.php?file=".$xfile;
	
	echo " <tr>\n";
	echo '  <td align="right"><b>Sigle des actes divers : </b></td>'."\n";
	echo '  <td>';

	$request = "select distinct SIGLE from ".EA_DB."_div3 where length(SIGLE)>0 order by SIGLE";
	optimize($request);
	$result = EA_sql_query($request);
	//echo $request;
	$default='';
	if ($result = EA_sql_query($request))
		{
		$i = 1;
		echo '<select name="SIGLE" onchange="changesigle()">'."\n";
		while ($row = EA_sql_fetch_array($result))
			{
			echo '<option '.selected_option($row["SIGLE"],$lesigle).'>'.\ActaLibre\Securite\Filtre::stocke($row["SIGLE"]).'</option>'."\n";
			$i++;
			}
		}
	echo " </select>\n";
  echo "</td></tr>\n";
	
  echo "<tr>\n";
	echo '  <td align="right"><b>Libellé concerné : </b></td>'."\n";
	echo '<td>';
	$request = "select distinct LIBELLE from ".EA_DB."_div3 where SIGLE=? order by LIBELLE";
	if ($result = \ActaLibre\Db\Requete::executer(EA_sql_which_link(),$request,[$lesigle]))
		{
		$i = 1;
		while ($row = EA_sql_fetch_array($result))
			{
			echo ''.\ActaLibre\Securite\Filtre::stocke($row["LIBELLE"]).'<br />'."\n";
			$i++;
			}
		}
  echo "</td></tr>\n";

	echo '<tr><th>Zone</th><th>Etiquette spécifique</th></tr>';
	foreach($gspec as $curgrp)
		{
		$grptxt = grp_label($curgrp,'V',$lg,$lesigle);
		$j++;
		echo '<tr class="row0">'."\n";
		echo ' <input type="hidden" name="grp_'.$j.'"  value="'.\ActaLibre\Securite\Filtre::stocke($curgrp).'" />'."\n";
		echo '  <td align="left"><i>&nbsp; '.$grpes[$curgrp]."</i> </td>\n";
		echo ' <td><input type="text" name="group_'.$j.'" size="30" maxlength="50" value="'.\ActaLibre\Securite\Filtre::stocke($grptxt).'" /></td>';
		echo '</tr>';			
		}

	echo ' <tr><td align="right">'."\n";
	echo '<input type="hidden" name="grpnbr"  value="'.$j.'" />'."\n";
	echo '<input type="hidden" name="chsigle"  value="0" />'."\n"; 
	echo '<input type="hidden" name="file"  value="'.\ActaLibre\Securite\Filtre::html($xfile).'" />'."\n";
	echo '<input type="hidden" name="xconfirm" value="specifique" />'."\n";
	} // cas des groupes spéciaux
 else
	{ // cas des etiquettes par défaut
	$notech = "";
	$leschoix=["F - Si non vide","O - Toujours","A - Administration","M - Inutilisé"];
	if (defined('EA_MASTER'))
		array_push($leschoix,"T - Technique");
		else
		$notech = " and affich<>'T' ";
		
	$request = "select * from (".EA_DB."_metadb d join ".EA_DB."_metalg l) where d.zid=l.zid and LG='fr' and dtable=?" .$notech." order by GROUPE,OV3";
	$result = \ActaLibre\Db\Requete::executer(EA_sql_which_link(),$request,[$xfile]);
	//echo $request;
	$i=0;
	$j=0;
	$prog = "gest_labels.php?file=".$xfile;

	echo '<tr><th>Zone</th><th>Affichage</th><th>Etiquette</th></tr>';
	$curgrp="AA"; 
	while ($row = EA_sql_fetch_array($result))
		{
		$i++;
		if ($row["groupe"]<>$curgrp)
			{
			$curgrp = $row["groupe"];
			$grptxt = grp_label($curgrp,$xfile,$lg);
			$j++;
			echo '<tr class="row0">'."\n";
			echo '  <td align="right"><b><i>Groupe : &nbsp;</i></b></td>'."\n";
			echo ' <input type="hidden" name="grp_'.$j.'"  value="'.\ActaLibre\Securite\Filtre::stocke($curgrp).'" />'."\n";
			echo '  <td align="left"><i>&nbsp; '.$grpes[$curgrp]."</i> </td>\n";
			echo ' <td><input type="text"   name="group_'.$j.'" size="30" maxlength="50" value="'.\ActaLibre\Securite\Filtre::stocke($grptxt).'" /></td>';
			echo '</tr>';			
				}
		echo ' <tr class="row1">'."\n";
		echo '  <td align="left"><b>'.\ActaLibre\Securite\Filtre::stocke($row["zone"])."</b> : </td>\n";
		echo '<td>';
		if (mb_substr($row["zone"],-3)=="PRE")
			{
			echo 'Avec le nom'."\n";
			}
			else
			{
			echo '<select name="affi_'.$i.'">'."\n";
			foreach($leschoix as $lechoix)
				{
				echo '<option '.selected_option(mb_substr($lechoix,0,isin($lechoix,"-",0)-1),$row["affich"]).'>'.mb_substr($lechoix,isin($lechoix,"-",0)+1).'</option>'."\n";
				}
			echo " </select>\n";
			}
		echo '  </td>';
		echo '  <td>';
		echo ' <input type="hidden" name="zid_'.$i.'"  value="'.\ActaLibre\Securite\Filtre::stocke($row["ZID"]).'" />'."\n";
		echo ' <input type="text"   name="etiq_'.$i.'" size="30" maxlength="50" value="'.\ActaLibre\Securite\Filtre::stocke($row["etiq"]).'" />';
		echo '  </td>';
		echo " </tr>\n";
		}
	echo ' <tr><td align="right">'."\n";
	echo '<input type="hidden" name="grpnbr"  value="'.$j.'" />'."\n";
	echo '<input type="hidden" name="parnbr"  value="'.$i.'" />'."\n";
	echo '<input type="hidden" name="file"  value="'.\ActaLibre\Securite\Filtre::html($xfile).'" />'."\n";
	echo '<input type="hidden" name="xconfirm" value="default" />'."\n";
	}
echo '<a href="index.php">Annuler</a> &nbsp; &nbsp;</td>'."\n";
echo '<td><input type="submit" value="ENREGISTRER" /></td>'."\n";
echo "</tr></table>\n";
echo "</form>\n";
echo '</div>';
close_page(1,$root);
?>
