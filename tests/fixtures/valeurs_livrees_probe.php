<?php
declare(strict_types=1);

// Processus isolé : aucun autoloader Composer ni accès MySQL.
$mode = $argv[1];
$racine = $argv[2];

if ($mode === 'parametres' || $mode === 'sans-marqueurs') {
    require $racine . '/src/autoload.php';
    define('ENTITY_REPLACE_FLAGS', ENT_COMPAT | ENT_HTML5);
    define('ENTITY_CHARSET', 'UTF-8');
    $GLOBALS['parametres_sonde'] = [['param' => 'SITENAME', 'valeur' => 'Site &amp; test']];
    if ($mode === 'parametres') {
        foreach (['ACTALIBRE_VERSION' => '9.8.7', 'ACTALIBRE_SCHEMA' => '9.0.0', 'ACTALIBRE_INSTALLATION' => 'en cours', 'ACTALIBRE_MIGRATION' => '3'] as $nom => $valeur) {
            $GLOBALS['parametres_sonde'][] = ['param' => $nom, 'valeur' => $valeur];
        }
        $GLOBALS['parametres_sonde'][] = ['param' => 'EA_VERSION', 'valeur' => '3.2.4'];
    }
    function con_db(): bool
    {
        return true;
    }
    function EA_sql_query(string $sql): object
    {
        if ($sql === "SHOW TABLES LIKE 'act_params';") {
            return (object) ['lignes' => [['act_params']]];
        }
        if ($sql === 'select * from act_params') {
            return (object) ['lignes' => $GLOBALS['parametres_sonde']];
        }
        throw new RuntimeException('Requête inattendue dans la sonde.');
    }
    function EA_sql_num_rows(object $resultat): int
    {
        return count($resultat->lignes);
    }
    function EA_sql_fetch_array(object $resultat): array|false
    {
        return array_shift($resultat->lignes) ?? false;
    }
    function mkSiteUrl(): string
    {
        return 'https://site.example';
    }
    require $racine . '/tools/actutils.php';
    echo json_encode([
        'version' => ACTALIBRE_VERSION,
        'schema' => defined('ACTALIBRE_SCHEMA') ? ACTALIBRE_SCHEMA : null,
        'installation' => defined('ACTALIBRE_INSTALLATION'),
        'migration' => defined('ACTALIBRE_MIGRATION'),
        'titre' => SITENAME,
        'version_heritee' => defined('EA_VERSION'),
        'format_herite' => defined('EA_VERSION_PRG'),
    ], JSON_THROW_ON_ERROR);
} elseif ($mode === 'page') {
    $_SERVER['SERVER_NAME'] = 'localhost';
    $page = $argv[3] === 'install.php' ? '/install.php' : '/update.php';
    $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'] = $page;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    require $racine . $page;
} elseif ($mode === 'environnement') {
    $_SERVER['SERVER_NAME'] = 'localhost';
    $EA_Appel_dOu = '';
    require $racine . '/tools/_COMMUN_env.inc.php';
} elseif ($mode === 'tampon') {
    $_SERVER['SCRIPT_FILENAME'] = '/mutusf/sites/test/index.php';
    $niveau = ob_get_level();
    require $racine . '/tools/PreProcess.inc.php';
    $ouvert = ob_get_level() === $niveau + 1;
    if ($ouvert) {
        ob_end_clean();
    }
    echo $ouvert ? 'tampon' : 'absent';
} elseif ($mode === 'rss') {
    define('ACTALIBRE_VERSION', '1.2.3');
    define('ACTALIBRE_SCHEMA', '9.0.0');
    require $racine . '/tools/MakeRss/MakeRss.class.php';
    $rss = new GenRss();
    $rss->entete = '';
    $rss->Load();
    echo $rss->generator;
} else {
    throw new RuntimeException('Mode inconnu.');
}
