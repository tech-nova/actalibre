<?php
if (file_exists('tools/_COMMUN_env.inc.php')){
	$EA_Appel_dOu = '';
} else {
	$EA_Appel_dOu = '../';
}
include($EA_Appel_dOu.'tools/_COMMUN_env.inc.php');
$action ??= null; $dtcreation ??= null; $level ??= null; $libre ??= null; $maj_solde ??= null; $prenom ??= null;
$pt_conso ??= null; $regime ??= null; $rem ??= null; $solde ??= null; $statut ??= null; $u_db ??= null;
$udbaddr ??= null; $udbuser ??= null;

pathroot($root,$path,$xcomm,$xpatr,$page);

$id  = \ActaLibre\Securite\Filtre::entier(getparam('id')) ?? '';
$act = getparam('act');

$userlogin="";
$userlevel=logonok(9);
while ($userlevel<9)
  {
  login($root);
  }
exiger_jeton_si(getparam('action') == 'submitted' or getparam('act') == 'del');

$sendmail   = ischecked('SendMail');
$autopw     = ischecked('autopw');
$xdroits    = getparam('lelevel');
$xregime    = getparam('regime');
$message    = getparam('Message');
$nom        = getparam('nom');
$email      = getparam('email');
$dtexpir    = getparam('dtexpir');

if (getparam('action') == 'submitted')
  {
  setcookie("chargeUSERparam", $sendmail.$xdroits.$xregime, \ActaLibre\Compte\Cookie::options(time()+60*60*24*60));  // 60 jours
  // setcookie("chargeUSERmessage", $message, time()+60*60*24*180);  // 180 jours
  }

open_page("Gestion des utilisateurs",$root);

navadmin($root,"Gestion des utilisateurs");

echo '<div id="col_menu">';
form_recherche();
menu_admin($root,$userlevel);

echo '</div>';

echo '<div id="col_main_adm">';

if ($id==-1) 
	menu_users('A');
	else
	menu_users('1'); // ce qui affiche le lien vers ajouter !

if (isset($udbname))
	{
	msg('ATTENTION : Données ajoutées/modifiées dans '.\ActaLibre\Securite\Filtre::stocke($udbaddr."/".$udbuser."/".$udbname."/".EA_UDB)."</p>",'info');
	}
	

$missingargs=true;

//print '<pre>';  print_r($_REQUEST); echo '</pre>';

$lelogin=getparam('lelogin');
$lepassw=\ActaLibre\Compte\MotDePasse::lirePost('lepassw');
$leid=$id;

// Données postées -> ajouter ou modifier
if (getparam('action') == 'submitted')
	{
	$ok = true;
	if(strlen($nom)<3)
	 {
	 msg('Vous devez préciser le nom de la personne');
	 $ok = false;
	 }
	if(!\ActaLibre\Securite\Filtre::courriel($email))
	 {
	 msg("Vous devez préciser une adresse email valide pour la personne");
	 $ok = false;
	 }
	if(strlen($lelogin)<3 or strlen($lelogin)>15)
	 {
	 msg('Vous devez donner un LOGIN d\'au moins 3 et au plus 15 caractères');
	 $ok = false;
	 }
	if(!sans_quote($lelogin))
	 {
	 msg('Vous ne pouvez pas mettre d\'apostrophe dans le LOGIN');
	 $ok = false;
	 }
  if ($autopw)
		$pw = MakeRandomPassword(32);
	 else
	  if ($id==-1 or !empty($lepassw)) 
			{
			if(strlen($lepassw)<\ActaLibre\Compte\MotDePasse::LONGUEUR_MINIMALE)
				{
				msg('Vous devez donner un MOT DE PASSE d\'au moins 6 caractères');
				$ok = false;
				}
			if($lepassw <> \ActaLibre\Compte\MotDePasse::lirePost('passwverif'))
				{
				msg('Les deux copies du MOT DE PASSE ne sont pas identiques');
				$ok = false;
				}
			$pw = $lepassw;	
			}
			else
			$pw = "";
	$res=\ActaLibre\Db\Requete::executer($u_db,"SELECT * FROM ".EA_UDB."_user3 WHERE login=? and id <> ?",[trim($lelogin),(int)$leid]);
	if (EA_sql_num_rows($res)!=0)
	  {
	  $row = EA_sql_fetch_array($res);
 	  msg('Ce code de login est déjà utilisé par "'.\ActaLibre\Securite\Filtre::stocke($row['prenom'].' '.$row['nom']).'" !');
	  $ok=false;
	  }
	if (TEST_EMAIL_UNIC==1)
		{
		$res=\ActaLibre\Db\Requete::executer($u_db,"SELECT * FROM ".EA_UDB."_user3 WHERE email=? and id <> ?",[trim(getparam('email')),(int)$leid]);
		if (EA_sql_num_rows($res)!=0)
			{
			$row = EA_sql_fetch_array($res);
			msg('Cette adresse email est déjà utilisé par "'.\ActaLibre\Securite\Filtre::stocke($row['prenom'].' '.$row['nom']).'" !');
			$ok=false;
			}
		}
	if ($ok)
		 {
		 $mes = "";
		 $adresseChangee = false;
		 if ($dtexpir=="")
		   $dtexpir=TOUJOURS;
		 if ($id <= 0)
			 {
			 $maj_solde = date("Y-m-d");
			 $reqmaj = "insert into ".EA_UDB."_user3 "
				."(nom, prenom, email, level, login, hashpass, regime, solde, maj_solde, statut, dtcreation, dtexpiration, rem, libre)"
				." values('"
				.sql_quote(getparam('nom'))."','"
				.sql_quote(getparam('prenom'))."','"
				.sql_quote(getparam('email'))."','"
				.sql_quote($xdroits)."','"
				.sql_quote($lelogin)."','"
				.sql_quote(\ActaLibre\Compte\MotDePasse::hacher($pw))."','"
				.sql_quote(getparam('regime'))."','"
				.sql_quote(getparam('solde'))."','"
				.sql_quote($maj_solde)."','"
				.sql_quote(getparam('statut'))."','"
				.sql_quote($maj_solde)."','"
				.sql_quote(date_sql($dtexpir))."','"
				.sql_quote(getparam('rem'))."','"
				.sql_quote(getparam('libre'))."')";
			  }
			 else
			  {
			  $missingargs=false;
			  if (getparam('solde') != getparam('soldepre'))
			    $maj_solde = date("Y-m-d");
			   else
					$maj_solde = $_REQUEST['maj_solde'];

			  $adresseChangee = (adresse_du_compte($id) !== getparam('email'));
			  $reqmaj = "update ".EA_UDB."_user3 set ";
			  $reqmaj = $reqmaj.
						 "NOM        = '".sql_quote(getparam('nom'))."', ".
						 "PRENOM     = '".sql_quote(getparam('prenom'))."', ".
						 "EMAIL      = '".sql_quote(getparam('email'))."', ".
						 "LEVEL      = '".sql_quote($xdroits)."', ".
						 "LOGIN      = '".sql_quote($lelogin)."', ";
				if ($pw<>"")		 
			  	$reqmaj = $reqmaj."HASHPASS   = '".sql_quote(\ActaLibre\Compte\MotDePasse::hacher($pw))."', ";		 
			  $reqmaj = $reqmaj.						 
						 "REGIME     = '".sql_quote(getparam('regime'))."', ".
						 "SOLDE      = '".sql_quote(getparam('solde'))."', ".
						 "MAJ_SOLDE  = '".sql_quote($maj_solde)."', ".
						 "DTEXPIRATION= '".sql_quote(date_sql($dtexpir))."', ".
						 "STATUT     = '".sql_quote(getparam('statut'))."', ".
						 "LIBRE      = '".sql_quote(getparam('libre'))."', ".
						 "REM        = '".sql_quote(getparam('rem'))."' ".
					 " where ID=".$id.";";
			    }
	     //echo "<p>".$reqmaj."</p>";

		 if  ($result = EA_sql_query($reqmaj,$u_db))
			{
			// echo '<p>'.EA_sql_error().'<br />'.$reqmaj.'</p>';
			if ($id > 0 and $pw <> "")
				{
				session_courante()->fermerToutes((int)$id);
				reinitialisation_courante()->supprimerCompte((int)$id);
				}
			// Un lien en attente a pu partir vers une adresse erronée.
			if ($id > 0 and ($adresseChangee or getparam('statut') !== 'N'))
				reinitialisation_courante()->supprimerCompte((int)$id);
			if ($id <= 0)
			  {
			  $log = "Ajout utilisateur";
				$bon_message = ($sendmail==1) ? message_compte_cree($message,$lelogin,getparam('nom'),getparam('prenom')) : null;
				if ($bon_message !== null)
					{
					$sujet = "Votre compte ".SITENAME;
					$sender = mail_encode(SITENAME).' <'.LOC_MAIL.">";
					$okmail = sendmail($sender,getparam('email'),$sujet,$bon_message);
					}
					else
					$okmail = false;
				if ($okmail)
					{
					$log.= " + mail";
					$mes = " et mail envoyé";
					}
					else
					$mes = " et mail PAS envoyé";
					
			  writelog($log,$lelogin,0);
			  }
			  else
			  {
			  writelog('Modification utilisateur ',$lelogin,0);
			  }
			echo '<p><b>Fiche enregistrée'.$mes.'.</b></p>';
			$id = 0;
			}
		  else
			{
			echo ' -> Erreur : ';
			erreur_sql($reqmaj);
			}
		 }
   }

if ($id > 0 and $act=="del")
	{
	$reqmaj = "delete from ".EA_UDB."_user3 where ID=?";
	if  ($result = \ActaLibre\Db\Requete::executer($u_db,$reqmaj,[$id]))
		{
		fermer_sessions_orphelines();
		writelog('Suppression utilisateur #'.$id,$lelogin,1);
		echo '<p><b>FICHE SUPPRIMEE.</b></p>';
		$id=0;
		}
	 else
		{
		echo ' -> Erreur : ';
		erreur_sql($reqmaj);
		}
	}

if ($id == -1)
	{  // Initialisation
	if (isset($_COOKIE['chargeUSERparam']))
		$chargeUSERparam=$_COOKIE['chargeUSERparam'];
		else
		$chargeUSERparam="042";
	$sendmail   = $chargeUSERparam[0];
	$xdroits    = $chargeUSERparam[1];
	$xregime    = $chargeUSERparam[2];
	$message    = MAIL_NEWUSER;  
	/* $_COOKIE['chargeUSERmessage'];
	if ($message=="")
	  {
	  $message  = def_mes_sendmail();
	  }
	*/
	$action = 'Ajout';
	$nom   = "";
	$prenom= "";
	$email = "";
	$lelogin = "";
	$lepassw = "";
	$level = $xdroits;
	$regime = $xregime;
	$solde  = PTS_PAR_PER;
	$maj_solde = today();
	$statut = "N";
	$dtcreation = $maj_solde;
	$dtexpir=dt_expiration_defaut();
	$pt_conso = 0;
	$rem   = "";
	$libre = "";
	}

if ($id > 0)
	{  //
	$action = 'Modification';
	$request = "select NOM, PRENOM, EMAIL, LEVEL, LOGIN, REGIME, SOLDE, MAJ_SOLDE, STATUT, DTCREATION, DTEXPIRATION, PT_CONSO, REM, LIBRE"
				." from ".EA_UDB."_user3 "
				." where ID =?";
				//echo '<P>'.$request;
	if ($result = \ActaLibre\Db\Requete::executer($u_db,$request,[$id]))
		{
		$row = EA_sql_fetch_array($result);
		$nom       = $row["NOM"];
		$prenom    = $row["PRENOM"];
		$email     = $row["EMAIL"];
		$level     = $row["LEVEL"];
		$lelogin   = $row["LOGIN"];
		$regime    = $row["REGIME"];
		$solde     = $row["SOLDE"];
		$maj_solde = $row["MAJ_SOLDE"];
		$statut    = $row["STATUT"];
		$dtcreation= $row["DTCREATION"];
		$dtexpir   = $row["DTEXPIRATION"];
		$pt_conso  = $row["PT_CONSO"];
		$rem       = $row["REM"];
		$libre     = $row["LIBRE"];
		}
	   else
		{
		echo "<p>*** FICHE NON TROUVEE***</p>";
		}
	}


//Si pas tout les arguments nécessaire, on affiche le formulaire
if((int)$id<>0 and $missingargs)
	{
	echo '<h2>'.$action." d'une fiche d'utilisateur</h2> \n";
//  echo '<form method="post" id="fiche" name="eaform" action="gestuser.php" onsubmit="return  pwProtect();">'."\n";
  echo '<form method="post" id="fiche" name="eaform" action="gestuser.php">'."\n";
  echo \ActaLibre\Securite\Jeton::champ();
	echo '<table cellspacing="0" cellpadding="1" border="0" summary="Formulaire">'."\n";

	echo " <tr>\n";
	echo "  <td align='right'>Nom : </td>\n";
	echo '  <td><input type="text" size="30" name="nom" value="'.\ActaLibre\Securite\Filtre::stocke($nom).'" />'."</td>\n";
	echo " </tr>\n";

	echo " <tr>\n";
	echo "  <td align='right'>Prénom : </td>\n";
	echo '  <td><input type="text" name="prenom" size="30" value="'.\ActaLibre\Securite\Filtre::stocke($prenom).'" />'."</td>\n";
	echo " </tr>\n";

	echo " <tr>\n";
	echo "  <td align='right'>E-mail : </td>\n";
	echo '  <td><input type="text" name="email" size="50" value="'.\ActaLibre\Securite\Filtre::stocke($email).'" />'."</td>\n";
	echo " </tr>\n";

	$zonelibre = USER_ZONE_LIBRE;
	if (!$zonelibre) $zonelibre = "Zone libre (à définir)";
	echo " <tr>\n";
	echo "  <td align='right'>".\ActaLibre\Securite\Filtre::stocke($zonelibre)." : </td>\n";
	echo '  <td><input type="text" name="libre" size="50" value="'.\ActaLibre\Securite\Filtre::stocke($libre).'" />'."</td>\n";
	echo " </tr>\n";

	echo " <tr><td colspan=2>&nbsp;</td></tr>\n";

	echo " <tr>\n";
	echo "  <td align='right'>Login : </td>\n";
	echo '  <td><input type="text" name="lelogin" size="15" maxlength="15" value="'.\ActaLibre\Securite\Filtre::stocke($lelogin).'" />'."</td>\n";
	echo " </tr>\n";

	if ($id == -1)
		$lecture = "text";
	 else
	  $lecture = "password";
	echo " <tr>\n";
	echo "  <td align='right'>Mot de passe : </td>\n";
	echo '  <td><input type="'.$lecture.'" name="lepassw" size="15" />';
	if ($id == -1)
		{
		echo ' &nbsp; <input type="checkbox" name="autopw" value="1" /> Mot de passe automatique&nbsp; ';
		}
	echo "</td>\n";
	echo " </tr>\n";
	echo " <tr>\n";
	echo "  <td align='right'>Mot de passe (vérif.) : </td>\n";
	echo '  <td><input type="'.$lecture.'" name="passwverif" size="15" />'."</td>\n";
	echo " </tr>\n";

	echo " <tr><td colspan=2>&nbsp;</td></tr>\n";

	echo " <tr>\n";
	echo "  <td align='right'>Statut : </td>\n";
	echo '  <td>';
	lb_statut_user($statut);
	if (USER_AUTO_DEF==1 and ($statut=="A" or $statut=="W"))
		{
		$urlapp = "approuver_compte.php?id=".$id."&action=";
		echo ' --> <a href="'.\ActaLibre\Securite\Filtre::html($urlapp.'OK').'">Approuver</a> ou ';
		echo ' <a href="'.\ActaLibre\Securite\Filtre::html($urlapp.'KO').'">Refuser</a> ';
		}
	echo '  </td>';
	echo " </tr>\n";

	echo " <tr>\n";
	echo "  <td align='right'>Date entrée : </td>\n";
	echo '  <td>';
	if ($dtcreation!=null)
		echo showdate($dtcreation,'S');
		else
		echo '- Inconnue -';
	echo "</td>\n";	
	echo " </tr>\n";

	echo " <tr>\n";
	echo "  <td align='right'>Date expiration : </td>\n";
	if ($dtexpir < today()) 
		$expiralert = '&nbsp; <b><span style="color:red">EXPIREE</span></b>';
		else
		$expiralert = "";
	$dtexpir = showdate($dtexpir,'S');
	echo '  <td><input type="text" name="dtexpir" size="10" value="'.\ActaLibre\Securite\Filtre::stocke($dtexpir).'" />'.$expiralert."</td>\n";
	echo " </tr>\n";

	echo " <tr>\n";
	echo "  <td align='right'>Droits d'accès : </td>\n";
	echo '  <td>';
	lb_droits_user($level);
	echo '  </td>';
	echo " </tr>\n";

	if (GEST_POINTS>0)
		{
		echo " <tr><td colspan=2>&nbsp;</td></tr>\n";
		echo " <tr>\n";
		echo "  <td align='right'>Régime (points) : </td>\n";
		echo '  <td>';
		 lb_regime_user($regime);
		echo '  </td>';
		echo " </tr>\n";

		echo " <tr>\n";
		echo "  <td align='right'>Solde de points : </td>\n";
		echo '  <td><input type="text" name="solde" size="5" value="'.\ActaLibre\Securite\Filtre::stocke($solde).'" />';
		echo '<input type="hidden" name="soldepre" value="'.\ActaLibre\Securite\Filtre::stocke($solde).'" />';  // pour test si maj
		echo "</td>\n";
		echo " </tr>\n";

		echo " <tr>\n";
		echo "  <td align='right'>Dernière recharge : </td>\n";
		echo '  <td>'.date("d-m-Y",strtotime($maj_solde))."</td>\n";
		echo " </tr>\n";
		echo " <tr><td colspan=2>&nbsp;</td></tr>\n";

		echo " <tr>\n";
		echo "  <td align='right'>Points consommés : </td>\n";
		echo '  <td>'.(int)$pt_conso."</td>\n";
		echo " </tr>\n";
		echo " <tr><td colspan=2>&nbsp;</td></tr>\n";
		}
	 else
		{
		echo ' <tr><td colspan="2">';
		echo '<input type="hidden" name="regime" value="'.\ActaLibre\Securite\Filtre::stocke($regime).'" />';
		echo '<input type="hidden" name="solde" value="'.\ActaLibre\Securite\Filtre::stocke($solde).'" />';
		echo '<input type="hidden" name="soldepre" value="'.\ActaLibre\Securite\Filtre::stocke($solde).'" />';
		echo "</td></tr>\n";
		}

	 echo " <tr>\n";
	 echo "  <td align='right'>Commentaire : </td>\n";
	 echo '  <td><input type="text" name="rem" size="50" value="'.\ActaLibre\Securite\Filtre::stocke($rem).'" />';
	 echo '<input type="hidden" name="maj_solde" value="'.\ActaLibre\Securite\Filtre::stocke($maj_solde).'" />';
	 echo "</td>\n";
	 echo " </tr>\n";

if ($id == -1)
	{
	echo " <tr>\n";
	echo '  <td align="right">Envoi des codes d\'accès : </td>'."\n";
	echo '  <td>';
	echo '    <input type="checkbox" name="SendMail" value="1"'.checked($sendmail).' />Envoi automatique du mail ci-dessous&nbsp; ';
	echo '  </td>';
	echo " </tr>\n";

	echo ' <tr>'."\n";
	echo "  <td align='right'>Texte du mail : </td>\n";
	echo '  <td>';
	echo '<textarea name="Message" cols=50 rows=6>'.\ActaLibre\Securite\Filtre::stocke($message).'</textarea>';
	echo '  </td>';
	echo " </tr>\n";
	}

	 echo " <tr>\n";
	 echo '  <td colspan="2">&nbsp;</td>'."\n";
	 echo " </tr>\n";

	 echo " <tr><td align=\"right\">\n";
	 echo '  <input type="hidden" name="id" value="'.\ActaLibre\Securite\Filtre::html($id).'" />';
	 echo '  <input type="hidden" name="action" value="submitted" />';
	 echo '  <a href="aide/gestuser.html" target="_blank">Aide</a>&nbsp;';
	 echo '  <input type="reset" value=" Effacer " />'."\n";
	 echo " </td><td align=\"left\">\n";
	 echo ' &nbsp; <input type="submit" value=" *** ENREGISTRER *** " />'."\n";
	 if ($id>0 and $level<9)
	   {
	   echo ' &nbsp; &nbsp; &nbsp; <a href="gestuser.php?id='.(int)$id.'&amp;act=del&amp;'.\ActaLibre\Securite\Jeton::lien().'">Supprimer cet utilisateur</a>'."\n";
	   }
	 echo " </td></tr>\n";
	 echo "</table>\n";
	 echo "</form>\n";
  }
 else
  {
	 echo '<p align="center"><a href="listusers.php">Retour à la liste des utilisateurs</a>';
	 if ($leid > 0 and $act!="del")
	   echo '&nbsp;|&nbsp; <a href="gestuser.php?id='.(int)$leid.'">Retour à la fiche de '.\ActaLibre\Securite\Filtre::html(getparam('prenom')." ".getparam('nom')).'</a>';
	 if ($leid ==-1 and $act!="del")
	   echo '&nbsp;|&nbsp; <a href="gestuser.php?id=-1">Ajout d\'une autre fiche'.'</a>';	  
	 echo '</p>';
  }
echo '</div>';
close_page(1);
?>
