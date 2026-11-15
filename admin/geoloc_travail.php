<?php
// Opérations JSON de géolocalisation des localités (niveau 9) : travaux groupés et recherche depuis une fiche.
if (file_exists('tools/_COMMUN_env.inc.php')){
	$EA_Appel_dOu = '';
} else {
	$EA_Appel_dOu = '../';
}
include($EA_Appel_dOu.'tools/_COMMUN_env.inc.php');

/** @param array<string,mixed> $donnees */
function geoloc_repondre(int $code, array $donnees): never
	{
	http_response_code($code);
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store');
	echo json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
	exit;
	}

function geoloc_champ(string $nom): string
	{
	$valeur = $_POST[$nom] ?? $_GET[$nom] ?? '';
	return is_string($valeur) ? $valeur : '';
	}

function geoloc_coordonnee(string $nom): float
	{
	$valeur = geoloc_champ($nom);
	if (!is_numeric($valeur))
		throw new \InvalidArgumentException('Coordonnée invalide.');
	return (float)$valeur;
	}

/** @return array{statut:string,lon:?string,lat:?string} */
function geoloc_version(): array
	{
	$texte = static fn (string $nom): ?string => geoloc_champ($nom) === '' ? null : geoloc_champ($nom);
	return ['statut' => geoloc_champ('version_statut'), 'lon' => $texte('version_lon'), 'lat' => $texte('version_lat')];
	}

// Sans session valide, logonok() redirigerait vers la page de connexion : le navigateur attend un JSON identifiable.
$cookie = $_COOKIE[\ActaLibre\Compte\Session::COOKIE] ?? null;
if (!is_string($cookie) or $cookie === '' or CheckSession($cookie) === 0)
	geoloc_repondre(401, ['erreur' => 'session', 'message' => 'Votre session a expiré. Reconnectez-vous, puis reprenez la mise à jour.']);

$root = ""; $path = ""; $xcomm = ""; $xpatr = ""; $page = 1;
pathroot($root,$path,$xcomm,$xpatr,$page);
$userlogin = "";
$userlevel = logonok(9);
if ($userlevel < 9)
	geoloc_repondre(403, ['erreur' => 'droits', 'message' => 'Cette opération est réservée aux administrateurs.']);
$proprietaire = (int)current_user('ID');

$action = geoloc_champ('action');
if (in_array($action, ['creer','avancer','suspendre','reprendre','rechercher','remplacer'], true))
	{
	if ($_SERVER['REQUEST_METHOD'] !== 'POST')
		geoloc_repondre(405, ['erreur' => 'methode', 'message' => 'Cette opération exige un envoi de formulaire.']);
	if (!\ActaLibre\Securite\Jeton::verifier())
		geoloc_repondre(403, ['erreur' => 'jeton', 'message' => "Cette page n'est plus valable. Rechargez-la, puis recommencez."]);
	}

try
	{
	$travaux = geoloc_travaux();
	switch ($action)
		{
		case 'creer':
			$ids = $_POST['ids'] ?? null;
			if (!is_array($ids))
				throw new \InvalidArgumentException('Aucune localité sélectionnée.');
			$id = $travaux->creer($proprietaire, $ids);
			geoloc_repondre(200, \ActaLibre\Geoloc\Travaux::vue($travaux->lire($id, $proprietaire)));
		case 'etat':
			geoloc_repondre(200, \ActaLibre\Geoloc\Travaux::vue($travaux->lire(geoloc_champ('travail'), $proprietaire)));
		case 'actif':
			$actif = $travaux->dernierActif($proprietaire);
			geoloc_repondre(200, ['travail' => $actif === null ? null : \ActaLibre\Geoloc\Travaux::vue($actif)]);
		case 'avancer':
			geoloc_repondre(200, $travaux->avancer(geoloc_champ('travail'), $proprietaire, geoloc_moteur()));
		case 'suspendre':
			geoloc_repondre(200, $travaux->suspendre(geoloc_champ('travail'), $proprietaire));
		case 'reprendre':
			geoloc_repondre(200, $travaux->reprendre(geoloc_champ('travail'), $proprietaire));
		case 'rechercher':
			geoloc_repondre(200, geoloc_moteur()->rechercherPourFiche((int)geoloc_champ('id')));
		case 'remplacer':
			geoloc_repondre(200, geoloc_moteur()->remplacerManuelle((int)geoloc_champ('id'), geoloc_version(), geoloc_coordonnee('lon'), geoloc_coordonnee('lat')));
		default:
			geoloc_repondre(400, ['erreur' => 'action', 'message' => 'Opération inconnue.']);
		}
	}
catch (\ActaLibre\Geoloc\Occupe)
	{
	geoloc_repondre(409, ['erreur' => 'occupe', 'message' => 'Une autre mise à jour avance en ce moment. Nouvel essai dans quelques secondes.']);
	}
catch (\ActaLibre\Geoloc\TravailIntrouvable)
	{
	geoloc_repondre(404, ['erreur' => 'travail', 'message' => "Cette mise à jour n'existe plus ou a expiré. Lancez une nouvelle sélection."]);
	}
catch (\InvalidArgumentException $erreur)
	{
	geoloc_repondre(400, ['erreur' => 'requete', 'message' => $erreur->getMessage()]);
	}
catch (\ActaLibre\Carte\RefusDebit $refus)
	{
	geoloc_repondre(503, ['erreur' => 'debit', 'attente' => $refus->attente, 'message' => 'Le service Nominatim demande de patienter. Réessayez plus tard.']);
	}
catch (\RuntimeException $panne)
	{
	geoloc_repondre(503, ['erreur' => 'panne', 'message' => "La recherche n'a pas abouti : ".$panne->getMessage()]);
	}
