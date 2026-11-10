<?php
declare(strict_types=1);

namespace ActaLibre\Tests\Donnees;

/**
 * Produit le jeu de données fictif du golden master.
 * Aucune valeur ne vient de données réelles ; seuls les taux de remplissage s'en inspirent.
 */
final class GenerateurDonnees
{
    public const GRAINE = 20261104;
    public const ACTES_PAR_TYPE = 400;
    public const MARIAGES_CSV = 300;

    /** Ordre des colonnes EA3 : table act_metadb, tri par OV3 (BIDON porte l'en-tête de ligne). */
    private const DEBUT = 'BIDON CODCOM COMMUNE CODDEP DEPART TYPACT DATETXT DREPUB COTE LIBRE';
    private const TEMOINS_2 = 'T1_NOM T1_PRE T1_COM T2_NOM T2_PRE T2_COM';
    private const TEMOINS_4 = 'T1_NOM T1_PRE T1_COM T2_NOM T2_PRE T2_COM T3_NOM T3_PRE T3_COM T4_NOM T4_PRE T4_COM';
    private const PARENTS = 'P_NOM P_PRE P_COM P_PRO M_NOM M_PRE M_COM M_PRO';
    private const PARENTS_C = 'CP_NOM CP_PRE CP_COM CP_PRO CM_NOM CM_PRE CM_COM CM_PRO';
    private const FIN_NIM = 'COMGEN IDNIM PHOTOS';
    private const FIN_EA = 'LADATE ID DEPOSANT PHOTOGRA RELEVEUR VERIFIEU DTDEPOT DTMODIF';

    /** Comptes créés avec des ID fixes, au-dessus de l'administrateur créé à l'installation. */
    private const ID_DEPOSANT = 16;
    private const DATE_DEPOT = '2024-01-15';

    private const COMMUNES = [
        ['XA001', 'Saint-Ormel', 'XA', 'Hautes-Brumes'],
        ['XA002', 'Montbrisac', 'XA', 'Hautes-Brumes'],
        ['XB001', "L'Orméane", 'XB', "Val d'Ombre"],
        ['XB002', 'Pontarèze', 'XB', "Val d'Ombre"],
        ['XC001', 'La Ferté-Ombreuse', 'XC', 'Côte-Sauvage'],
        ['XC002', 'Vauclairmont', 'XC', 'Côte-Sauvage'],
    ];

    private const NOMS = [
        'ABRANEL', 'ALBERGUE', 'AUMERAC', 'BARTOLÈS', 'BELCOUR', 'BRISSANDE', 'CALVERON', 'CHARMOLET',
        'CORVELLE', 'DAMBRESSE', 'DELORMIAC', 'DUVERGNAC', 'ESCARVEL', 'FALCONNET', 'FERRADOU', 'GALARDIN',
        'GARANCIÈRE', 'HERBELIN', 'JOLIVAUD', 'LABRUSSAC', 'LAMBERTIN', 'LESCURAC', 'MALVIGNE', 'MARTELOUX',
        'NARBONNEL', 'ORSANDIER', 'PELLISSAC', 'PRADOUX', 'QUINTARD', 'RAVELOUX', 'ROCHEBRAC', 'SALVEROT',
        'TAILLANDOU', 'TOURNEMOUX', 'VALMOREAU', 'VERNISSAC',
    ];
    private const PRENOMS_H = [
        'Antoine', 'Baptiste', 'Claude', 'Étienne', 'François', 'Gaspard', 'Honoré', 'Jacques', 'Jean',
        'Joseph', 'Louis', 'Marius', 'Pierre', 'Théodore',
    ];
    private const PRENOMS_F = [
        'Anaïs', 'Antoinette', 'Bonne', 'Catherine', 'Élisabeth', 'Françoise', 'Geneviève', 'Jeanne',
        'Louise', 'Madeleine', 'Marguerite', 'Marie', 'Rosalie', 'Thérèse',
    ];
    private const METIERS = [
        'cultivateur', 'tisserand', 'maréchal-ferrant', 'instituteur', 'couturière', 'meunier', 'notaire',
        'charpentier', 'boulanger', 'journalier',
    ];
    private const NOTES = [
        'Acte en marge du registre', 'Signature illisible', 'Présence du curé de la paroisse',
        'Page abîmée en bas de feuillet', 'Mention ajoutée plus tard',
    ];
    private const DIVERS = [
        ['CMar', 'Contrat de mariage'], ['Test', 'Testament'], ['RgMat', 'Registre Matricule'],
        ['Recen', 'Recensement'], ['Bans', 'Publication de bans'],
    ];

    /** Taux inspirés des fichiers réels ; les zones absentes valent TAUX_COUVERTURE. */
    private const TAUX = [
        'N' => ['PRE' => 100, 'SEXE' => 100, 'P_NOM' => 96, 'P_PRE' => 96, 'M_NOM' => 97, 'M_PRE' => 97, 'COMGEN' => 60],
        'M' => ['PRE' => 100, 'ORI' => 88, 'P_NOM' => 100, 'P_PRE' => 96, 'M_NOM' => 97, 'M_PRE' => 97,
            'C_NOM' => 100, 'C_PRE' => 100, 'C_ORI' => 86, 'CP_NOM' => 100, 'CP_PRE' => 97, 'CM_NOM' => 98,
            'CM_PRE' => 98, 'COMGEN' => 60],
        'D' => ['PRE' => 100, 'SEXE' => 100, 'AGE' => 66, 'DNAIS' => 66, 'P_NOM' => 65, 'P_PRE' => 64,
            'M_NOM' => 65, 'M_PRE' => 65, 'COMGEN' => 60],
        'V' => ['PRE' => 100, 'SEXE' => 100, 'DNAIS' => 66, 'AGE' => 66, 'COM' => 26, 'PRO' => 26,
            'P_NOM' => 65, 'P_PRE' => 64, 'M_NOM' => 65, 'M_PRE' => 65, 'COMGEN' => 95],
    ];
    /** Zones vides dans les fichiers réels, remplies en partie pour que leur affichage soit couvert. */
    private const TAUX_COUVERTURE = 20;
    private const TAUX_DREPUB = 50;

    /** @var array<string,array<string,int>> table => zone => largeur */
    private array $largeurs;

    public function __construct(string $fichierSql)
    {
        $this->largeurs = self::lireLargeurs((string) file_get_contents($fichierSql));
    }

    /** @return array<string,array<string,int>> */
    public static function lireLargeurs(string $sql): array
    {
        $largeurs = [];
        preg_match_all('/CREATE TABLE IF NOT EXISTS EA_DB_(\w+) \((.*?)\n\)/s', $sql, $tables, PREG_SET_ORDER);
        foreach ($tables as [, $table, $corps]) {
            preg_match_all('/^\s*`?(\w+)`? (?:var)?char\((\d+)\)/m', $corps, $zones, PREG_SET_ORDER);
            foreach ($zones as [, $zone, $largeur]) {
                $largeurs[$table][$zone] = (int) $largeur;
            }
        }
        return $largeurs;
    }

    /** @return list<string> */
    public static function colonnes(string $type): array
    {
        $liste = match ($type) {
            'N' => self::DEBUT . ' NOM PRE SEXE COM ' . self::PARENTS . ' ' . self::TEMOINS_2
                . ' COMGEN IDNIM PHOTOS ' . self::FIN_EA,
            'M' => self::colonnesNimegue() . ' ' . self::FIN_EA,
            'D' => self::DEBUT . ' NOM PRE ORI DNAIS SEXE AGE COM PRO C_NOM C_PRE C_COM C_PRO ' . self::PARENTS
                . ' ' . self::TEMOINS_2 . ' COMGEN IDNIM PHOTOS ' . self::FIN_EA,
            'V' => self::DEBUT . ' SIGLE LIBELLE NOM PRE SEXE ORI DNAIS AGE COM PRO EXCON EXC_PRE EXC_COM '
                . self::PARENTS . ' C_NOM C_PRE C_SEXE C_ORI C_DNAIS C_AGE C_COM C_PRO C_EXCON C_X_PRE C_X_COM '
                . self::PARENTS_C . ' ' . self::TEMOINS_4 . ' COMGEN IDNIM PHOTOS ' . self::FIN_EA,
        };
        return explode(' ', $liste);
    }

    /** Colonnes NIMEGUEV3 des mariages (OV3 < 100). */
    private static function colonnesNimegue(): string
    {
        return self::DEBUT . ' NOM PRE ORI DNAIS AGE COM PRO EXCON EXC_PRE EXC_COM ' . self::PARENTS
            . ' C_NOM C_PRE C_ORI C_DNAIS C_AGE C_COM C_PRO C_EXCON C_X_PRE C_X_COM ' . self::PARENTS_C
            . ' ' . self::TEMOINS_4 . ' ' . self::FIN_NIM;
    }

    /** @return array<string,string> nom de fichier => contenu exact */
    public function fichiers(): array
    {
        mt_srand(self::GRAINE);
        $fichiers = [];
        foreach (['N', 'M', 'D', 'V'] as $type) {
            $lignes = [];
            for ($i = 0; $i < self::ACTES_PAR_TYPE; $i++) {
                $lignes[] = $this->ligneBea($type, $this->acte($type, $i));
            }
            $fichiers["actes_{$type}.001.bea"] = implode("\r\n", $lignes) . "\r\n";
        }
        $lignes = [];
        for ($i = 0; $i < self::MARIAGES_CSV; $i++) {
            $lignes[] = $this->ligneCsv($this->acte('M', $i, true));
        }
        $fichiers['mariages.csv'] = implode("\r\n", $lignes) . "\r\n";
        $fichiers['utilisateurs.csv'] = self::utilisateurs();
        return $fichiers;
    }

    /** @param array<string,string> $acte */
    public function verifierLargeurs(string $type, array $acte): void
    {
        $table = ['N' => 'nai3', 'M' => 'mar3', 'D' => 'dec3', 'V' => 'div3'][$type];
        foreach ($acte as $zone => $valeur) {
            $max = $this->largeurs[$table][$zone] ?? null;
            if ($max !== null && mb_strlen($valeur) > $max) {
                throw new \LengthException("$type/$zone : « $valeur » dépasse $max caractères");
            }
        }
    }

    /** @return array<string,string> */
    private function acte(string $type, int $i, bool $pourCsv = false): array
    {
        [$codcom, $commune, $coddep, $depart] = self::COMMUNES[$i % count(self::COMMUNES)];
        $annee = mt_rand(1650, 1920);
        $mois = mt_rand(1, 12);
        $jour = mt_rand(1, 28);
        $sexe = $type === 'M' ? 'M' : self::choisir(['M', 'F']);
        $acte = [
            'CODCOM' => $codcom, 'COMMUNE' => $commune, 'CODDEP' => $coddep, 'DEPART' => $depart,
            'TYPACT' => $type,
            'DATETXT' => sprintf('%02d/%02d/%04d', $jour, $mois, $annee),
            'COTE' => sprintf('FIC %s/%d vue %d', $coddep, mt_rand(1, 40), mt_rand(1, 300)),
            'LIBRE' => 'N° ' . ($i + 1),
            'NOM' => self::choisir(self::NOMS),
            'LADATE' => sprintf('%04d-%02d-%02d', $annee, $mois, $jour),
            'ID' => (string) ($i + 1),
            'DEPOSANT' => (string) self::ID_DEPOSANT,
            'DTDEPOT' => self::DATE_DEPOT, 'DTMODIF' => self::DATE_DEPOT,
            'IDNIM' => $pourCsv ? (string) (900001 + $i) : '0',
        ];
        if ($annee >= 1793 && $annee <= 1805 && mt_rand(1, 100) <= self::TAUX_DREPUB) {
            $acte['DREPUB'] = sprintf('%d ventôse an %d', $jour, $annee - 1792);
        }
        if ($type === 'V') {
            [$acte['SIGLE'], $acte['LIBELLE']] = self::choisir(self::DIVERS);
        }
        foreach (self::colonnes($type) as $zone) {
            if (isset($acte[$zone]) || in_array($zone, ['BIDON', 'DREPUB', 'SIGLE', 'LIBELLE'], true)) {
                continue;
            }
            if (mt_rand(1, 100) <= (self::TAUX[$type][$zone] ?? self::TAUX_COUVERTURE)) {
                $valeur = self::valeur($zone, $sexe, $annee, $i);
                if ($valeur !== null) {
                    $acte[$zone] = $valeur;
                }
            }
        }
        if ($type === 'M') {
            $acte['C_NOM'] ??= self::choisir(self::NOMS); // obligatoire
        }
        if ($pourCsv) {
            unset($acte['PHOTOS']); // vide dans les fichiers NIMEGUEV3 de référence
        }
        self::appliquerCasLimite($acte, $i, $type, $pourCsv);
        $this->verifierLargeurs($type, $acte);
        return $acte;
    }

    private static function valeur(string $zone, string $sexe, int $annee, int $i): ?string
    {
        $feminin = preg_match('/^(M_|CM_|C_X_|EXC_)/', $zone) === 1
            || (str_starts_with($zone, 'C_') && $sexe === 'M')
            || (in_array($zone, ['PRE', 'SEXE'], true) && $sexe === 'F');
        return match (true) {
            preg_match('/(^|_)NOM$/', $zone) === 1, in_array($zone, ['EXCON', 'C_EXCON'], true) => self::choisir(self::NOMS),
            preg_match('/(^|_)PRE$/', $zone) === 1 => self::choisir($feminin ? self::PRENOMS_F : self::PRENOMS_H),
            preg_match('/(^|_)COM$/', $zone) === 1 => 'hab. ' . self::COMMUNES[mt_rand(0, 5)][1],
            preg_match('/(^|_)ORI$/', $zone) === 1 => self::COMMUNES[mt_rand(0, 5)][1],
            preg_match('/(^|_)PRO$/', $zone) === 1 => self::choisir(self::METIERS),
            preg_match('/(^|_)AGE$/', $zone) === 1 => (string) mt_rand(1, 85),
            preg_match('/(^|_)DNAIS$/', $zone) === 1 => sprintf('%02d/%02d/%04d', mt_rand(1, 28), mt_rand(1, 12), $annee - mt_rand(1, 60)),
            preg_match('/(^|_)SEXE$/', $zone) === 1 => $feminin ? 'F' : 'M',
            $zone === 'COMGEN' => self::choisir(self::NOTES),
            $zone === 'PHOTOS' => 'http://example.org/registre/' . ($i + 1),
            in_array($zone, ['PHOTOGRA', 'RELEVEUR', 'VERIFIEU'], true) => self::choisir(self::PRENOMS_H) . ' ' . self::choisir(self::NOMS),
            default => null,
        };
    }

    /** Les premières lignes de chaque fichier portent les cas limites, à position fixe. */
    private static function appliquerCasLimite(array &$acte, int $i, string $type, bool $pourCsv): void
    {
        switch ($i) {
            case 0:
                $acte['NOM'] = "D'ARCY";
                $acte['PRE'] = 'Jean-Étienne';
                break;
            case 1:
                $acte['COMGEN'] = $pourCsv ? 'Sans guillemets ni séparateur' : 'Dit "le Grand" ; présent à l\'acte';
                break;
            case 2:
                $acte['DATETXT'] = '??/??/1790';
                $acte['LADATE'] = '1790-00-00';
                unset($acte['DREPUB']);
                break;
            case 3:
                $acte['PRE'] = 'Catherine ?';
                break;
            case 4:
                foreach (array_keys($acte) as $zone) {
                    if (!self::obligatoire($zone, $type, $pourCsv)) {
                        unset($acte[$zone]);
                    }
                }
                break;
            case 5:
                $acte['NOM'] = str_repeat('N', 30);
                $acte['PRE'] = str_repeat('É', 35);
                $acte['COM'] = str_repeat('C', 70);
                break;
            case 6:
                $acte['COMGEN'] = 'Âge < 30 ans & sans profession';
                break;
            case 7:
                $acte['NOM'] = $pourCsv ? 'FRANÇOIS' : 'ŒUVRARD';
                break;
        }
    }

    private static function obligatoire(string $zone, string $type, bool $pourCsv): bool
    {
        $base = ['CODCOM', 'COMMUNE', 'CODDEP', 'DEPART', 'TYPACT', 'DATETXT', 'NOM', 'LADATE', 'ID', 'DEPOSANT', 'DTDEPOT', 'DTMODIF'];
        return in_array($zone, $base, true)
            || ($type === 'M' && $zone === 'C_NOM')
            || ($type === 'V' && $zone === 'LIBELLE')
            || ($pourCsv && $zone === 'IDNIM');
    }

    /** @param array<string,string> $acte */
    private function ligneBea(string $type, array $acte): string
    {
        $ligne = 'EA32;';
        foreach (array_slice(self::colonnes($type), 1) as $zone) {
            $valeur = $acte[$zone] ?? '';
            $ligne .= ($valeur === '' ? '' : '"' . str_replace('"', '""', $valeur) . '"') . ';';
        }
        return $ligne;
    }

    /** NIMEGUEV3 : sans guillemets ; département en latin-1, autres textes en CP850, comme Team.csv. */
    private function ligneCsv(array $acte): string
    {
        $champs = ['NIMEGUEV3'];
        foreach (array_slice(explode(' ', self::colonnesNimegue()), 1) as $zone) {
            // É vaut 0x90 en CP850, octet sur lequel ea_utf8_encode() perd toute la ligne.
            $valeur = str_replace('É', 'E', $acte[$zone] ?? '');
            if (str_contains($valeur, ';') || str_contains($valeur, '"')) {
                throw new \DomainException("$zone : « $valeur » interdit dans un fichier sans guillemets");
            }
            $champs[] = self::encoder($valeur, $zone === 'DEPART' ? 'ISO-8859-1' : 'CP850');
        }
        return implode(';', $champs);
    }

    private static function encoder(string $valeur, string $encodage): string
    {
        $encode = mb_convert_encoding($valeur, $encodage, 'UTF-8');
        if (mb_convert_encoding($encode, 'UTF-8', $encodage) !== $valeur) {
            throw new \DomainException("« $valeur » n'existe pas en $encodage");
        }
        return $encode;
    }

    /** Ordre de admin/loaduser.php. Mots de passe de test en clair : loaduser les hache en sha1. */
    private static function utilisateurs(): string
    {
        $lignes = '';
        foreach ([[11, 1, 'public'], [16, 6, 'depot'], [19, 9, 'admin']] as [$id, $niveau, $role]) {
            $lignes .= implode(';', [
                'FICTIF', ucfirst($role), "$role@example.org", "fictif_$role", "Test-$role-2026",
                $niveau, 0, 100, 'Compte de test', '31/12/2033', '', $id, 'N', self::DATE_DEPOT, 0, self::DATE_DEPOT,
            ]) . "\r\n";
        }
        return $lignes;
    }

    /** @template T @param list<T> $liste @return T */
    private static function choisir(array $liste): mixed
    {
        return $liste[mt_rand(0, count($liste) - 1)];
    }
}
