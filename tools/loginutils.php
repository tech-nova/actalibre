<?php
// Copyright (C) : André Delacharlerie, 2005-2010
// Ce programme est libre, vous pouvez le redistribuer et/ou le modifier selon les termes de la
// Licence Publique Générale GNU, version 2 (GPLv2), publiée par la Free Software Foundation
// Texte de la licence : https://www.gnu.org/licenses/old-licenses/gpl-2.0.fr.html

//------------------------------------------------------------------------
//   Fonctions pour autentification générale  Version 3 : avec cryptage SHA-1 et expiration des comptes
//   Adaptée pour travail avec une base user déportée dans la connexion $u_db (V3.1)
//------------------------------------------------------------------------
require('traceIP/trace_ip.php');
if (!defined("TIP_LEVEL_NO_IP_TEST")) define ("TIP_LEVEL_NO_IP_TEST",9);

function current_user($zone)
	{
	 global $userlogin, $u_db;
	 if ($userlogin=="")
	   return 0;  // non connecté
	  else
	   {
	   $sql = "SELECT * FROM ".EA_UDB."_user3 WHERE login = '".sql_quote($userlogin)."'";
	   $res=EA_sql_query($sql,$u_db);
	   if ($res and EA_sql_num_rows($res)!=0)
	     {
	     $row = EA_sql_fetch_array($res);
	     return $row[$zone];
	     }
	    else
	     {return 0;}
	   }
	}

//------------------------------------------------------------------------

function logonok($level=0)
	{
	global $root,$userlogin,$statut,$expirok,$u_db;

  if (!defined("EA_MAINTENANCE")) define("EA_MAINTENANCE",0);
	effacer_anciens_cookies();
	$niveau = null;
	// Autentification PHP
	if (isset($_POST['login']))  // si on présente un login, on le teste de suite
		{
		$userid = 0;
		$statut = "";
		$expirok = true;
		$login = \ActaLibre\Compte\MotDePasse::lirePost('login');
		$limiteur = limiteur_courant();
		// Au-delà de la limite, le mot de passe n'est pas testé et la réponse reste celle d'un échec ordinaire.
		$userlevel = $limiteur->bloque($login,adresse_visiteur()) ? 0 : CheckUser($login,\ActaLibre\Compte\MotDePasse::lirePost('passwd'),$userid);
		if ($userlevel==0)
			$limiteur->noterEchec($login,adresse_visiteur());
			else
			$limiteur->oublierCouple($login,adresse_visiteur());
        $t = ["W" => 5, "A" => 5, "B" => 7];
		if($userlevel==0)
			{
			if (!$expirok)
				$cas=6;
			 elseif (array_key_exists($statut, $t))
				$cas = $t[$statut];
			 else
				$cas=1;
			header("Location: ".$root."/login.php?cas=".$cas."&uri=".urlencode($_SERVER['REQUEST_URI']));
			die();
			}
		 else
			{
			$rester = (($_POST['saved'] ?? '') === 'yes');
			$session = session_courante();
			$jeton = $session->ouvrir((int)$userid,$rester);
			if ($jeton !== null)
				poser_cookie_session($jeton,$session->expirationCookie($rester));
			$niveau = $userlevel;
			$userlogin = $login;
			if ($userlevel >= LEVEL_MAIL_LOGIN)
				{
				$lb        = "\r\n";
				$Vdate = date("d/m/Y à H:i");
				$array_server_values = $_SERVER;
				$Vua   = $array_server_values['HTTP_USER_AGENT'] ?? 'Inconnu';
				$Vip   = $array_server_values['REMOTE_ADDR'];
				$message  = "Information sécurité : Vous vous êtes connecté au site suivant ".$lb;
				$message .= "".$lb;
				$message .= adresse_publique().$root."/index.php".$lb;
				$message .= "".$lb;
				$message .= "avec le login [".$login."] ce ".$Vdate.$lb;
				$message .= "".$lb;
				$message .= "Votre adresse IP est :".$Vip.$lb;
				$message .= "Votre client est : ".$Vua.$lb;
				$sujet = "Confirmation de login sur ".SITENAME;
				$sender = mail_encode(SITENAME).' <'.LOC_MAIL.">";
				$okmail = sendmail($sender,current_user('email'),$sujet,$message);
				}
			}
		}
	elseif (isset($_COOKIE[\ActaLibre\Compte\Session::COOKIE]) and is_string($_COOKIE[\ActaLibre\Compte\Session::COOKIE]))
		{
		$niveauSession = CheckSession($_COOKIE[\ActaLibre\Compte\Session::COOKIE]);  // mets à jour $userlogin
		if ($niveauSession > 0)
			$niveau = $niveauSession;
			else
			effacer_cookie_session();
		}
	if ($niveau === null)
		{
		if ($level <= PUBLIC_LEVEL)
			{
			$niveau = PUBLIC_LEVEL;  // ne pas (re)positionner userlogin
			}
		else
			{
			header("Location: ".$root."/login.php?cas=2&uri=".urlencode($_SERVER['REQUEST_URI']));
			die();
			}
		}
	if ($niveau < $level) $niveau = 0;
	if (!(EA_MAINTENANCE==0 or $niveau==9))
		$niveau=0;
	recharger_solde();
	// test IP 
	if ($niveau < TIP_LEVEL_NO_IP_TEST && $niveau!=5)
	  {
		traceip();
	  }
	return $niveau;
	}

//------------------------------------------------------------------------

// À appeler avant toute sortie, avec la condition d'écriture de la page.
function exiger_jeton_si($ecriture)
	{
	global $root;
	if (!$ecriture or \ActaLibre\Securite\Jeton::verifier())
		return;
	http_response_code(403);
	open_page("Action non exécutée",$root);
	echo '<div id="col_main">'."\n";
	msg("Cette action n'a pas été exécutée, car la page n'est plus valable, par exemple après la fin de votre session. Merci de recharger la page et de recommencer.");
	echo '<p><a href="'.$root.'/index.php">Retour à la page d\'accueil</a></p>'."\n";
	echo '</div>'."\n";
	close_page(1,$root);
	exit;
	}

//------------------------------------------------------------------------

function login($path): never  // Uniquement utilisé par authentification
	{
	global $root;
	header("Location: ".$root."/login.php?cas=3&uri=".urlencode($_SERVER['REQUEST_URI']));
	die();
	}

//------------------------------------------------------------------------

function compte_autorise($row)
	{
	return ($row["level"]==9 or $row["statut"]=='N');
	}

function compte_non_expire($row)
	{
	return ($row["statut"]!='N' or $row["dtexpiration"] >= date("Y-m-d",time()) or $row["level"]==9);
	}

function CheckUser($login,$pw,&$userid)
	// Vérification réelle du droit d'accès
  {
  global $statut, $expirok, $u_db;

	$res = \ActaLibre\Db\Requete::executer($u_db,"SELECT * FROM ".EA_UDB."_user3 WHERE login=?",[$login]);
	if (!$res)
		return 0;
	$nbresult = EA_sql_num_rows($res);
	if ($nbresult!=1)
		{
		\ActaLibre\Compte\MotDePasse::verifierPourRien($pw);
		if ($nbresult>1)
			writelog('Login ERROR : ',$login,$nbresult);
		return 0;
		}
	$row = EA_sql_fetch_array($res);
	$stocke = (string)$row["hashpass"];
	$pwok = \ActaLibre\Compte\MotDePasse::verifier($pw,$stocke);
	// recontrôle du login pour assurer le respect de la casse
	if (!($row["login"]==$login and $pwok))
		return 0;
	// L'état du compte n'est dit qu'à celui qui a donné le bon mot de passe.
	$statut = $row["statut"];
	$expirok = compte_non_expire($row);
	if (!($expirok and compte_autorise($row)))
		return 0;
	if (\ActaLibre\Compte\MotDePasse::aRehacher($pw,$stocke))
		\ActaLibre\Db\Requete::executer($u_db,"UPDATE ".EA_UDB."_user3 SET hashpass=? WHERE ID=?",[\ActaLibre\Compte\MotDePasse::hacher($pw),(int)$row["ID"]]);
	$userid = $row["ID"];
	return max($row["level"],PUBLIC_LEVEL);
  }

//------------------------------------------------------------------------

function CheckSession($jeton)
  {
  global $userlogin, $u_db;
	$session = session_courante();
	$lue = $session->lire($jeton);
	if ($lue === null)
		return 0;
	$res = \ActaLibre\Db\Requete::executer($u_db,"SELECT * FROM ".EA_UDB."_user3 WHERE ID=?",[$lue['compte']]);
	$row = $res ? EA_sql_fetch_array($res) : null;
	if (!$row or !compte_autorise($row) or !compte_non_expire($row))
		{
		$session->fermer($jeton);
		return 0;
		}
	if ($lue['prolongee'] and $lue['rester'])
		poser_cookie_session($jeton,$session->expirationCookie(true));
	$userlogin = $row["login"];
	return max($row["level"],PUBLIC_LEVEL);
  }

//------------------------------------------------------------------------

function session_courante()
	{
	global $u_db;
	return new \ActaLibre\Compte\Session($u_db,EA_UDB."_session");
	}

function limiteur_courant()
	{
	global $u_db;
	return new \ActaLibre\Compte\Limiteur($u_db,EA_UDB."_essai");
	}

function plafond_courriel_courant()
	{
	global $u_db;
	return new \ActaLibre\Compte\PlafondCourriel($u_db,EA_UDB."_courriel");
	}

function adresse_visiteur()
	{
	return \ActaLibre\Compte\Limiteur::reseau((string)($_SERVER['REMOTE_ADDR'] ?? ''));
	}

function reinitialisation_courante()
	{
	global $u_db;
	return new \ActaLibre\Compte\Reinitialisation($u_db,EA_UDB."_reinit");
	}

// Vide si aucune adresse n'est enregistrée : un lien porteur d'un secret ne part pas sans elle.
function adresse_publique_enregistree()
	{
	return defined('URL_PUBLIQUE') ? rtrim((string)URL_PUBLIQUE,'/') : '';
	}

function adresse_publique()
	{
	$adresse = adresse_publique_enregistree();
	return $adresse !== '' ? $adresse : EA_URL_SITE;
	}

// Rend null si le lien n'a pas pu être enregistré.
function lien_reinitialisation($compte,$duree)
	{
	global $root;
	if (adresse_publique_enregistree() === '')
		return null;
	$jeton = reinitialisation_courante()->creer((int)$compte,$duree);
	return $jeton === null ? null : adresse_publique().$root."/reinitialiser.php?jeton=".$jeton;
	}

function adresse_du_compte($compte)
	{
	global $u_db;
	$res = \ActaLibre\Db\Requete::executer($u_db,"SELECT email FROM ".EA_UDB."_user3 WHERE ID=?",[(int)$compte]);
	$row = $res ? EA_sql_fetch_array($res) : null;
	return $row ? (string)$row['email'] : '';
	}

function enregistrer_mot_de_passe($compte,$passw)
	{
	global $u_db;
	$ecrit = \ActaLibre\Db\Requete::executer($u_db,"UPDATE ".EA_UDB."_user3 SET hashpass=? WHERE ID=?",[\ActaLibre\Compte\MotDePasse::hacher($passw),(int)$compte]);
	if (!$ecrit or $u_db->affected_rows !== 1)
		return false;
	session_courante()->fermerToutes((int)$compte);
	$res = \ActaLibre\Db\Requete::executer($u_db,"SELECT login FROM ".EA_UDB."_user3 WHERE ID=?",[(int)$compte]);
	$row = $res ? EA_sql_fetch_array($res) : null;
	if ($row)
		limiteur_courant()->oublierCouple((string)$row['login'],adresse_visiteur());
	return true;
	}

// Courriel d'un compte créé par un tiers : aucun mot de passe, un lien pour le choisir. Rend null si le lien manque.
function message_compte_cree($message,$login,$nom,$prenom)
	{
	global $u_db, $root;
	$res = \ActaLibre\Db\Requete::executer($u_db,"SELECT ID FROM ".EA_UDB."_user3 WHERE login=?",[$login]);
	$row = $res ? EA_sql_fetch_array($res) : null;
	if (!$row)
		return null;
	$lien = lien_reinitialisation($row['ID'],\ActaLibre\Compte\Reinitialisation::DUREE_CREATION);
	if ($lien === null)
		return null;
	$lb = "\r\n";
	// Les modèles personnalisés peuvent ne pas prévoir ce code.
	if (!str_contains($message,"#PASSW#"))
		$message .= $lb.$lb."Votre mot de passe : #PASSW#";
	$texte = "à choisir avec ce lien, valable 7 jours et une seule fois :".$lb.$lien.$lb;
	$codes = ["#NOMSITE#","#URLSITE#", "#LOGIN#", "#PASSW#", "#NOM#", "#PRENOM#"];
	$decodes = [SITENAME, adresse_publique().$root."/index.php", $login, $texte, $nom, $prenom];
	return str_replace($codes, $decodes, $message);
	}

function poser_cookie_session($jeton,$expiration)
	{
	global $root;
	setcookie(\ActaLibre\Compte\Session::COOKIE,$jeton,\ActaLibre\Compte\Cookie::options($expiration,\ActaLibre\Compte\Cookie::cheminSite((string)$root)));
	$_COOKIE[\ActaLibre\Compte\Session::COOKIE] = $jeton;
	}

function effacer_cookie_session()
	{
	global $root;
	setcookie(\ActaLibre\Compte\Session::COOKIE,'',\ActaLibre\Compte\Cookie::options(1,\ActaLibre\Compte\Cookie::cheminSite((string)$root)));
	unset($_COOKIE[\ActaLibre\Compte\Session::COOKIE]);
	}

// Les anciens cookies étaient posés sur le chemin du site ou sur celui de la page.
function effacer_anciens_cookies()
	{
	global $root;
	foreach (['userid','md5'] as $nom)
		{
		if (isset($_COOKIE[$nom]))
			{
			setcookie($nom,'',\ActaLibre\Compte\Cookie::options(1));
			setcookie($nom,'',\ActaLibre\Compte\Cookie::options(1,\ActaLibre\Compte\Cookie::cheminSite((string)$root)));
			unset($_COOKIE[$nom]);
			}
		}
	}

function preparer_sessions()
	{
	global $u_db;
	return \ActaLibre\Compte\Migration::preparerSessions($u_db,EA_UDB) and \ActaLibre\Compte\Migration::preparerParcours($u_db,EA_UDB);
	}

// Rend le nombre de comptes restant à migrer, -1 en cas d'erreur.
function migrer_comptes($secondes)
	{
	global $u_db;
	if (!\ActaLibre\Compte\Migration::preparerComptes($u_db,EA_UDB."_user3"))
		return -1;
	return \ActaLibre\Compte\Migration::migrerMotsDePasse($u_db,EA_UDB."_user3",$secondes);
	}

function fermer_sessions_orphelines()
	{
	session_courante()->fermerOrphelines(EA_UDB."_user3");
	reinitialisation_courante()->fermerOrphelines(EA_UDB."_user3");
	}

function fermer_session()
	{
	$jeton = $_COOKIE[\ActaLibre\Compte\Session::COOKIE] ?? null;
	if (is_string($jeton))
		session_courante()->fermer($jeton);
	effacer_cookie_session();
	effacer_anciens_cookies();
	}

// Après un changement de mot de passe : plus aucune session ancienne, et le compte reste connecté.
function remplacer_sessions($compte)
	{
	reinitialisation_courante()->supprimerCompte((int)$compte);
	$session = session_courante();
	$actuel = $_COOKIE[\ActaLibre\Compte\Session::COOKIE] ?? null;
	$lue = is_string($actuel) ? $session->lire($actuel) : null;
	$rester = ($lue !== null and $lue['rester']);
	$session->fermerToutes((int)$compte);
	$jeton = $session->ouvrir((int)$compte,$rester);
	if ($jeton !== null)
		poser_cookie_session($jeton,$session->expirationCookie($rester));
	}

?>
