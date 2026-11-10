<?php
error_reporting(E_ALL);

if (file_exists('tools/_COMMUN_env.inc.php')){
	$EA_Appel_dOu = '';
} else {
	$EA_Appel_dOu = '../';
}
include($EA_Appel_dOu.'tools/_COMMUN_env.inc.php');
my_ob_start_affichage_continu(); 

global $dbaddr, $dbuser, $dbpass, $dbname;

if (!defined("MYSQL_PATH"))
	{
	$mysql_path = "";
	}
 else
  {
  $mysql_path = MYSQL_PATH;
  if ($mysql_path!='') $mysql_path = rtrim($mysql_path,'/\\').DIRECTORY_SEPARATOR;
  }

$root=$path="";
$xcomm=$xpatr=$page="";
pathroot($root,$path,$xcomm,$xpatr,$page);

$userlogin="";
$userlevel=logonok(9);
while  ($userlevel<9)
	{
	login($root);
	}

open_page("Backup de votre base de données",$root);
navadmin($root,"Backup de la base");

echo "<h1>Backup de votre base de données</h1>";

if( ini_get('safe_mode') or (str_contains(ini_get('disable_functions'),"system")) )
	{
  echo "<p>Désolé : la configuration SAFE_MODE ou DISABLE_FUNCTIONS de l'hébergement ne permet pas d'exécuter un backup via PHP !";
  }
 else
	{
	if (!\ActaLibre\Securite\Jeton::verifier())
		{
		echo '<p><a href="?'.\ActaLibre\Securite\Jeton::lien().'"><b>Lancer la sauvegarde</b></a></p>';
		close_page(0);
		exit;
		}
	echo "<p>Commencement de la sauvegarde...\n<br>";
	my_flush(); // On affiche un minimum

	$file = $dbname."_".today().".sql";
	$command = "mysqldump";
	putenv("MYSQL_PWD=".$dbpass);
	$options = "--opt --host=".escapeshellarg($dbaddr)." --user=".escapeshellarg($dbuser)." ".escapeshellarg($dbname). " > ".escapeshellarg(dirname(__DIR__).DIRECTORY_SEPARATOR."tmp".DIRECTORY_SEPARATOR."backups".DIRECTORY_SEPARATOR.$file);
	$opt323 = "--compatible=mysql323 ";
	my_flush();

	$full_command = '"'.$mysql_path.$command.'" '.$opt323.$options;
	system($full_command,$ret_value);
	if ($ret_value!=0)
		{
		echo "<p>Nouvel essai sans option mysql323...\n<br>";
		$full_command = '"'.$mysql_path.$command.'" '.$options;
		system($full_command,$ret_value);
		}
	putenv("MYSQL_PWD");

	if ($ret_value==0)
		{
		echo "<p>Sauvegarde réussie.<br>Vous pouvez récupérer le fichier <b>tmp/backups".DIRECTORY_SEPARATOR.\ActaLibre\Securite\Filtre::stocke($file)."</b> par FTP";
		}
	 else
		{
		//echo "<p>Commande exécutée : <br>".$full_command;
		echo "<p>Désolé : impossible d'exécuter le backup ou erreur au cours de l'exécution !";
		echo "<p>Consulter l'<a ".'href="aide/backup.html"'.">aide</a> pour résoudre le problème.";
		}
	}

close_page(0);
my_flush();
?>
