<?php
declare(strict_types=1);

namespace ActaLibre\Install;

use ActaLibre\Livraison;

/** État observé seulement : les futurs appelants effectuent les reprises et migrations. */
final class EtatSite
{
    public const PARAMETRE_HISTORIQUE = 'EA_VERSION';
    public const LIVRAISON_INVALIDE = 'livraison_invalide';
    public const ILLISIBLE = 'illisible';
    public const BASE_VIDE = 'base_vide';
    public const INSTALLATION_INACHEVEE = 'installation_inachevee';
    public const INCOHERENTE = 'incoherente';
    public const EXPOACTES_ADMIS = 'expoactes_admis';
    public const EXPOACTES_REFUSE = 'expoactes_refuse';
    public const RETOUR_VERSION_REFUSE = 'retour_version_refuse';
    public const MISE_A_JOUR_SCHEMA = 'mise_a_jour_schema';
    public const MISE_A_JOUR_PRODUIT = 'mise_a_jour_produit';
    public const A_JOUR = 'a_jour';

    /** @param array<string,?string> $marqueurs */
    private function __construct(
        public readonly string $etat,
        public readonly string $diagnostic,
        public readonly ?Livraison $livraison,
        public readonly ?string $versionInstallee,
        public readonly ?string $schemaInstalle,
        public readonly ?string $versionExpoActes,
        public readonly array $marqueurs
    ) {
    }

    public static function lire(
        \mysqli $actes,
        string $prefixeActes,
        string $composer,
        ?\mysqli $comptes = null,
        ?string $prefixeComptes = null
    ): self {
        $comptes ??= $actes;
        $prefixeComptes ??= $prefixeActes;
        foreach ([$prefixeActes, $prefixeComptes] as $prefixe) {
            if (preg_match('/\A[A-Za-z0-9_]{1,55}\z/', $prefixe) !== 1) {
                throw new \InvalidArgumentException('Préfixe de tables invalide.');
            }
        }
        try {
            $livraison = Livraison::lire($composer);
        } catch (\RuntimeException) {
            return self::resultat(self::LIVRAISON_INVALIDE, null);
        }
        try {
            return self::examiner($actes, $prefixeActes, $comptes, $prefixeComptes, $livraison);
        } catch (\mysqli_sql_exception | \RuntimeException) {
            return self::resultat(self::ILLISIBLE, $livraison);
        }
    }

    private static function examiner(\mysqli $actes, string $prefixeActes, \mysqli $comptes, string $prefixeComptes, Livraison $livraison): self
    {
        $inventaire = self::lignes($actes, 'SHOW TABLES');
        $tables = self::tables($inventaire, $prefixeActes);
        $tablesComptes = self::tables($comptes === $actes ? $inventaire : self::lignes($comptes, 'SHOW TABLES'), $prefixeComptes);
        if ($tables === null || $tablesComptes === null) {
            return self::resultat(self::INCOHERENTE, $livraison);
        }
        if ($tables === [] && $tablesComptes === []) {
            return self::resultat(self::BASE_VIDE, $livraison);
        }
        $params = $tables['params'] ?? null;
        if ($params === null) {
            return self::resultat(self::INCOHERENTE, $livraison);
        }
        $noms = "'" . implode("','", Marqueurs::NOMS) . "'";
        $marqueurs = [];
        $incoherent = false;
        foreach (self::lignes($actes, "SELECT param, valeur FROM `$params` WHERE param IN ($noms)") as $ligne) {
            $nom = (string) $ligne['param'];
            if (!Marqueurs::est($nom) || array_key_exists($nom, $marqueurs)) {
                $incoherent = true;
                continue;
            }
            $marqueurs[$nom] = $ligne['valeur'] === null ? null : (string) $ligne['valeur'];
        }
        if ($incoherent) {
            return self::resultat(self::INCOHERENTE, $livraison, $marqueurs);
        }
        // La lecture précédente vérifie les colonnes avant d'admettre une table vide.
        // Des noms identiques sur deux liens ne suffisent pas : vérifier aussi le contenu.
        if (count($tables) === 1 && array_diff(array_values($tablesComptes), [$params]) === []
            && (int) self::lignes($actes, "SELECT COUNT(*) AS total FROM `$params`")[0]['total'] === 0
            && ($comptes === $actes || $tablesComptes === [] || (int) self::lignes($comptes, "SELECT COUNT(*) AS total FROM `$params`")[0]['total'] === 0)) {
            return self::resultat(self::BASE_VIDE, $livraison);
        }
        $expo = null;
        if (array_key_exists('ACTALIBRE_VERSION', $marqueurs)) {
            $produit = $marqueurs['ACTALIBRE_VERSION'];
            $schema = $marqueurs['ACTALIBRE_SCHEMA'] ?? null;
            if (!self::versionValide($produit) || !self::versionValide($schema)) {
                return self::resultat(self::INCOHERENTE, $livraison, $marqueurs);
            }
            if (version_compare($produit, $livraison->version, '>') || version_compare($schema, $livraison->schema, '>')) {
                return self::resultat(self::RETOUR_VERSION_REFUSE, $livraison, $marqueurs);
            }
            $etat = version_compare($schema, $livraison->schema, '<') ? self::MISE_A_JOUR_SCHEMA
                : (version_compare($produit, $livraison->version, '<') ? self::MISE_A_JOUR_PRODUIT : self::A_JOUR);
        } elseif (array_key_exists('ACTALIBRE_INSTALLATION', $marqueurs)) {
            if (array_key_exists('ACTALIBRE_MIGRATION', $marqueurs)
                || self::lignes($actes, "SELECT 1 FROM `$params` WHERE param = 'EA_VERSION' LIMIT 1") !== []) {
                return self::resultat(self::INCOHERENTE, $livraison, $marqueurs);
            }
            return self::resultat(self::INSTALLATION_INACHEVEE, $livraison, $marqueurs);
        } else {
            $heritees = self::lignes($actes, "SELECT valeur FROM `$params` WHERE param = 'EA_VERSION'");
            if (count($heritees) !== 1) {
                return self::resultat(self::INCOHERENTE, $livraison, $marqueurs);
            }
            $expo = trim((string) $heritees[0]['valeur']);
            if ($expo !== '3.2.4') {
                return self::resultat(self::EXPOACTES_REFUSE, $livraison, $marqueurs, $expo);
            }
            $etat = self::EXPOACTES_ADMIS;
        }
        foreach (['nai3', 'mar3', 'dec3', 'div3', 'metadb', 'metalg', 'mgrplg'] as $suffixe) {
            if (!isset($tables[$suffixe])) {
                return self::resultat(self::INCOHERENTE, $livraison, $marqueurs, $expo);
            }
        }
        if (!isset($tablesComptes['user3'])) {
            return self::resultat(self::INCOHERENTE, $livraison, $marqueurs, $expo);
        }
        $utilisateurs = $tablesComptes['user3'];
        self::lignes($comptes, "SELECT ID, login, hashpass FROM `$utilisateurs` LIMIT 0");
        return self::resultat($etat, $livraison, $marqueurs, $expo);
    }

    /** @return list<array<string,mixed>> */
    private static function lignes(\mysqli $lien, string $sql): array
    {
        $resultat = $lien->query($sql);
        if (!$resultat instanceof \mysqli_result) {
            throw new \RuntimeException('Lecture SQL impossible.');
        }
        return $resultat->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * @param list<array<string,mixed>> $inventaire
     * @return array<string,string>|null
     */
    private static function tables(array $inventaire, string $prefixe): ?array
    {
        $tables = [];
        foreach ($inventaire as $ligne) {
            $nom = (string) array_values($ligne)[0];
            if (strncasecmp($nom, $prefixe . '_', strlen($prefixe) + 1) !== 0) {
                continue;
            }
            $suffixe = strtolower(substr($nom, strlen($prefixe) + 1));
            if (isset($tables[$suffixe])) {
                return null;
            }
            $tables[$suffixe] = $nom;
        }
        return $tables;
    }

    /** @phpstan-assert-if-true string $version */
    private static function versionValide(?string $version): bool
    {
        return $version !== null && preg_match('/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\z/', $version) === 1;
    }

    /** @param array<string,?string> $marqueurs */
    private static function resultat(string $etat, ?Livraison $livraison, array $marqueurs = [], ?string $expo = null): self
    {
        $diagnostic = match ($etat) {
            self::LIVRAISON_INVALIDE => 'Livraison invalide : vérifier composer.json.',
            self::ILLISIBLE => 'Impossible de lire les métadonnées du site.',
            self::BASE_VIDE => 'Aucune donnée applicative installée.',
            self::INSTALLATION_INACHEVEE => 'Installation inachevée : reprise nécessaire.',
            self::INCOHERENTE => 'Métadonnées du site incohérentes.',
            self::EXPOACTES_ADMIS => 'Base ExpoActes 3.2.4 admissible à la migration.',
            self::EXPOACTES_REFUSE => 'Version ExpoActes non admise : seule la version 3.2.4 est acceptée.',
            self::RETOUR_VERSION_REFUSE => 'Retour à une version antérieure refusé.',
            self::MISE_A_JOUR_SCHEMA => 'Mise à jour du schéma nécessaire.',
            self::MISE_A_JOUR_PRODUIT => 'Mise à jour du produit nécessaire.',
            self::A_JOUR => 'Site à jour.',
            default => throw new \LogicException('État inconnu.'),
        };
        return new self($etat, $diagnostic, $livraison, $marqueurs['ACTALIBRE_VERSION'] ?? null, $marqueurs['ACTALIBRE_SCHEMA'] ?? null, $expo, $marqueurs);
    }
}
