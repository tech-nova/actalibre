<?php
if (file_exists('tools/_COMMUN_env.inc.php')){
	$EA_Appel_dOu = '';
} else {
	$EA_Appel_dOu = '../';
}
include($EA_Appel_dOu.'tools/_COMMUN_env.inc.php');
$u_db ??= null;

global $loc_mail;
if (AUTO_CAPTCHA)
	session_start();  // pour captcha

pathroot($root,$path,$xcomm,$xpatr,$page);

open_page("Créer mon compte utilisateur",$root);
navigation($root,2,"","Création de mon compte");

echo '<div id="col_menu">'."\n";
menu_public();
show_pub_menu();
echo '</div>'."\n";
echo '<div id="col_main_adm">'."\n";

if (USER_AUTO_DEF==0)
	{
  echo "<p><b>Désolé : Cette action n'est pas autorisée sur ce site</b></p>";
  echo "<p>Vous devez contacter le gestionnaire du site pour demander un compte utilisateur</p>";
  echo '</div>';
	close_page(1);
  die();
  }
  
function fin_message_inscription($okmail)
	{
	if ($okmail)
		return " et un mail vous a été envoyé pour l'ACTIVER."
			."<br />Si vous ne recevez pas de mail, merci d'en avertir l'administrateur afin qu'il active votre compte.";
	return " mais le mail n'a pas pu être envoyé : contactez l'administrateur du site pour le faire activer.";
	}

function avertir_titulaire($email)
	{
	global $root;
	$lb = "\r\n";
	$message  = "Bonjour,".$lb.$lb;
	$message .= "Une création de compte a été demandée avec votre adresse sur :".$lb;
	$message .= adresse_publique().$root."/index.php".$lb.$lb;
	$message .= "Cette adresse a déjà un compte : aucun nouveau compte n'a été créé.".$lb.$lb;
	$message .= "Si vous avez oublié votre mot de passe, vous pouvez en choisir un nouveau ici :".$lb;
	$message .= adresse_publique().$root."/renvoilogin.php".$lb.$lb;
	$message .= "Si vous n'êtes pas à l'origine de cette demande, ignorez ce courriel.".$lb.$lb;
	$message .= "Cordialement,".$lb.$lb."Votre webmestre.".$lb;
	return sendmail(mail_encode(SITENAME).' <'.LOC_MAIL.">",$email,"Votre compte ".SITENAME,$message);
	}

$missingargs=true;
$lelogin =getparam('lelogin');
$lepassw =\ActaLibre\Compte\MotDePasse::lirePost('lepassw');
$leid    =getparam('id');
$email   =getparam('email');
$emailverif=getparam('emailverif');
$libre   =getparam('libre');
$accept  =getparam('acceptcond');

//print '<pre>';  print_r($_REQUEST); echo '</pre>';
$ok = false;

// Données postées -> ajouter ou modifier
if (getparam('action') == 'submitted')
	{
	$ok = true;
	if(!isset($_REQUEST['nom']))
	 {
	 msg('Vous devez préciser votre nom de famille');
	 $ok = false;
	 }
	if(!isset($_REQUEST['prenom']))
	 {
	 msg('Vous devez préciser votre prénom');
	 $ok = false;
	 }
	if(!\ActaLibre\Securite\Filtre::courriel($email))
	 {
	 msg("Vous devez préciser une adresse email valide");
	 $ok = false;
	 }
	if($email <> getparam('emailverif'))
		{
		msg('Les deux copies de l\'adresse e-mail ne sont pas identiques');
		$ok = false;
		}
	$zonelibre = USER_ZONE_LIBRE;
	if ($zonelibre and strlen($libre)<2)
		{
		msg('Vous devez compléter la zone ['.\ActaLibre\Securite\Filtre::stocke($zonelibre).']');
		$ok = false;
		}
	$txtconduse = TXT_CONDIT_USAGE;
	if ($txtconduse and strlen($accept)==0)
		{
		msg("Vous devez marquer votre accord sur les conditions d'utilisation");
		$ok = false;
		}
	if(!sans_quote($lelogin))
		{
		msg('Vous ne pouvez pas mettre d\'apostrophe dans le LOGIN');
		$ok = false;
		}
	if(strlen($lelogin)<3 or strlen($lelogin)>15)
		{
		msg('Vous devez donner un LOGIN d\'au moins 3 et au plus 15 caractères');
		$ok = false;
		}
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
	if (AUTO_CAPTCHA and !\ActaLibre\Securite\Captcha::verifier(getparam('captcha')))
		{
		msg('Attention à bien recopier le code dissimulé dans l\'image !');
		$ok = false;
		}
	$pw = $lepassw;	
	$res=EA_sql_query("SELECT * FROM ".EA_UDB."_user3 WHERE login='".sql_quote($lelogin)."'",$u_db);
	if (EA_sql_num_rows($res)!=0)
	  {
	  $row = EA_sql_fetch_array($res);
 	  msg('Ce code de login est déjà utilisé par un autre utilisateur, choissisez-en un autre.');
	  $ok=false;
	  }
	$res=EA_sql_query("SELECT * FROM ".EA_UDB."_user3 WHERE email='".sql_quote($email)."'",$u_db);
	$adresseConnue = (EA_sql_num_rows($res)!=0);
	if ($ok and $adresseConnue)
		{
		// Même réponse qu'une inscription : seul le titulaire de l'adresse apprend qu'elle a déjà un compte.
		\ActaLibre\Compte\MotDePasse::hacher($pw);
		$okmail = false;
		if (adresse_publique_enregistree() !== '')
			$okmail = (!plafond_courriel_courant()->accepter($email) || avertir_titulaire($email));
		writelog('Inscr. adresse prise',$lelogin,0);
		echo '<p><b>Votre compte a été créé'.fin_message_inscription($okmail).'</b></p>';
		$id = 0;
		$missingargs=false;
		}
	elseif ($ok)
		 {
		 $missingargs=false;
		 $clevalid = MakeRandomPassword(15);
		 $dtexpir=dt_expiration_defaut();
		 $mes = "";
		 $maj_solde = date("Y-m-d");
		 $reqmaj = "insert into ".EA_UDB."_user3 "
			."(nom, prenom, email, level, login, hashpass, regime, solde, maj_solde, statut, dtcreation, dtexpiration, pt_conso, libre, rem)"
			." values('"
			.sql_quote(getparam('nom'))."','"
			.sql_quote(getparam('prenom'))."','"
			.sql_quote($email)."','"
			.sql_quote(USER_AUTO_LEVEL)."','"  // level 
			.sql_quote($lelogin)."','"
			.sql_quote(\ActaLibre\Compte\MotDePasse::hacher($pw))."','"
			.sql_quote(GEST_POINTS)."','"  // regime
			.sql_quote(PTS_PAR_PER)."','"  // solde courant
			.sql_quote($maj_solde)."','"   // date maj du solde
			.sql_quote('W')."','"          // statut : toujours attendre validation de l'email (W)
			.sql_quote($maj_solde)."','"   // dtcreation
			.sql_quote( $dtexpir)."','"    // dtexpiration
			.sql_quote('0')."','"          // pt déjà consommés   
			.sql_quote($libre)."','"       // zone libre (si utilisée)  
			.sql_quote($clevalid)."')";    // Clé pour la validation du compte email dans REM

	     //echo "<p>".$reqmaj."</p>";

		 if  ($result = EA_sql_query($reqmaj,$u_db))
			{
			// echo '<p>'.EA_sql_error().'<br />'.$reqmaj.'</p>';
			$log = "Créat. auto user";
			$crlf = chr(10).chr(13);
			if (USER_AUTO_DEF==1)
				$message = MAIL_VALIDUSER;
			 else
				$message = MAIL_AUTOUSER;
			
			$urlvalid = adresse_publique().$root."/activer_compte.php?login=".$lelogin."&key=".$clevalid.$crlf.$crlf;
			$urlsite = adresse_publique().$root."/index.php";
			$codes = ["#NOMSITE#","#URLSITE#","#LOGIN#","#PASSW#","#NOM#","#PRENOM#","#URLVALID#","#KEYVALID#"];
			$decodes = [SITENAME, $urlsite, $lelogin, "celui que vous avez choisi", getparam('nom'), getparam('prenom'), $urlvalid, $clevalid];
			$bon_message = str_replace($codes, $decodes, $message);
			$sujet = "Votre compte ".SITENAME;
			$sender = mail_encode(SITENAME).' <'.LOC_MAIL.">";
			$okmail = (adresse_publique_enregistree() !== '') ? sendmail($sender,$email,$sujet,$bon_message) : false;
			if ($okmail)
				{
				$log.= " + mail";
				}
				else
				{
				$log.= " NO mail";
				}
			$mes = fin_message_inscription($okmail);
					
			  writelog($log,$lelogin,0);
			echo '<p><b>Votre compte a été créé'.$mes.'</b></p>';
			$id = 0;
			}
		  else
			{
			echo ' -> Erreur : ';
			erreur_sql($reqmaj);
			}
		 }
   }


//Si pas tout les arguments nécessaire, on affiche le formulaire
if(!$ok)
	{
	$id = -1;
	$action = 'Ajout';
	$nom   = getparam('nom');
	$prenom= getparam('prenom');
	
	echo '<h2>Création de mon compte d\'utilisateur</h2>'."\n";
	echo '<form method="post"  action="">'."\n";
	echo '<table cellspacing="0" cellpadding="1" border="0" summary="Formulaire">'."\n";

	echo " <tr>\n";
	echo '  <td align="right">'."Nom : </td>\n";
	echo '  <td><input type="text" size="30" name="nom" value="'.\ActaLibre\Securite\Filtre::html($nom).'" />'."</td>\n";
	echo " </tr>\n";

	echo " <tr>\n";
	echo '  <td align="right">'."Prénom : </td>\n";
	echo '  <td><input type="text" name="prenom" size="30" value="'.\ActaLibre\Securite\Filtre::html($prenom).'" />'."</td>\n";
	echo " </tr>\n";

	$zonelibre = USER_ZONE_LIBRE;
	if ($zonelibre)
		{
		echo " <tr>\n";
		echo '  <td align="right">'.\ActaLibre\Securite\Filtre::stocke($zonelibre).": </td>\n";
		echo '  <td><input type="text" name="libre" size="50" value="'.\ActaLibre\Securite\Filtre::html($libre).'" />'."</td>\n";
		echo " </tr>\n";
		}
	echo " <tr>\n";
	echo '  <td align="right">'."E-mail : </td>\n";
	echo '  <td><input type="text" name="email" size="50" value="'.\ActaLibre\Securite\Filtre::html($email).'" />'."</td>\n";
	echo " </tr>\n";

	echo " <tr>\n";
	echo '  <td align="right">'."E-mail (vérification) : </td>\n";
	echo '  <td><input type="text" name="emailverif" size="50" value="'.\ActaLibre\Securite\Filtre::html($emailverif).'" />'."</td>\n";
	echo " </tr>\n";

	echo " <tr>\n";
	echo '  <td align="right">'."Login : </td>\n";
	echo '  <td><input type="text" name="lelogin" size="15" maxlength="15" value="'.\ActaLibre\Securite\Filtre::html($lelogin).'" />'."</td>\n";
	echo " </tr>\n";

	$lecture = "password";
	echo " <tr>\n";
	echo '  <td align="right">'."Mot de passe : </td>\n";
	echo '  <td><input type="'.$lecture.'" name="lepassw" size="15" value="" />';
	echo "</td>\n";
	echo " </tr>\n";
	echo " <tr>\n";
	echo '  <td align="right">'."Mot de passe (vérification) : </td>\n";
	echo '  <td><input type="'.$lecture.'" name="passwverif" size="15" value="" />'."</td>\n";
	echo " </tr>\n";

	if (TXT_CONDIT_USAGE<>"")
		{
		echo " <tr>\n";
		echo ' <td align="right">'."Conditions d'utilisation : </td>\n";
		echo ' <td><textarea name="captcha" cols="60" rows="10" readonly>'.\ActaLibre\Securite\Filtre::stocke(TXT_CONDIT_USAGE)."</textarea><br />\n";
		echo ' <input type="checkbox" name="acceptcond">'."J'ai lu et j'accepte les conditions ci-dessus.</input> <br />&nbsp;</td>\n";
		echo " </tr>\n";
		}

	if (AUTO_CAPTCHA)
		{
		echo " <tr>\n";
		if (function_exists('imagettftext'))
			{
			echo '  <td align="right"><img src="tools/captchas/image.php" alt="captcha" id="captcha" /></td>'."\n";
			}
		 else
		 {
		 	msg('061 : Librairie GD indisponible');
			echo '  <td align="right">Code captcha manquant</td>'."\n";
			}
		echo '  <td>Recopiez le code ci-contre : <br />';
		echo '<input type="text" name="captcha" size="6" maxlength="5" value="" />'."</td>\n";
		echo " </tr>\n";
		}

	echo " <tr>\n";
	echo '  <td colspan="2">&nbsp;</td>'."\n";
	echo " </tr>\n";

	
	echo " <tr><td align=\"right\">\n";
	echo '  <input type="hidden" name="id" value="'.(int)$id.'" />';
	echo '  <input type="hidden" name="action" value="submitted" />';
	echo '  <input type="reset" value=" Effacer " />'."\n";
	echo " </td><td align=\"left\">\n";
	echo ' &nbsp; <input type="submit" value=" *** INSCRIVEZ-MOI *** " />'."\n";
	echo " </td></tr>\n";
	echo "</table>\n";
	echo "</form>\n";
  }
 else
  {
	 echo '<p align="center"><a href="index.php">Retour à la page d\'accueil</a></p>';
  }
echo '</div>';
close_page(1);
?>
