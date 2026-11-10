<?php

declare (strict_types=1);
namespace ActaLibre\Install;

use ActaLibre\Livraison;
use ActaLibre\Db\Requete;
/** Une requête autorisée : aucune initialisation applicative implicite. */
final class Installateur
{
    /** @param null|\Closure(string):void $apresEtape */
    public function __construct(private readonly string $racine, private readonly ?\Closure $apresEtape = null)
    {
    }
    /** @return array{action:'installe'|'rattache',publication:string,contenu:?string} */
    public function executer(AccesMysql $actes, string $login, string $courriel, #[\SensitiveParameter] string $motDePasse, #[\SensitiveParameter] string $confirmation, string $urlPublique, ?AccesMysql $comptes = null): array
    {
        $this->garde();
        $livraison = Livraison::lire($this->racine . '/composer.json');
        self::prerequis($livraison);
        $prefixeComptes = $comptes === null ? $actes->prefixe : $comptes->prefixe;
        $lien = $actes->ouvrir();
        $utilisateurs = null;
        $verrou = null;
        try {
            $utilisateurs = $comptes?->ouvrir();
            $etat = EtatSite::lire($lien, $actes->prefixe, $this->racine . '/composer.json', $utilisateurs, $comptes?->prefixe);
            self::admettre($etat, $comptes);
            $verrou = Verrou::prendre($lien, $actes->base, $actes->prefixe, 'installation');
            $this->garde();
            $etat = EtatSite::lire($lien, $actes->prefixe, $this->racine . '/composer.json', $utilisateurs, $comptes?->prefixe);
            self::admettre($etat, $comptes);
            if ($etat->livraison === null) {
                throw new \RuntimeException('Livraison invalide.');
            }
            $livraison = $etat->livraison;
            self::prerequis($livraison);
            $neuve = in_array($etat->etat, [EtatSite::BASE_VIDE, EtatSite::INSTALLATION_INACHEVEE], true);
            if ($neuve) {
                self::verifierCasse($lien, $actes->prefixe);
            }
            $compteExiste = Administrateur::existe($utilisateurs ?? $lien, $prefixeComptes);
            if (!$neuve || $compteExiste) {
                if (!Administrateur::verifier($utilisateurs ?? $lien, $prefixeComptes, $login, $motDePasse)) {
                    throw new \RuntimeException('Identifiants administrateur refusés.');
                }
            } else {
                Administrateur::valider($login, $courriel, $motDePasse);
                if ($motDePasse !== $confirmation) {
                    throw new \RuntimeException('Les mots de passe doivent être identiques.');
                }
            }
            if ($neuve) {
                $donnees = new DonneesInitiales($this->racine);
                $donnees->creerParametres($lien, $actes->prefixe);
                $this->etape('params');
                if (!array_key_exists('ACTALIBRE_INSTALLATION', $etat->marqueurs)) {
                    $this->marquer($lien, $actes->prefixe, 'ACTALIBRE_INSTALLATION', gmdate('c'));
                }
                $this->etape('installation');
                $donnees->completer($lien, $actes->prefixe, $courriel, $urlPublique, $this->apresEtape);
                $this->etape('donnees');
                if (!$compteExiste) {
                    Administrateur::creer($lien, $actes->prefixe, $login, $courriel, $motDePasse);
                }
                if (!Administrateur::verifier($lien, $actes->prefixe, $login, $motDePasse)) {
                    throw new \RuntimeException('Compte administrateur non vérifié.');
                }
                $this->etape('compte');
                $this->marquer($lien, $actes->prefixe, 'ACTALIBRE_SCHEMA', $livraison->schema);
                $this->etape('schema');
                $this->marquer($lien, $actes->prefixe, 'ACTALIBRE_VERSION', $livraison->version);
                $this->etape('produit');
                self::requete($lien, "DELETE FROM `{$actes->prefixe}_params` WHERE param='ACTALIBRE_INSTALLATION'");
                $this->etape('nettoyage');
                $final = EtatSite::lire($lien, $actes->prefixe, $this->racine . '/composer.json');
                if ($final->etat !== EtatSite::A_JOUR) {
                    throw new \RuntimeException('Installation non finalisée.');
                }
            }
            $this->etape('publication');
            $this->garde();
            $contenu = FichierConnexion::contenu($actes, $comptes);
            $publication = FichierConnexion::publier($this->racine, $contenu);
            if ($publication === FichierConnexion::EXISTANT) {
                throw new \RuntimeException('Fichier de connexion déjà présent : utiliser update.php.');
            }
            if ($neuve) {
                DiagnosticInstallation::controler($this->racine, $urlPublique, false, DiagnosticInstallation::depot($lien, $actes->prefixe));
            }
            return ['action' => $neuve ? 'installe' : 'rattache', 'publication' => $publication, 'contenu' => $publication === FichierConnexion::MANUEL ? $contenu : null];
        } finally {
            $verrou?->liberer();
            $utilisateurs?->close();
            $lien->close();
        }
    }
    public static function prerequis(Livraison $livraison): void
    {
        if (version_compare(PHP_VERSION, $livraison->phpMinimum, '<')) {
            throw new \RuntimeException('PHP ' . $livraison->phpMinimum . ' ou plus récent est requis.');
        }
        foreach ($livraison->extensions as $extension) {
            if (!extension_loaded($extension)) {
                throw new \RuntimeException('Extension PHP requise : ' . $extension . '.');
            }
        }
    }
    private function garde(): void
    {
        clearstatcache();
        if (is_link($this->racine . '/_config/connect.inc.php') || InstallGuard::etat($this->racine . '/_config') === InstallGuard::INSTALLE) {
            throw new \RuntimeException('Fichier de connexion déjà présent : utiliser update.php.');
        }
    }
    private static function admettre(EtatSite $etat, ?AccesMysql $comptes): void
    {
        if (!in_array($etat->etat, [EtatSite::BASE_VIDE, EtatSite::INSTALLATION_INACHEVEE, EtatSite::A_JOUR, EtatSite::MISE_A_JOUR_SCHEMA, EtatSite::MISE_A_JOUR_PRODUIT, EtatSite::EXPOACTES_ADMIS], true)) {
            throw new \RuntimeException($etat->diagnostic);
        }
        if ($comptes !== null && in_array($etat->etat, [EtatSite::BASE_VIDE, EtatSite::INSTALLATION_INACHEVEE], true)) {
            throw new \RuntimeException('Les comptes distincts sont réservés au rattachement d’un site existant.');
        }
    }
    /** Ne pas recréer des homonymes sur un serveur sensible à la casse. */
    private static function verifierCasse(\mysqli $lien, string $prefixe): void
    {
        $mode = self::requete($lien, 'SELECT @@lower_case_table_names');
        if (!$mode instanceof \mysqli_result) {
            throw new \RuntimeException('Impossible de vérifier les noms de tables.');
        }
        if ((int) ($mode->fetch_row()[0] ?? -1) !== 0) {
            return;
        }
        $tables = self::requete($lien, 'SHOW TABLES');
        if (!$tables instanceof \mysqli_result) {
            throw new \RuntimeException('Impossible de vérifier les noms de tables.');
        }
        foreach ($tables->fetch_all() as [$table]) {
            if (strncasecmp($table, $prefixe . '_', strlen($prefixe) + 1) !== 0) {
                continue;
            }
            $suffixe = substr($table, strlen($prefixe) + 1);
            if ($table !== $prefixe . '_' . strtolower($suffixe)) {
                throw new \RuntimeException('La casse du préfixe ou des tables ne correspond pas à la base existante.');
            }
        }
    }
    private function etape(string $nom): void
    {
        $this->apresEtape?->__invoke($nom);
    }
    private function marquer(\mysqli $lien, string $prefixe, string $nom, string $valeur): void
    {
        $table = "`{$prefixe}_params`";
        $lignes = self::requete($lien, "SELECT param,valeur FROM {$table} WHERE param=?", [$nom]);
        if (!$lignes instanceof \mysqli_result) {
            throw new \RuntimeException('Marqueur illisible.');
        }
        if ($lignes->num_rows === 0) {
            self::requete($lien, "INSERT INTO {$table} (param,groupe,ordre,type,valeur,listval,libelle,aide) VALUES (?,'Hidden',100,'C',?,'','','')", [$nom, $valeur]);
        } else {
            self::requete($lien, "UPDATE {$table} SET valeur=? WHERE param=?", [$valeur, $nom]);
        }
        $r = self::requete($lien, "SELECT valeur FROM {$table} WHERE param=?", [$nom]);
        if (!$r instanceof \mysqli_result || $r->num_rows !== 1 || ($r->fetch_row()[0] ?? null) !== $valeur) {
            throw new \RuntimeException('Marqueur non vérifié.');
        }
    }
    /** @param list<string> $valeurs */
    private static function requete(\mysqli $lien, string $sql, array $valeurs = []): \mysqli_result|bool
    {
        try {
            $r = Requete::executer($lien, $sql, $valeurs);
        } catch (\mysqli_sql_exception) {
            throw new \RuntimeException('Échec de l’installation en base.');
        }
        if ($r === false) {
            throw new \RuntimeException('Échec de l’installation en base.');
        }
        return $r;
    }
}
