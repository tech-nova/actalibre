<?php
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

function envoyer_liens_reinitialisation($email)
	{
	global $u_db, $root;
	$res = \ActaLibre\Db\Requete::executer($u_db,"SELECT * FROM ".EA_UDB."_user3 WHERE email=?",[$email]);
	if (!$res)
		return;
	$plafond = null;
	while ($user = EA_sql_fetch_array($res))
		{
		if (!compte_autorise($user))
			continue;
		$plafond ??= plafond_courriel_courant()->accepter($email);
		if (!$plafond)
			return;
		$lien = lien_reinitialisation($user['ID'],\ActaLibre\Compte\Reinitialisation::DUREE_OUBLI);
		if ($lien === null)
			{
			writelog('Lien MDP NON créé',$user['login'],0);
			continue;
			}
		$lb = "\r\n";
		$message  = "Bonjour,".$lb.$lb;
		$message .= "Une demande de nouveau mot de passe a été faite pour votre compte sur :".$lb;
		$message .= adresse_publique().$root."/index.php".$lb.$lb;
		$message .= "Votre login : ".$user['login'].$lb.$lb;
		$message .= "Pour choisir un nouveau mot de passe, suivez ce lien, valable une heure et une seule fois :".$lb;
		$message .= $lien.$lb.$lb;
		$message .= "Si vous n'êtes pas à l'origine de cette demande, ignorez ce courriel : votre mot de passe actuel reste valable.".$lb.$lb;
		$message .= "Cordialement,".$lb.$lb."Votre webmestre.".$lb;
		$sujet = "Votre mot de passe pour ".SITENAME;
		$sender = mail_encode(SITENAME).' <'.LOC_MAIL.">";
		// sendmail() affiche ses erreurs : la page ne doit pas dépendre de l'adresse saisie.
		ob_start();
		$okmail = sendmail($sender,$user['email'],$sujet,$message);
		ob_end_clean();
		writelog($okmail ? 'Lien MDP envoyé' : 'Lien MDP NON envoyé',$user['login'],0);
		}
	}

$root = "";
$path = "";

$xcomm=$xpatr=$page="";
pathroot($root,$path,$xcomm,$xpatr,$page);

$ok = false;
$destinataire = null;

open_page("ExpoActes : Renvoi codes d'accès",$root,null,null,null,'../index.htm');
navigation($root,2,"R","Renvoi des codes d'accès");


echo '<div id="col_menu">'."\n";
menu_public();
show_pub_menu();
echo '</div>'."\n";
echo '<div id="col_main">'."\n";

if (getparam('submit') <> '')
	{
	if(getparam('email')=="")
		{
		msg('Vous devez fournir votre adresse email');
		}
	else
		{
		$email = getparam('email');
		$cleEssai = "courriel:".$email;
		$limiteur = limiteur_courant();
		if (!$limiteur->bloque($cleEssai,adresse_visiteur()))
			{
			$limiteur->noterEchec($cleEssai,adresse_visiteur());
			$destinataire = $email;
			}
		// Même message que l'adresse soit connue ou non.
		echo "<p>Si cette adresse correspond à un compte, un courriel vient d'être envoyé. Il contient un lien valable une heure pour choisir un nouveau mot de passe.</p>";
		echo '<p><a href="'.$root.'/login.php"><b>Retour à la page de connexion</b></a></p>';
		$ok = true;
		}
	}

if (!$ok)
	{

	echo "<h2>Mot de passe oublié</h2>"."\n";
	echo "<p>Indiquez l'adresse mail associée à votre compte d'utilisateur : un lien pour choisir un nouveau mot de passe vous sera envoyé.</p>"."\n";

	echo '<form id="log" method="post" action="">'."\n";
	echo '<table align="center" summary="Formulaire">'."\n";
	echo '<tr><td align="right">Adresse e-mail : </td><td><input name="email" /></td></tr>'."\n";
	echo '<tr><td colspan="2" align="center"><input type="submit" name="submit" value=" Envoyer " /></td></tr>'."\n";
	echo '</table>'."\n";
	echo '</form>'."\n";

	echoln( '<p><a href="'.$root.'/acces.php">Voir les conditions d\'accès à la partie privée du site</a></p>'."\n");

	echoln( '<p>&nbsp;</p>'."\n");
	}
echo '</div>'."\n";
close_page(1,$root);
if ($destinataire !== null)
	{
	terminer_reponse();
	envoyer_liens_reinitialisation($destinataire);
	}
?>
