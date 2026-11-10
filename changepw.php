<?php
if (file_exists('tools/_COMMUN_env.inc.php')) {
    $EA_Appel_dOu = '';
} else {
    $EA_Appel_dOu = '../';
}
include($EA_Appel_dOu . 'tools/_COMMUN_env.inc.php');
$u_db ??= null;

pathroot($root, $path, $xcomm, $xpatr, $page);

$act = getparam('act');

//{ print '<pre>';  print_r($_REQUEST); echo '</pre>'; }

$userlogin = "";

$userlevel = logonok(CHANGE_PW);
while ($userlevel < CHANGE_PW) {
    login($root);
}
exiger_jeton_si(getparam('action') == 'submitted');

$missingargs = true;
$messages = [];
$erreurMaj = false;
$pwModifie = false;
$userid = current_user("ID");

if (getparam('action') == 'submitted') {
    $passw = \ActaLibre\Compte\MotDePasse::lirePost('passw');
    if (strlen($passw) < \ActaLibre\Compte\MotDePasse::LONGUEUR_MINIMALE) {
        $messages[] = 'Vous devez donner un nouveau MOT DE PASSE d\'au moins 6 caractères';
    }
    if ($passw !== \ActaLibre\Compte\MotDePasse::lirePost('passwverif')) {
        $messages[] = 'Les deux copies du nouveau MOT DE PASSE ne sont pas identiques';
    }
    $limiteur = limiteur_courant();
    if ($limiteur->bloque($userlogin, adresse_visiteur()) or !\ActaLibre\Compte\MotDePasse::verifier(\ActaLibre\Compte\MotDePasse::lirePost('oldpassw'), (string) current_user("hashpass"))) {
        $limiteur->noterEchec($userlogin, adresse_visiteur());
        $messages[] = 'Votre ancien mot de passe n\'est pas correct';
    } else {
        $limiteur->oublierCouple($userlogin, adresse_visiteur());
    }
    if ($messages === []) {
        $missingargs = false;
        $resultat = \ActaLibre\Db\Requete::executer(
            $u_db,
            "UPDATE " . EA_UDB . "_user3 SET hashpass = ? WHERE ID = ?",
            [\ActaLibre\Compte\MotDePasse::hacher($passw), (int) $userid]
        );
        if ($resultat) {
            writelog('Modification mot de passe ', $userlogin, 0);
            remplacer_sessions((int) $userid);
            $pwModifie = true;
        } else {
            $erreurMaj = true;
        }
    }
}

open_page("Changement de mot de passe", $root);
navigation($root, 2, 'A', "Changement de mot de passe");

?>
<script type="text/javascript">
function pwProtect() {
    form = document.forms["eaform"];
    if (form.oldpassw.value == "") {
        alert("Erreur : L'ancien mot de passe est vide !");
        return false;
    }
    if (form.passw.value == "") {
        alert("Erreur : Le nouveau mot de passe est vide !");
        return false;
    }
    if (form.passw.value.length < 6) {
        alert("Erreur : Le nouveau mot de passe est trop court (min 6 caractères) !");
        return false;
    }
    if (!(form.passw.value == form.passwverif.value)) {
        alert("Erreur : Les nouveaux mots de passes ne sont pas identiques !");
        return false;
    }
    return true;
}
function seetext(x) {
    x.type = 'text';
}
function seeasterisk(x){
    x.type = 'password';
}
</script>
<?php


echo '<div id="col_menu">';
form_recherche();
//menu_admin($root, $userlevel);
statistiques();
menu_public();
show_pub_menu();
show_certifications();

echo '</div>';

echo '<div id="col_main_adm">';

if ($act == "relogin") {
    echo '<p align="center"><a href="index.php">Retour à la page d\'accueil</a></p>';
    echo '</div>';
    close_page(1);
    exit;
}

foreach ($messages as $message) {
    msg($message);
}
if ($pwModifie) {
    echo '<p><b>MOT DE PASSE MODIFIE.</b></p>';
}
if ($erreurMaj) {
    echo ' -> Erreur : ';
    erreur_sql();
}

//Si pas tout les arguments nécessaire, on affiche le formulaire
if ($missingargs) {
    $champJeton = \ActaLibre\Securite\Jeton::champ();
    $loginAffiche = \ActaLibre\Securite\Filtre::stocke($userlogin);
    echo<<<AAA
     <h2>Modification de votre mot de passe</h2>
     <form method="post" name="eaform" action="" onsubmit="return  pwProtect();">
     $champJeton
     <table cellspacing="0" cellpadding="1" border="0" summary="Formulaire">
     <tr>
       <td align="right">Code utilisateur : </td>
       <td>$loginAffiche</td>
     </tr>
     <tr>
       <td align="right">Ancien mot de passe : </td>
       <td><input id="EAoldpwd" type="password" name="oldpassw" size="15" value="" />
       <img onmouseover="seetext(EAoldpwd)" onmouseout="seeasterisk(EAoldpwd)"
        style="vertical-center" border="0" src="img/eye-16-16.png"
        alt="Voir mot de passe" width="16" height="16">
       </td>
     </tr>
     <tr>
       <td align="right">Nouveau mot de passe : </td>
       <td><input id="EApwd" type="password" name="passw" size="15" value="" />
       <img onmouseover="seetext(EApwd)" onmouseout="seeasterisk(EApwd)"
        style="vertical-center" border="0" src="img/eye-16-16.png"
        alt="Voir mot de passe" width="16" height="16">
       </td>
     </tr>
     <tr>
       <td align="right">Nouveau mot de passe (vérif.) : </td>
       <td><input id="EApwdverif" type="password" name="passwverif" size="15" value="" />
       <img onmouseover="seetext(EApwdverif)" onmouseout="seeasterisk(EApwdverif)"
        style="vertical-center" border="0" src="img/eye-16-16.png"
        alt="Voir mot de passe" width="16" height="16">
       </td>
     </tr>
     <tr>
       <td colspan="2">&nbsp;</td>
     </tr>
     <tr><td align="right">
       <input type="hidden" name="lelogin" value="$loginAffiche" />
       <input type="hidden" name="action" value="submitted" />
       <input type="reset" value=" Effacer " />
       </td>
       <td align="left">
         &nbsp; <input type="submit" value=" *** MODIFIER *** " />
       </td>
     </tr>
     </table>
     </form>

AAA;
} else {
    $mes = 'Vous DEVEZ vous reconnecter avec le nouveau mot de passe.';
    echo '<p align="center"><a href="login.php?cas=4">' . $mes . '</a></p>';
}
echo '</div>';
close_page(1);
