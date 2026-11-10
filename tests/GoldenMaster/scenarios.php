<?php
declare(strict_types=1);

// Catalogue ordonné. Les scénarios d'écriture sont à la fin, car ils modifient la base.

use ActaLibre\Tests\Donnees\GenerateurDonnees;
use ActaLibre\Tests\GoldenMaster\Client;

require_once __DIR__ . '/../Donnees/GenerateurDonnees.php';

$scenario = static fn (string $nom, string $profil, string $url, array $parametres = [], string $methode = 'GET'): array =>
    ['nom' => $nom, 'profil' => $profil, 'methode' => $methode, 'url' => $url, 'parametres' => $parametres];

// Contrôle attendu par les fiches d'actes : voir ctrlxid() dans tools/actutils.php.
$controle = static function (string $type, int $id): int {
    $lignes = file(__DIR__ . "/../Donnees/actes_$type.001.bea");
    $acte = array_combine(GenerateurDonnees::colonnes($type), array_slice(str_getcsv($lignes[$id - 1], ';', '"', ''), 0, -1));
    return ($acte['NOM'] === '' ? 13 : ord($acte['NOM'][0]) + 3) * ($acte['PRE'] === '' ? 19 : ord($acte['PRE'][0]) + 7);
};

$commune = "L'Orméane [Val d'Ombre]";
$pagesParType = ['N' => 'naiss', 'M' => 'mari', 'D' => 'deces', 'V' => 'bans'];

$pagesPubliques = [
    'acces', 'acte_bans', 'acte_deces', 'acte_mari', 'acte_naiss', 'activer_compte', 'changepw',
    'chercherlevenshtein', 'chercher', 'cree_compte', 'eclair', 'form_contact', 'index', 'localite', 'login',
    'rechavancee', 'rechlevenshtein', 'reinitialiser', 'renvoilogin', 'rss', 'signal_erreur', 'stat_annees',
    'tab_bans', 'tab_deces', 'tab_mari', 'tab_naiss',
];
$pagesAdmin = [
    'acte_bans', 'acte_deces', 'acte_mari', 'acte_naiss', 'ajout_1acte', 'approuver_compte', 'backup', 'chargecsv',
    'charge', 'corr_grp_acte', 'edit_acte', 'envoimail', 'exporte', 'expparams', 'expsupuser', 'gestgeoloc',
    'gestindex', 'gest_labels', 'gest_params', 'gestpoints', 'gesttraceip', 'gestuser', 'index', 'listgeolocs',
    'listlog', 'listusers', 'loaduser', 'maj_sums', 'permute', 'stat_annees', 'suppr_acte',
    'supprime', 'tab_bans', 'tab_deces', 'tab_mari', 'tab_naiss', 'test_mail', 'update_params',
];

$liste = [];
foreach ($pagesPubliques as $page) {
    $liste[] = $scenario("public/$page", 'anonyme', "/$page.php");
}
$pagesDeposant = [
    'acte_bans', 'acte_deces', 'acte_mari', 'acte_naiss', 'charge', 'chargecsv',
    'edit_acte', 'exporte', 'index', 'maj_sums', 'permute', 'stat_annees',
    'suppr_acte', 'supprime', 'tab_bans', 'tab_deces', 'tab_mari', 'tab_naiss',
];
foreach ($pagesAdmin as $page) {
    if (in_array($page, $pagesDeposant, true)) {
        $liste[] = $scenario("admin/$page-niveau6", 'niveau6', "/admin/$page.php");
    }
    $liste[] = $scenario("admin/$page-niveau9", 'niveau9', "/admin/$page.php", $page === 'expparams' ? ['jeton' => Client::JETON] : []);
}

// Accueil et listes
foreach (['vue-communes' => ['vue' => 'C', 'xtyp' => 'A'], 'vue-tableau-N' => ['vue' => 'T', 'xtyp' => 'N'],
    'initiale-L' => ['xtyp' => 'A', 'init' => 'L']] as $nom => $parametres) {
    $liste[] = $scenario("public/index-$nom", 'anonyme', '/index.php', $parametres);
}
$liste[] = $scenario('public/index-connecte', 'niveau6', '/index.php');
// La virgule doit rester littérale dans args : pathroot() découpe l'URL brute.
$argsCommune = 'args=' . urlencode($commune);
foreach (['N' => ['naiss', 'BELCOUR'], 'M' => ['mari', 'ABRANEL'], 'D' => ['deces', 'CORVELLE']] as $type => [$page, $patronyme]) {
    $liste[] = $scenario("public/tab_$page-commune", 'anonyme', "/tab_$page.php", ['args' => $commune]);
    $liste[] = $scenario("public/tab_$page-patronyme", 'anonyme', "/tab_$page.php?$argsCommune,$patronyme");
    $liste[] = $scenario("public/tab_$page-tri-nom", 'anonyme', "/tab_$page.php?$argsCommune,$patronyme&xord=N");
    $liste[] = $scenario("admin/tab_$page-commune", 'niveau9', "/admin/tab_$page.php", ['args' => $commune]);
    $liste[] = $scenario("public/stat_annees-$type", 'anonyme', '/stat_annees.php', ['comdep' => $commune, 'xtyp' => $type]);
}
$liste[] = $scenario('public/tab_bans-commune', 'anonyme', '/tab_bans.php', ['args' => $commune . ';Contrat de mariage']);
$liste[] = $scenario('public/tab_bans-patronyme', 'anonyme', '/tab_bans.php?args=' . urlencode($commune . ';Contrat de mariage') . ',ALBERGUE');
$liste[] = $scenario('public/localite-id', 'anonyme', '/localite.php', ['id' => 3]);
$liste[] = $scenario('public/rss-tout', 'anonyme', '/rss.php', ['type' => 'A']);
$liste[] = $scenario('admin/index-type-N', 'niveau9', '/admin/index.php', ['args' => 'N']);
$liste[] = $scenario('admin/listusers-tri', 'niveau9', '/admin/listusers.php', ['xord' => 'L']);
$liste[] = $scenario('admin/gestuser-compte', 'niveau9', '/admin/gestuser.php', ['id' => 16]);
$liste[] = $scenario('admin/gestgeoloc-commune', 'niveau9', '/admin/gestgeoloc.php', ['id' => 1]);

// Fiches : les huit premiers actes de chaque type portent les cas limites du jeu de données.
foreach ($pagesParType as $type => $page) {
    for ($id = 1; $id <= 8; $id++) {
        $liste[] = $scenario("public/acte_$page-$id", 'anonyme', "/acte_$page.php", ['xid' => $id, 'xct' => $controle($type, $id)]);
    }
    $liste[] = $scenario("admin/acte_$page-1", 'niveau9', "/admin/acte_$page.php", ['xid' => 1, 'xct' => $controle($type, 1)]);
    $liste[] = $scenario("admin/edit_acte-$type", 'niveau9', '/admin/edit_acte.php', ['xid' => 1, 'xtyp' => $type]);
    $liste[] = $scenario("admin/ajout_1acte-$type", 'niveau9', '/admin/ajout_1acte.php', ['xtyp' => $type]);
    $liste[] = $scenario("admin/suppr_acte-$type", 'niveau9', '/admin/suppr_acte.php', ['xid' => 2, 'xtyp' => $type]);
}

// Recherches
$toutes = '*** Toutes ***';
$recherche = ['zone' => 1, 'TypNDMV' => 'N', 'ComDep' => $toutes];
$liste[] = $scenario('public/chercher-resultats', 'niveau6', '/chercher.php', ['achercher' => 'BELCOUR', 'comp' => 'E'] + $recherche);
$liste[] = $scenario('public/chercher-debut', 'niveau6', '/chercher.php', ['achercher' => 'BEL', 'comp' => 'D'] + $recherche);
$liste[] = $scenario('public/chercher-aucun', 'niveau6', '/chercher.php', ['achercher' => 'INEXISTANT', 'comp' => 'E'] + $recherche);
// Valeurs légitimes avec des caractères spéciaux
$speciale = "D'Arc & <Fils>";
$liste[] = $scenario('public/chercher-caracteres-speciaux', 'niveau6', '/chercher.php', ['achercher' => $speciale, 'comp' => 'E', 'amin' => 1650, 'amax' => 1920] + $recherche);
$liste[] = $scenario('public/chercherlevenshtein-caracteres-speciaux', 'niveau6', '/chercherlevenshtein.php', ['achercher' => $speciale, 'prenom' => "D'Arc", 'comp' => 'D', 'TypNDMV' => 'N', 'ComDep' => $toutes]);
$liste[] = $scenario('public/eclair-caracteres-speciaux', 'anonyme', '/eclair.php', ['xcom' => $speciale, 'xdep' => $speciale, 'xtyp' => 'N', 'xini' => "D'"]);
$liste[] = $scenario('public/form_contact-caracteres-speciaux', 'anonyme', '/form_contact.php', ['nompre' => $speciale, 'email' => $speciale, 'objet' => $speciale, 'txtmsg' => $speciale]);
$liste[] = $scenario('public/form_contact-site-web', 'anonyme', '/form_contact.php', ['sweb' => $speciale]);
$liste[] = $scenario('public/signal_erreur-caracteres-speciaux', 'anonyme', '/signal_erreur.php', ['nompre' => $speciale, 'email' => $speciale, 'msgerreur' => $speciale, 'xty' => 'N', 'xid' => 1, 'xct' => $speciale, 'xdf' => $speciale]);
$liste[] = $scenario('public/login-retour', 'anonyme', '/login.php', ['uri' => '/tab_naiss.php?args=a&xord=N']);
$liste[] = $scenario('public/tab_naiss-caracteres-speciaux', 'niveau6', '/tab_naiss.php?args=' . urlencode($speciale) . ',' . urlencode($speciale));

// Listes d'actes en administration : colonne des déposants
foreach (['tab_naiss', 'tab_mari', 'tab_deces', 'tab_bans'] as $page) {
    $liste[] = $scenario("admin/{$page}-actes-niveau6", 'niveau6', "/admin/{$page}.php?args=" . urlencode('Saint-Ormel [Hautes-Brumes]') . ',' . urlencode("D'ARCY"));
}

$tousTypes = ['zone' => 1, 'TypN' => 'N', 'TypM' => 'M', 'TypD' => 'D', 'TypV' => 'V'];
$liste[] = $scenario('public/chercher-avancee', 'niveau6', '/chercher.php', ['achercher' => 'BELCOUR', 'prenom' => 'Jean', 'comp' => 'E', 'amin' => 1650, 'amax' => 1920, 'ComDep' => $commune] + $tousTypes);
$liste[] = $scenario('public/chercher-pagination-1', 'niveau6', '/chercher.php', ['achercher' => 'SAC', 'comp' => 'C', 'ComDep' => $toutes] + $tousTypes);
$liste[] = $scenario('public/chercher-pagination-2', 'niveau6', '/chercher.php', ['achercher' => 'SAC', 'comp' => 'C', 'ComDep' => $toutes, 'pg' => 2] + $tousTypes);
$liste[] = $scenario('public/chercherlevenshtein-resultats', 'niveau6', '/chercherlevenshtein.php', ['achercher' => 'BELCOUR', 'comp' => 'D', 'TypNDMV' => 'N', 'ComDep' => $toutes]);
$liste[] = $scenario('public/eclair-commune', 'anonyme', '/eclair.php', ['xcom' => "L'Orméane", 'xdep' => "Val d'Ombre", 'xtyp' => 'N']);

// Formulaires de chargement, jusqu'à l'étape qui précède le chargement
$liste[] = $scenario('admin/charge-sauvegarde', 'niveau9', '/admin/charge.php', ['Origine' => 'B']);
$liste[] = $scenario('admin/charge-nimegue', 'niveau9', '/admin/charge.php', ['Origine' => 'N']);
$liste[] = $scenario('admin/chargecsv-type', 'niveau9', '/admin/chargecsv.php', ['TypeActes' => 'M']);

$liste[] = $scenario('admin/backup-lancee', 'niveau9', '/admin/backup.php', ['jeton' => Client::JETON]);

// Écritures

$acte = ['xconfirm' => 'confirmed', 'COMMUNE' => "L'Orméane", 'DEPART' => "Val d'Ombre", 'DATETXT' => '02/03/1801', 'NOM' => 'ECRITURE', 'PRE' => 'Test', 'DEPOSANT' => 16, 'IDNIM' => 0];
$liste[] = $scenario('ecriture/ajout-naissance', 'niveau9', '/admin/edit_acte.php', ['xid' => -1, 'xtyp' => 'N'] + $acte, 'POST');
$liste[] = $scenario('ecriture/modif-naissance', 'niveau9', '/admin/edit_acte.php', ['xid' => 9, 'xtyp' => 'N'] + $acte, 'POST');
$liste[] = $scenario('ecriture/suppr-naissance', 'niveau9', '/admin/suppr_acte.php', ['xid' => 10, 'xtyp' => 'N', 'xconfirm' => 'confirmed'], 'POST');
$liste[] = $scenario('ecriture/permute-mariage', 'niveau9', '/admin/permute.php', ['xid' => 9, 'xtyp' => 'M', 'xconfirm' => 'confirmed'], 'POST');
$compte = ['action' => 'submitted', 'nom' => 'ECRITURE', 'prenom' => 'Compte', 'email' => 'ecriture@example.org', 'lelevel' => 2, 'regime' => 0, 'solde' => 10, 'statut' => 'N', 'dtexpir' => '2033-12-31', 'rem' => '', 'libre' => ''];
$liste[] = $scenario('ecriture/compte-creation', 'niveau9', '/admin/gestuser.php', ['id' => -1, 'act' => 'add', 'lelogin' => 'ecriture', 'lepassw' => 'Test-ecrit-2026', 'passwverif' => 'Test-ecrit-2026'] + $compte, 'POST');
$liste[] = $scenario('ecriture/compte-modif', 'niveau9', '/admin/gestuser.php', ['id' => 11, 'lelogin' => 'fictif_public', 'nom' => 'FICTIF', 'prenom' => 'Public', 'email' => 'public@example.org', 'lelevel' => 2, 'regime' => 0, 'solde' => 50, 'soldepre' => 50, 'maj_solde' => '2024-01-15', 'statut' => 'N', 'dtexpir' => '2033-12-31', 'action' => 'submitted', 'rem' => 'Modifié', 'libre' => ''], 'POST');

$liste[] = $scenario('ecriture/ajout-autre-deposant', 'niveau9', '/admin/edit_acte.php', ['xid' => -1, 'xtyp' => 'N', 'DEPOSANT' => 1] + $acte, 'POST');

return $liste;
