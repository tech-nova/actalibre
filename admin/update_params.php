<?php
// NB : programme distinct de update_params situé dans install !!
//error_reporting(E_ALL);

if (file_exists('tools/_COMMUN_env.inc.php')){
	$EA_Appel_dOu = '';
} else {
	$EA_Appel_dOu = '../';
}
include($EA_Appel_dOu.'tools/_COMMUN_env.inc.php');
my_ob_start_affichage_continu(); 

include("../install/instutils.php");

$root="";
pathroot($root,$path,$xcomm,$xpatr,$page);

$userlogin="";
$userlevel=logonok(9);
while ($userlevel<9)
	{
	login($root);
	}
exiger_jeton_si(getparam('action') == 'submitted');

open_page("Mise à jour des paramètres",$root);
navadmin($root,"Mise à jour des paramètres");

echo '<div id="col_menu">';
form_recherche();
menu_admin($root,$userlevel);
echo '</div>';

echo '<div id="col_main_adm">';
menu_software('P');
echo '<h2>Backup / Restauration</h2>';

echo '<p align="center"><strong>Actions sur les paramètres : </strong>';
echo ' <a href="expparams.php?'.\ActaLibre\Securite\Jeton::lien().'"><b>Sauvegarder</b></a>';
echo ' | Restaurer';
echo ' || <a href="gest_params.php">Retour</a>';
echo '</p>';

$missingargs=true;

//$message    = getparam('Message');
$xaction    = getparam('action');

if ($xaction == 'submitted')
	{
	if(!empty($_FILES['params']['tmp_name']))
		{ // fichier de paramètres
		if(strtolower(mb_substr($_FILES['params']['name'], -4))==".xml") //Vérifie que l'extension est bien '.XML'
			{ // type XML
			$filename=$_FILES['params']['tmp_name'];
			$missingargs=false;
            $restauration_ok = false;
            try {
                // Prévalidation des quatre familles avant la première écriture.
                $doc = \ActaLibre\Parametres\Sauvegarde::lire($filename);
                $restauration = new \ActaLibre\Parametres\Restauration(EA_sql_which_link(), EA_DB);
                $restauration->preparer($doc, true);
                $bilan = $restauration->appliquer();
                $par_add = $bilan['ajouts'];
                $par_mod = $bilan['modifications'];
                $restauration_ok = true;
                writelog('Restauration des paramètres', "PARAMS", $par_mod + $par_add);
            } catch (\Throwable $erreur) {
                $par_add = $par_mod = 0;
                error_log('ActaLibre : restauration des paramètres : '.get_class($erreur).' : '.str_replace(["\r", "\n"], ' ', $erreur->getMessage()));
                msg('Restauration refusée ou interrompue. Vérifiez le document et la base avant de recommencer.');
            }

			if ($par_add>0)
				echo "<p>".$par_add." paramètres ajoutés.</p>";
			if ($par_mod>0)
				echo "<p>".$par_mod." paramètres modifiés.</p>";
			if ($restauration_ok && $par_add+$par_mod==0)
				echo "<p>Aucune modification nécessaire.</p>";
			}
			else
			{
			msg("Type de fichier incorrect !");
			}
		}
  }  

//Si pas tout les arguments nécessaire, on affiche le formulaire
if($missingargs)
	{
	echo '<form method="post" enctype="multipart/form-data" action="">'."\n";
	echo \ActaLibre\Securite\Jeton::champ();
	echo '<h2 align="center">Restauration de paramètres sauvegardés</h2>';
	echo '<table cellspacing="2" cellpadding="0" border="0" align="center" summary="Formulaire">'."\n";
	echo '<tr><td align="right">Dernier backup : &nbsp;</td><td>';
	echo show_last_backup("P");
	echo "</td></tr>";
	echo " <tr><td colspan=\"2\">&nbsp;</td></tr>\n";
	echo " <tr>\n";
	echo '  <td align="right">Fichier XML de paramètres : &nbsp;</td>'."\n";
	echo '  <td><input type="file" size="62" name="params" />'."</td>\n";
	echo " </tr>\n";
	echo " <tr><td colspan=\"2\">&nbsp;</td></tr>\n";
	echo " <tr><td colspan=\"2\" align=\"center\">\n<br />";
	echo '  <input type="hidden" name="action" value="submitted" />';
	echo '  <input type="reset" value="Annuler" />'."\n";
	echo '  <input type="submit" value=" >> CHARGER >> " />'."\n";
	echo " </td></tr>\n";
	echo "</table>\n";
	echo "</form>\n";
	}
echo '</div>';

close_page(1,$root);
//ob_flush();
//close_page(0);
?>
