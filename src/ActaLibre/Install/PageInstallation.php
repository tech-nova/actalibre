<?php

declare (strict_types=1);
namespace ActaLibre\Install;

use ActaLibre\Livraison;
use ActaLibre\Securite\Filtre;
use ActaLibre\Compte\Cookie;
/** Contrôleur et vue indépendants du bootstrap du site. */
final class PageInstallation
{
    public static function afficher(string $racine): void
    {
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        $jeton = '';
        $message = '';
        $contenu = null;
        $diagnostic = '';
        $formulaire = true;
        try {
            if (is_link($racine . '/_config/connect.inc.php') || InstallGuard::etat($racine . '/_config') === InstallGuard::INSTALLE) {
                http_response_code(403);
                self::vue('Fichier de connexion déjà présent : utiliser la mise à jour.', '', [], false, null, true);
                return;
            }
            $livraison = Livraison::lire($racine . '/composer.json');
            Installateur::prerequis($livraison);
            $methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            if (!in_array($methode, ['GET', 'POST'], true)) {
                header('Allow: GET, POST');
                http_response_code(405);
                self::vue('Méthode refusée.', '', [], false);
                return;
            }
            $hote = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
            $origine = (Cookie::enHttps() ? 'https://' : 'http://') . $hote;
            if (!InstallGuard::nomHoteValide($hote) || filter_var($origine, FILTER_VALIDATE_URL) === false) {
                throw new \RuntimeException('Nom d’hôte invalide.');
            }
            $chemin = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/install.php')));
            if ($chemin === '.' || $chemin === '/') {
                $chemin = '/';
            } else {
                $chemin .= '/';
            }
            session_name('actalibre_install_jeton');
            if (!@session_start(['use_strict_mode' => 1, 'use_only_cookies' => 1, 'cookie_httponly' => true, 'cookie_samesite' => 'Lax', 'cookie_secure' => Cookie::enHttps(), 'cookie_path' => $chemin])) {
                throw new \RuntimeException('Impossible de préparer le formulaire sécurisé.');
            }
            if (!isset($_SESSION['jeton']) || !is_string($_SESSION['jeton'])) {
                $_SESSION['jeton'] = bin2hex(random_bytes(32));
            }
            $jeton = $_SESSION['jeton'];
            session_write_close();
            if ($methode === 'POST') {
                if (!is_string($_POST['jeton'] ?? null) || !hash_equals($jeton, $_POST['jeton'])) {
                    http_response_code(403);
                    self::vue('Formulaire expiré ou jeton invalide.', $jeton, self::publics());
                    return;
                }
                $champs = ['url_site', 'serveur', 'base', 'utilisateur', 'mot_de_passe_mysql', 'prefixe', 'login', 'courriel', 'mot_de_passe', 'confirmation', 'comptes_distincts', 'comptes_serveur', 'comptes_base', 'comptes_utilisateur', 'comptes_mot_de_passe_mysql', 'comptes_prefixe'];
                $valeurs = [];
                foreach ($champs as $champ) {
                    $v = $_POST[$champ] ?? '';
                    if (!is_string($v) || str_contains($v, "\x00")) {
                        throw new \RuntimeException('Champ de formulaire invalide.');
                    }
                    $valeurs[$champ] = $v;
                }
                $origine = ControleProtection::origine($valeurs['url_site']);
                $actes = new AccesMysql($valeurs['serveur'], $valeurs['base'], $valeurs['utilisateur'], $valeurs['mot_de_passe_mysql'], $valeurs['prefixe']);
                $comptes = $valeurs['comptes_distincts'] === '1' ? new AccesMysql($valeurs['comptes_serveur'], $valeurs['comptes_base'], $valeurs['comptes_utilisateur'], $valeurs['comptes_mot_de_passe_mysql'], $valeurs['comptes_prefixe']) : null;
                $resultat = (new Installateur($racine))->executer($actes, $valeurs['login'], $valeurs['courriel'], $valeurs['mot_de_passe'], $valeurs['confirmation'], $origine, $comptes);
                if ($resultat['publication'] === FichierConnexion::MANUEL) {
                    $message = 'La base est prête. Déposez ce contenu exact dans _config/connect.inc.php.';
                    $contenu = $resultat['contenu'];
                    $formulaire = false;
                } elseif ($resultat['action'] === 'rattache') {
                    header('Location: update.php', true, 303);
                    return;
                } else {
                    $message = 'Installation terminée. Le compte administrateur a été créé.';
                    $formulaire = false;
                }
                $diagnostic = DiagnosticInstallation::html($racine, $valeurs['base'], '_upload');
            }
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            http_response_code($jeton === '' ? 503 : 400);
            $message = $e->getMessage();
            if ($jeton === '') {
                $formulaire = false;
            }
        } catch (\Throwable $e) {
            // Jamais de trace à l'écran : elle pourrait contenir les accès saisis.
            error_log('ActaLibre installation : ' . $e::class . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
            http_response_code(500);
            $message = 'Erreur interne pendant l’installation.';
            $formulaire = $jeton !== '';
        }
        self::vue($message, $jeton, self::publics(), $formulaire, $contenu, false, $diagnostic);
    }
    /** @return array<string,string> */
    private static function publics(): array
    {
        $donnees = [];
        foreach (['url_site', 'serveur', 'base', 'utilisateur', 'prefixe', 'login', 'courriel', 'comptes_serveur', 'comptes_base', 'comptes_utilisateur', 'comptes_prefixe', 'comptes_distincts'] as $cle) {
            if (isset($_POST[$cle]) && is_string($_POST[$cle])) {
                $donnees[$cle] = $_POST[$cle];
            }
        }
        return $donnees;
    }
    /** @param array<string,string> $donnees */
    private static function vue(string $message, string $jeton, array $donnees, bool $formulaire = true, ?string $contenu = null, bool $miseAJour = false, string $diagnostic = ''): void
    {
        echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Installer ActaLibre</title>';
        echo '<link rel="icon" href="img/favicon.ico" sizes="16x16 32x32 48x48">';
        foreach ([16, 32, 48] as $taille) {
            echo '<link rel="icon" type="image/png" href="img/favicon-' . $taille . 'x' . $taille . '.png" sizes="' . $taille . 'x' . $taille . '">';
        }
        echo '<link rel="apple-touch-icon" href="img/apple-touch-icon.png" sizes="180x180">';
        echo '<style>body{font:1rem/1.5 system-ui;background:#f5f4ef;color:#18392f;margin:0;padding:2rem 1rem}main{max-width:42rem;margin:auto;background:white;padding:2rem;border-radius:.5rem}fieldset{margin:1rem 0;border:1px solid #bccbc3}label{display:block;margin:.7rem 0}input:not([type=checkbox]),textarea{box-sizing:border-box;display:block;width:100%;padding:.5rem;font:inherit}button{padding:.7rem 1.2rem;background:#18392f;color:white;border:0;border-radius:.2rem}a{color:#18392f}.message{padding:1rem;background:#eef4ef}</style></head><body><main><h1>Installer ActaLibre</h1>';
        if ($message !== '') {
            echo '<p class="message" role="status">' . Filtre::html($message) . '</p>';
        }
        if ($contenu !== null) {
            echo '<label for="connexion">Contenu du fichier de connexion</label><textarea id="connexion" readonly rows="16">' . Filtre::html($contenu) . '</textarea><p>Après le dépôt, ouvrez le site ou sa page de mise à jour.</p><a href="update.php">Mise à jour</a>';
        }
        if ($formulaire) {
            echo '<form method="post" action=""><input type="hidden" name="jeton" value="' . Filtre::html($jeton) . '"><fieldset><legend>Base MySQL</legend>';
            self::champ('url_site', 'Adresse complète du site (avec son sous-dossier)', $donnees);
            foreach (['serveur' => 'Serveur', 'base' => 'Base', 'utilisateur' => 'Utilisateur', 'mot_de_passe_mysql' => 'Mot de passe MySQL', 'prefixe' => 'Préfixe des tables'] as $nom => $label) {
                self::champ($nom, $label, $donnees);
            }
            echo '</fieldset><fieldset><legend>Administrateur</legend><p>Pour reprendre ou rattacher une base, indiquez les identifiants de son administrateur. Le courriel et la confirmation servent à créer un nouveau compte.</p>';
            foreach (['login' => 'Identifiant', 'courriel' => 'Courriel', 'mot_de_passe' => 'Mot de passe', 'confirmation' => 'Confirmation du mot de passe'] as $nom => $label) {
                self::champ($nom, $label, $donnees);
            }
            echo '</fieldset><details><summary>Base des comptes distincte (rattachement seulement)</summary><label><input type="checkbox" name="comptes_distincts" value="1"' . (($donnees['comptes_distincts'] ?? '') === '1' ? ' checked' : '') . '> Utiliser une base de comptes distincte</label>';
            foreach (['comptes_serveur' => 'Serveur des comptes', 'comptes_base' => 'Base des comptes', 'comptes_utilisateur' => 'Utilisateur MySQL des comptes', 'comptes_mot_de_passe_mysql' => 'Mot de passe MySQL des comptes', 'comptes_prefixe' => 'Préfixe des comptes'] as $nom => $label) {
                self::champ($nom, $label, $donnees);
            }
            echo '</details><p><button type="submit">Installer ActaLibre</button></p></form>';
        } elseif ($contenu === null) {
            echo '<p><a href="' . ($miseAJour ? 'update.php' : 'index.php') . '">' . ($miseAJour ? 'Mettre à jour ActaLibre' : 'Ouvrir le site') . '</a></p>';
        }
        echo $diagnostic;
        echo '</main></body></html>';
    }
    /** @param array<string,string> $donnees */
    private static function champ(string $nom, string $label, array $donnees): void
    {
        $secret = str_contains($nom, 'mot_de_passe') || $nom === 'confirmation';
        $type = $secret ? 'password' : ($nom === 'courriel' ? 'email' : 'text');
        $defaut = match ($nom) {
            'url_site' => (Cookie::enHttps() ? 'https://' : 'http://') . (string)($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/install.php'))), '/') . '/',
            'serveur' => 'localhost',
            'prefixe' => 'act',
            default => '',
        };
        echo '<label>' . Filtre::html($label) . '<input name="' . $nom . '" type="' . $type . '"' . ($secret ? '' : ' value="' . Filtre::html($donnees[$nom] ?? $defaut) . '"') . '></label>';
    }
}
