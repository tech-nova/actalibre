<?php
$EA_Appel_dOu = '../';
require '../tools/_COMMUN_env.inc.php';
global $dbname;
$root = $path = $xcomm = $xpatr = $page = '';
pathroot($root, $path, $xcomm, $xpatr, $page);
$userlogin = '';
$userlevel = logonok(9);
if ($userlevel < 9) { login($root); exit; }
$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($methode, ['GET','POST'], true)) { header('Allow: GET, POST'); http_response_code(405); exit; }
if ($methode === 'POST') { exiger_jeton_si(true); }
$message = '';
if ($methode === 'POST') {
    $verrou = null;
    try {
        if (($_POST['action'] ?? null) !== 'controler' || !is_string($_POST['url_site'] ?? null)) { throw new InvalidArgumentException('Formulaire invalide.'); }
        $origine = \ActaLibre\Install\ControleProtection::origine($_POST['url_site']);
        $verrou = \ActaLibre\Install\Verrou::prendre(EA_sql_which_link(), $dbname, EA_DB, 'migration');
        $rapport = \ActaLibre\Install\DiagnosticInstallation::controler(dirname(__DIR__), $origine, false, UPLOAD_DIR);
        $message = $rapport->etat === 'verifie' ? 'Protection vérifiée.' : 'Contrôle terminé : la protection reste non vérifiée.';
    } catch (Throwable) { $message = 'Contrôle non réalisé. Vérifiez l’adresse et recommencez.'; }
    finally { $verrou?->liberer(); }
}
open_page('Protection et fichiers du site', $root);
navadmin($root, 'Protection et fichiers du site');
echo '<div id="col_main_adm"><h1>Protection et fichiers du site</h1>';
if ($message !== '') { echo '<p role="status">'.\ActaLibre\Securite\Filtre::html($message).'</p>'; }
echo \ActaLibre\Install\DiagnosticInstallation::html(dirname(__DIR__), $dbname, UPLOAD_DIR);
echo '<form method="post" action="">'.\ActaLibre\Securite\Jeton::champ().'<input type="hidden" name="action" value="controler">';
echo '<label>Adresse complète du site <input name="url_site" type="url" required value="'.\ActaLibre\Securite\Filtre::html(defined('URL_PUBLIQUE') ? URL_PUBLIQUE : '').'"></label><button type="submit">Relancer le contrôle</button></form></div>';
close_page(1, $root);
