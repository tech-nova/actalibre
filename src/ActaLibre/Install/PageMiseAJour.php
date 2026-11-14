<?php
declare(strict_types=1);
namespace ActaLibre\Install;
use ActaLibre\Compte\Cookie;
use ActaLibre\Compte\Limiteur;
use ActaLibre\Livraison;
use ActaLibre\Securite\Filtre;

/** Entrée autonome : aucune session SQL, aucun menu ni bootstrap du site. */
final class PageMiseAJour
{
    public static function afficher(string $racine): void
    {
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');
        $c=null;$etat=null;$jeton='';$message='';$suite=false;$avis=[];$administrateur=false;$origine='';$diagnostic='';$preuve='';
        try {
            $livraison=Livraison::lire($racine.'/composer.json');Installateur::prerequis($livraison);
            $methode=$_SERVER['REQUEST_METHOD']??'GET';
            if (!in_array($methode,['GET','POST'],true)) {
                header('Allow: GET, POST');http_response_code(405);
                self::vue('Méthode refusée.',null,'',false,[]);return;
            }
            $hote=(string)($_SERVER['SERVER_NAME']??$_SERVER['HTTP_HOST']??'');
            $configuration=ConfigurationSite::charger($racine,$hote);
            if ($configuration===null) { header('Location: install.php',true,303);return; }
            $c=$configuration->ouvrir();
            $etat=EtatExploitation::lire($c,$racine.'/composer.json');$message=$etat->diagnostic;
            // Base vierge ou installation inachevée : l'écran administrateur d'install.php prend le relais.
            if (Installateur::aInstaller($etat->site)) { header('Location: install.php',true,303);return; }
            if (!in_array($etat->action,['expoactes','reprendre','produit','nettoyer'],true)) {
                self::vue($message,$etat,'',false,[]);return;
            }
            $table='`'.$configuration->actes->prefixe.'_params`';
            $r=\ActaLibre\Db\Requete::executer($c->actes,'SELECT valeur FROM '.$table.' WHERE param=?',['URL_PUBLIQUE']);
            if ($r instanceof \mysqli_result) { $ligne=$r->fetch_row(); $origine=$ligne===null ? '' : (string)$ligne[0]; }
            $chemin=str_replace('\\','/',dirname((string)($_SERVER['SCRIPT_NAME']??'/update.php')));
            $chemin=$chemin==='.'||$chemin==='/'?'/':rtrim($chemin,'/').'/';
            SessionMiseAJour::ouvrir($chemin,Cookie::enHttps());$jeton=SessionMiseAJour::jeton();
            // Identifiants ExpoActes perdus : un nouvel administrateur peut être créé sur preuve FTP.
            $creation=in_array($etat->action,['expoactes','reprendre'],true);
            if ($creation) $preuve=PreuveFtp::nom($_SESSION);
            if ($methode==='POST') {
                if (!SessionMiseAJour::verifierJeton(self::lirePost('jeton'))) {
                    http_response_code(403);self::vue('Formulaire expiré ou jeton invalide.',$etat,$jeton,false,[]);return;
                }
                $action=self::lirePost('action');$perimetre=$configuration->perimetre();
                // L'adresse est validée avant tout effet : un compte créé ne doit pas dépendre d'un refus ultérieur.
                $soumise=self::lirePost('url_site');
                $origine=ControleProtection::origine($soumise!=='' ? $soumise : $origine);
                if (in_array($action,['demarrer','produit','nettoyer'],true)) {
                    $a=$configuration->comptes??$configuration->actes;
                    // La décision lue en base, jamais l'action du POST, choisit le limiteur.
                    $limiteur=in_array($etat->action,['nettoyer','produit'],true)?new Limiteur($c->comptes,$a->prefixe.'_essai'):null;
                    $adresse=Limiteur::reseau((string)($_SERVER['REMOTE_ADDR']??''));
                    $autorisation=SessionMiseAJour::authentifier($c->comptes,$a->prefixe,self::lirePost('login'),self::lirePost('mot_de_passe'),$perimetre,$limiteur,$adresse);
                    SessionMiseAJour::lierProcedure($etat);
                } elseif ($action==='creer') {
                    if (!$creation) throw new \RuntimeException('Création d’administrateur réservée à la migration depuis ExpoActes.');
                    if (!PreuveFtp::presente($racine,$_SESSION)) throw new \RuntimeException('Fichier de preuve introuvable : déposez-le par FTP dans _config/ puis validez à nouveau.');
                    $login=self::lirePost('login');$motDePasse=self::lirePost('mot_de_passe');
                    if ($motDePasse!==self::lirePost('confirmation')) throw new \RuntimeException('Les mots de passe doivent être identiques.');
                    $a=$configuration->comptes??$configuration->actes;
                    Administrateur::ajouter($c->comptes,$a->prefixe,$login,self::lirePost('courriel'),$motDePasse);
                    PreuveFtp::consommer($racine,$_SESSION);
                    $autorisation=SessionMiseAJour::authentifier($c->comptes,$a->prefixe,$login,$motDePasse,$perimetre);
                    SessionMiseAJour::lierProcedure($etat);
                    $action='demarrer';
                } elseif ($action==='continuer') {
                    $autorisation=SessionMiseAJour::autorisation($perimetre);
                    if ($autorisation===null) throw new \RuntimeException('Authentification expirée. Reconnectez-vous pour reprendre.');
                } else throw new \RuntimeException('Action de mise à jour invalide.');
                SessionMiseAJour::fermer();
                $resultat=(new MiseAJour($racine))->executer($c,$autorisation,$action,$origine);
                $administrateur=true;
                $etat=EtatExploitation::lire($c,$racine.'/composer.json');
                $message=$resultat['diagnostic'];$avis=$resultat['avis'];$suite=!$resultat['terminee'];
                if (!$suite) $diagnostic=DiagnosticInstallation::html($racine,$configuration->actes->base,DiagnosticInstallation::depot($c->actes,$configuration->actes->prefixe));
            }
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            http_response_code($jeton===''?503:400);$message=$e->getMessage();
        } catch (\Throwable $e) {
            error_log('ActaLibre mise à jour : '.$e::class.' ('.basename($e->getFile()).':'.$e->getLine().')');
            http_response_code(500);$message='Erreur interne pendant la mise à jour.';
        } finally {
            SessionMiseAJour::fermer();$c?->fermer();
        }
        self::vue($message,$etat,$jeton,$suite,$avis,$administrateur,$origine,$diagnostic,$preuve);
    }
    private static function lirePost(string $nom): string
    {
        $valeur=$_POST[$nom]??'';
        if (!is_string($valeur) || str_contains($valeur,"\x00")) throw new \RuntimeException('Champ de formulaire invalide.');
        return $valeur;
    }
    /** @param list<string> $avis */
    private static function vue(string $message,?EtatExploitation $etat,string $jeton,bool $suite,array $avis,bool $administrateur=false,string $origine='',string $diagnostic='',string $preuve=''): void
    {
        echo '<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Mettre à jour ActaLibre</title>';
        echo '<style>body{font:1rem/1.5 system-ui,sans-serif;background:#f4f5f7;color:#172433;margin:0;padding:2rem}main{max-width:46rem;margin:auto;background:white;padding:2rem;border-radius:.6rem}label{display:block;margin-top:1rem}input{display:block;max-width:100%;font:inherit;padding:.4rem}code{overflow-wrap:anywhere;background:#eef1f5;padding:0 .2rem}details{margin-top:1.5rem}button{font:inherit;margin-top:1rem;padding:.6rem 1rem}a{color:#145a8d}.attente{display:none;align-items:center;gap:.8rem;margin-top:1rem}.attente.active{display:flex}.attente span{flex:none;width:1.6rem;height:1.6rem;border:.25rem solid #d5dbe3;border-top-color:#145a8d;border-radius:50%;animation:tourne .9s linear infinite}@keyframes tourne{to{transform:rotate(360deg)}}@media (prefers-reduced-motion:reduce){.attente span{animation-duration:3s}}</style><main><h1>Mettre à jour ActaLibre</h1>';
        echo '<p role="status">'.Filtre::html($message).'</p>';
        if ($administrateur && $etat!==null && $etat->site->livraison!==null) {
            echo '<p>Livraison : '.Filtre::html($etat->site->livraison->version).' ; schéma '.Filtre::html($etat->site->livraison->schema).'.</p>';
        }
        foreach ($avis as $a) echo '<p>'.Filtre::html($a).'</p>';
        $admis=$etat!==null && in_array($etat->action,['expoactes','reprendre','produit','nettoyer'],true) && $jeton!=='';
        if ($admis) {
            $action=$suite?'continuer':match($etat->action) { 'produit'=>'produit','nettoyer'=>'nettoyer',default=>'demarrer' };
            echo '<form id="poursuite" method="post" action=""><input type="hidden" name="action" value="'.$action.'"><input type="hidden" name="jeton" value="'.Filtre::html($jeton).'">';
            if ($origine==='') $origine=(Cookie::enHttps()?'https://':'http://').(string)($_SERVER['HTTP_HOST']??'localhost').rtrim(str_replace('\\','/',dirname((string)($_SERVER['SCRIPT_NAME']??'/update.php'))),'/').'/';
            // Pendant la poursuite automatique, l'adresse reste transmise sans libellé visible.
            echo $suite ? '<input name="url_site" type="hidden" value="'.Filtre::html($origine).'">' : '<label>Adresse complète du site <input name="url_site" type="url" value="'.Filtre::html($origine).'" required></label>';
            if (!$suite) echo '<p>Identifiez-vous avec un compte administrateur pour confirmer cette action.</p><label for="login">Identifiant</label><input id="login" name="login" autocomplete="username" required><label for="mot_de_passe">Mot de passe</label><input id="mot_de_passe" name="mot_de_passe" type="password" autocomplete="current-password" required>';
            // Sans JavaScript, la poursuite reste possible par le bouton.
            echo $suite ? '<noscript><button type="submit">Continuer</button></noscript></form>' : '<button type="submit">'.($action==='nettoyer'?'Nettoyer les restes terminés':'Mettre à jour ActaLibre').'</button></form>';
            if (!$suite && $preuve!=='') {
                echo '<details><summary>Identifiants perdus : créer un nouvel administrateur</summary><form class="poursuite" method="post" action=""><input type="hidden" name="action" value="creer"><input type="hidden" name="jeton" value="'.Filtre::html($jeton).'">';
                echo '<p>Pour prouver que vous contrôlez ce serveur, déposez par FTP un fichier vide nommé <code>'.Filtre::html($preuve).'</code> dans le dossier <code>_config/</code>, puis validez ce formulaire. Ce nom est valable une heure, dans ce navigateur ; le fichier est supprimé après la création du compte, et la migration démarre aussitôt.</p>';
                echo '<label>Adresse complète du site <input name="url_site" type="url" value="'.Filtre::html($origine).'" required></label>';
                foreach (['login'=>['Identifiant','text','username'],'courriel'=>['Courriel','email','email'],'mot_de_passe'=>['Mot de passe','password','new-password'],'confirmation'=>['Confirmation du mot de passe','password','new-password']] as $nom=>[$libelle,$type,$auto]) {
                    echo '<label for="creer_'.$nom.'">'.$libelle.'</label><input id="creer_'.$nom.'" name="'.$nom.'" type="'.$type.'" autocomplete="'.$auto.'" required>';
                }
                echo '<button type="submit">Créer l’administrateur et mettre à jour</button></form></details>';
            }
            // La roue reste animée pendant que le navigateur attend la réponse suivante : pas d'iframe nécessaire.
            echo '<div id="attente" class="attente'.($suite?' active':'').'" role="status" aria-live="polite"><span aria-hidden="true"></span><strong>'.($action==='nettoyer'?'Nettoyage en cours':'Mise à jour en cours').', ne fermez pas cette page…</strong></div>';
            echo '<script>(function(){var f=document.getElementById("poursuite"),a=document.getElementById("attente");'
                .'[f].concat(Array.prototype.slice.call(document.querySelectorAll("form.poursuite"))).forEach(function(g){g.addEventListener("submit",function(){a.classList.add("active");var b=g.querySelector("button");if(b){b.disabled=true;}});});'
                .($suite?'f.requestSubmit();':'').'})();</script>';
        }
        echo $diagnostic;
        echo '<p><a href="index.php">Ouvrir le site</a></p></main></html>';
    }
}
