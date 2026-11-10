<?php
// Choix d'un nouveau mot de passe depuis un lien reçu par courriel.
// Ce programme est libre, vous pouvez le redistribuer et/ou le modifier selon les termes de la
// Licence Publique Générale GNU, version 2 (GPLv2), publiée par la Free Software Foundation
// Texte de la licence : https://www.gnu.org/licenses/old-licenses/gpl-2.0.fr.html
//-------------------------------------------------------------------
$EA_Appel_dOu = '';
include(__DIR__.'/tools/_COMMUN_env.inc.php');

$root = "";
$path = "";
$xcomm=$xpatr=$page="";
pathroot($root,$path,$xcomm,$xpatr,$page);

$jeton = $_POST['jeton'] ?? $_GET['jeton'] ?? '';
if (!is_string($jeton) or preg_match('/^[0-9a-f]{64}$/',$jeton) !== 1)
	$jeton = '';
$reinit = reinitialisation_courante();
$compte = $jeton === '' ? null : $reinit->compte($jeton);

header('Cache-Control: no-store');
open_page("Choisir un mot de passe",$root);
navigation($root,2,"","Choisir un mot de passe");

echo '<div id="col_menu">'."\n";
menu_public();
show_pub_menu();
echo '</div>'."\n";
echo '<div id="col_main">'."\n";

$fini = false;
if ($compte === null)
	{
	msg("Ce lien n'est plus valable.");
	echo '<p><a href="'.$root.'/renvoilogin.php">Demander un nouveau lien</a></p>'."\n";
	$fini = true;
	}
elseif (($_POST['action'] ?? '') === 'submitted')
	{
	$passw = \ActaLibre\Compte\MotDePasse::lirePost('passw');
	if (strlen($passw) < \ActaLibre\Compte\MotDePasse::LONGUEUR_MINIMALE)
		msg("Vous devez donner un MOT DE PASSE d'au moins 6 caractères");
	elseif ($passw !== \ActaLibre\Compte\MotDePasse::lirePost('passwverif'))
		msg('Les deux copies du MOT DE PASSE ne sont pas identiques');
	elseif ($reinit->consommer($jeton) === null)
		{
		msg("Ce lien n'est plus valable.");
		$fini = true;
		}
	else
		{
		if (enregistrer_mot_de_passe($compte,$passw))
			{
			writelog('MDP choisi par lien',(string)$compte,0);
			echo '<p><b>Votre mot de passe a été enregistré.</b></p>'."\n";
			echo '<p><a href="'.$root.'/login.php"><b>Vous connecter</b></a></p>'."\n";
			}
		else
			{
			msg("Votre mot de passe n'a pas pu être enregistré. Merci de demander un nouveau lien.");
			echo '<p><a href="'.$root.'/renvoilogin.php">Demander un nouveau lien</a></p>'."\n";
			}
		$fini = true;
		}
	}

if (!$fini)
	{
	echo '<h2>Choisir un mot de passe</h2>'."\n";
	echo '<form method="post" action="reinitialiser.php">'."\n";
	echo '<input type="hidden" name="jeton" value="'.$jeton.'" />'."\n";
	echo '<input type="hidden" name="action" value="submitted" />'."\n";
	echo '<table align="center" summary="Formulaire">'."\n";
	echo '<tr><td align="right">Nouveau mot de passe : </td><td><input type="password" name="passw" size="15" value="" autocomplete="new-password" /></td></tr>'."\n";
	echo '<tr><td align="right">Nouveau mot de passe (vérification) : </td><td><input type="password" name="passwverif" size="15" value="" autocomplete="new-password" /></td></tr>'."\n";
	echo '<tr><td colspan="2" align="center"><input type="submit" value=" Enregistrer " /></td></tr>'."\n";
	echo '</table>'."\n";
	echo '</form>'."\n";
	}
echo '</div>'."\n";
close_page(1,$root);
