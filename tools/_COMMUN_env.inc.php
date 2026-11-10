<?php
$EA_Appel_dOu ??= null;
        // Centralisation pour éviter de répéter le code. Gestion des inclusions indispensables du programme ExpoActes
        // Copyright (C) : André Delacharlerie, Emmanuel Lethrosne 2005-2019
        // Ce programme est libre, vous pouvez le redistribuer et/ou le modifier selon les termes de la
        // Licence Publique Générale GNU, version 2 (GPLv2), publiée par la Free Software Foundation
        // Texte de la licence : https://www.gnu.org/licenses/old-licenses/gpl-2.0.fr.html
        //-------------------------------------------------------------------
        // Classes et livraison avant les personnalisations locales et la connexion.
        require_once __DIR__ . '/../src/autoload.php';
        $actalibreConfiguration = \ActaLibre\Install\ControleSite::verifier(
                dirname(__DIR__), (string) ($_SERVER['SERVER_NAME'] ?? 'localhost'), (string) ($EA_Appel_dOu ?? '')
        );
        if (!defined('EA_UDB')) define('EA_UDB', ($actalibreConfiguration->comptes ?? $actalibreConfiguration->actes)->prefixe);
        require_once __DIR__ . '/../src/livraison.php';
        // PreProcess pour toute page ExpoActes pour régler un problème spécifique.
    if (file_exists(__DIR__ . '/PreProcess.inc.php')) {
        include (__DIR__ . '/PreProcess.inc.php');
        }
        /* APPEL À FAIRE :
                //     $EA_level_min = 1; // À positionner avant l'appel si besoin : niveau minimum d'accès (par défaut ce sera 1 : public)
                //   Eventuellement define(ADM,nn); // voir dans "admin/index.php"
                if (file_exists("tools/_COMMUN_env.inc.php")) {
                $EA_Appel_dOu = '';
                } else {
                $EA_Appel_dOu = '../';
                }
                if (file_exists('LOCAL-'.basename(__FILE__))) {
                include('LOCAL-'.basename(__FILE__));
                return;
                } else {
                include($EA_Appel_dOu.'tools/_COMMUN_env.inc.php'); // ON est ADMIN
                }
        */

        if ($EA_Appel_dOu == '../') { // par rapport à la position du script appelant : ON est dans ADMIN ou TOOLS ou Autre
                if (!defined('ADM')) {
                        define('ADM', 10);
                }
                $admtxt = 'Gestion ';
                } else { // ON EST A LA RACINE
                if (!defined('ADM')) {
                        define('ADM', 0); // Mode public;
                }
                $admtxt = '';
        }

        // Rétablir les accès contrôlés après PreProcess, comme l'ancien include de configuration.
        unset($udbaddr, $udbname, $udbuser, $udbpass);
        $actalibreVariables = $actalibreConfiguration->variables;
        extract($actalibreVariables, EXTR_OVERWRITE);
        unset($actalibreVariables);
        include_once 'function.php';
        include_once 'adlcutils.php';
        include_once 'actutils.php';

        // p_info.php exige désormais une connexion administrateur (logonok)

        if (! in_array(basename($_SERVER['PHP_SELF']),['eclair.php', 'rss.php'])) {
                include_once 'loginutils.php';
        }
        // Cette partie gère l'existence d'un script modifié localement, son nom doit alors être LOCAL-[Nom complet d'origine]
        $EA_Ce_Script = str_replace('LOCAL-','',basename($_SERVER['PHP_SELF']));



