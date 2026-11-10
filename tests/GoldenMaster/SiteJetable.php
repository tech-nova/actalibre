<?php
declare(strict_types=1);

namespace ActaLibre\Tests\GoldenMaster;

/** Site jetable : copie du code, tables gm_*, serveur PHP local, données fictives. partage() le construit une fois et le remet à zéro ensuite. */
final class SiteJetable
{
    private const EXCLUS = ['.git', 'vendor', 'tests', 'docs', 'graft', '.superpowers', '.phpunit.cache', '.phpstan.cache', '_config', '_backup', '_logs', 'tmp', 'dist'];
    private const COMPTE_INSTALLATION = ['login' => 'fictif_inst', 'passe' => 'Test-inst-2026'];
    public const COMPTES = [
        'niveau1' => ['fictif_public', 'Test-public-2026'],
        'niveau6' => ['fictif_depot', 'Test-depot-2026'],
        'niveau9' => ['fictif_admin', 'Test-admin-2026'],
        'sortant' => ['fictif_inst', 'Test-inst-2026'],
        'changepw' => ['fictif_inst', 'Test-inst-2026'],
        'temoin' => ['fictif_inst', 'Test-inst-2026'],
    ];
    public const ACTES_PAR_TYPE = 400;
    private const TABLES = ['N' => 'gm_nai3', 'M' => 'gm_mar3', 'D' => 'gm_dec3', 'V' => 'gm_div3'];
    private const PREFIXE_INSTANTANE = 'gmi_';
    /** Dossiers que le site écrit : ils sont remis à l'identique à chaque réinitialisation. */
    private const DOSSIERS_ETAT = ['tmp', '_config', 'admin/_upload'];
    public const VERROU = 'actalibre_golden_master';

    private static ?self $partage = null;
    /** Connexion qui garde le verrou jusqu'à la fin du processus. */
    private static ?\mysqli $verrou = null;

    public readonly string $racine;
    public readonly string $journal;
    private readonly string $boite;
    public readonly \mysqli $base;
    private int $port = 0;
    /** @var resource|null */
    private $serveur = null;
    /** @var array<string,Client> */
    private array $clients = [];
    private readonly string $copieEtat;
    private readonly string $infrastructure;
    /** @var array<string,string> table gm_* => SHOW CREATE TABLE au moment de l'instantané */
    private array $schemas = [];

    /**
     * @param array{hote:string,nom:string,utilisateur:string,passe:string} $connexion
     * @param list<string> $reglagesPhp réglages du serveur de test, au format nom=valeur
     */
    public function __construct(private readonly string $depot, private readonly array $connexion, private readonly array $reglagesPhp = [])
    {
        $this->racine = sys_get_temp_dir() . '/actalibre-gm-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $this->copieEtat = $this->racine . '-instantane';
        $this->infrastructure = $this->racine . '-amorcage';
        $this->journal = $this->racine . '/tmp/logs/php.log';
        $this->boite = $this->racine . '/tmp/logs/courriels.txt';
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        self::verrouiller($connexion);
        $this->base = new \mysqli($connexion['hote'], $connexion['utilisateur'], $connexion['passe'], $connexion['nom']);
    }

    /**
     * Deux exécutions du golden master sur la même base s'écraseraient leurs tables gm_*.
     * Le verrou est libéré par MySQL à la fin du processus, même après un arrêt brutal.
     * @param array{hote:string,nom:string,utilisateur:string,passe:string} $connexion
     */
    private static function verrouiller(array $connexion): void
    {
        if (self::$verrou !== null) {
            return;
        }
        $lien = new \mysqli($connexion['hote'], $connexion['utilisateur'], $connexion['passe'], $connexion['nom']);
        $nom = $lien->real_escape_string($connexion['nom'] . '.' . self::VERROU);
        if ((string) $lien->query("SELECT GET_LOCK('$nom', 0)")->fetch_row()[0] !== '1') {
            $lien->close();
            throw new \RuntimeException('Un autre golden master utilise déjà la base ' . $connexion['nom'] . ' : attendre sa fin.');
        }
        self::$verrou = $lien;
    }

    public function demarrer(): void
    {
        $this->supprimerTables();
        $this->copierCode();
        \ActaLibre\Tests\Infrastructure\InventaireDuBanc::ecrire($this->racine);
        foreach (['.htaccess', '_config', 'tmp', 'admin/_upload'] as $destination) {
            self::exiger(!file_exists($this->racine . '/' . $destination), 'Destination préparée avant installation : ' . $destination);
        }
        mkdir($this->infrastructure, 0700);
        $this->lancerServeur(true);
        $this->installer();
        $this->arreterServeur();
        self::exiger(!is_file($this->infrastructure . '/php.log') || file_get_contents($this->infrastructure . '/php.log') === '', 'Erreur PHP pendant l’installation');
        $this->ecrireConfiguration();
        $this->lancerServeur();
        self::supprimerDossier($this->infrastructure);
        $this->charger();
    }

    /**
     * Site commun à toute l'exécution : construit au premier appel, remis ensuite
     * dans l'état qui suit l'installation et le chargement des données fictives.
     * Limite : les statistiques des index (SHOW INDEX) d'une table recopiée diffèrent de celles
     * d'un site neuf. Un test qui les affiche doit construire son propre site.
     * @param array{hote:string,nom:string,utilisateur:string,passe:string} $connexion
     */
    public static function partage(array $connexion): self
    {
        if (self::$partage !== null) {
            self::$partage->reinitialiser();
            return self::$partage;
        }
        $site = new self(dirname(__DIR__, 2), $connexion);
        try {
            $site->demarrer();
            $site->figer();
        } catch (\Throwable $erreur) {
            $site->arreter();
            throw $erreur;
        }
        register_shutdown_function([$site, 'arreter']);
        return self::$partage = $site;
    }

    public function reinitialiser(): void
    {
        $this->supprimerTables();
        foreach ($this->schemas as $table => $schema) {
            $this->base->query($schema);
            $this->base->query("INSERT INTO `$table` SELECT * FROM `" . self::PREFIXE_INSTANTANE . substr($table, 3) . '`');
        }
        foreach (self::DOSSIERS_ETAT as $dossier) {
            self::supprimerDossier($this->racine . '/' . $dossier);
            self::copierDossier($this->copieEtat . '/' . $dossier, $this->racine . '/' . $dossier);
        }
        $this->clients = [];
    }

    public function port(): int
    {
        return $this->port;
    }

    public function versionMysql(): string
    {
        return $this->base->server_info;
    }

    /** Un profil « nouveau » n'a pas de session : chaque scénario qui l'utilise repart sans cookie. */
    public function client(string $profil): Client
    {
        if ($profil === 'nouveau') {
            return new Client('http://127.0.0.1:' . $this->port);
        }
        if (!isset($this->clients[$profil])) {
            $client = new Client('http://127.0.0.1:' . $this->port);
            if (isset(self::COMPTES[$profil])) {
                [$login, $passe] = self::COMPTES[$profil];
                $client->post('/acces.php', ['login' => $login, 'passwd' => $passe]);
                $client->post('/admin/index.php', ['login' => $login, 'passwd' => $passe]);
            }
            $this->clients[$profil] = $client;
        }
        return $this->clients[$profil];
    }

    /** Pose le code attendu : l'image du captcha ne peut pas être lue par un test. */
    public function poserCaptcha(string $sessionPhp, string $code): void
    {
        file_put_contents(dirname($this->journal) . '/sess_' . $sessionPhp, 'valeur_image|s:64:"' . hash('sha256', $code) . '";');
    }

    /** Tous les courriels envoyés par le site depuis son démarrage. */
    public function courriels(): string
    {
        return is_file($this->boite) ? (string) file_get_contents($this->boite) : '';
    }

    /** Sans date commune, l'ordre du journal dépendrait des lignes écrites dans la même seconde. */
    public function figerJournal(): void
    {
        $this->base->query("UPDATE gm_log SET DATE = '2024-01-15 12:00:00'");
    }

    public function arreter(): void
    {
        $this->arreterServeur();
        $this->supprimerTables();
        // Un autre site du même processus ne doit pas effacer l'instantané du site partagé.
        if ($this->schemas !== []) {
            $this->supprimerInstantane();
        }
        self::supprimerDossier($this->racine);
        self::supprimerDossier($this->copieEtat);
        self::supprimerDossier($this->infrastructure);
        if (self::$partage === $this) {
            self::$partage = null;
        }
    }

    private function supprimerTables(): void
    {
        foreach ($this->base->query("SHOW TABLES LIKE 'gm\\_%'")->fetch_all() as [$table]) {
            $this->base->query("DROP TABLE `$table`");
        }
    }

    /** Copie les tables gm_* en gmi_* (le motif 'gm\_%' ne les voit pas) et les dossiers écrits par le site. */
    private function figer(): void
    {
        $this->supprimerInstantane();
        foreach ($this->base->query("SHOW TABLES LIKE 'gm\\_%'")->fetch_all() as [$table]) {
            $copie = self::PREFIXE_INSTANTANE . substr($table, 3);
            $this->schemas[$table] = (string) $this->base->query("SHOW CREATE TABLE `$table`")->fetch_row()[1];
            $this->base->query("CREATE TABLE `$copie` LIKE `$table`");
            $this->base->query("INSERT INTO `$copie` SELECT * FROM `$table`");
        }
        foreach (self::DOSSIERS_ETAT as $dossier) {
            self::copierDossier($this->racine . '/' . $dossier, $this->copieEtat . '/' . $dossier);
        }
    }

    /** Efface aussi un instantané laissé par une exécution interrompue. */
    private function supprimerInstantane(): void
    {
        foreach ($this->base->query("SHOW TABLES LIKE 'gmi\\_%'")->fetch_all() as [$table]) {
            $this->base->query("DROP TABLE `$table`");
        }
        $this->schemas = [];
    }

    private function copierCode(): void
    {
        $dossiers = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($this->depot, \FilesystemIterator::SKIP_DOTS),
                fn (\SplFileInfo $f): bool => !in_array($f->getFilename(), self::EXCLUS, true)
                    && $f->getPathname() !== $this->depot . '/admin/_upload'
                    && !$f->isLink()
                    && \ActaLibre\Tests\Infrastructure\InventaireDuBanc::livrable(substr($f->getPathname(),strlen($this->depot)+1))
            ),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        mkdir($this->racine);
        foreach ($dossiers as $element) {
            $cible = $this->racine . substr($element->getPathname(), strlen($this->depot));
            $element->isDir() ? mkdir($cible) : copy($element->getPathname(), $cible);
        }
    }

    private function ecrireConfiguration(): void
    {
        file_put_contents($this->racine . '/_config/connect.inc.php', sprintf(
            "<?php\ndefine('EA_DB', 'gm');\ndefine('EA_ERROR', 3);\ndefine('GEOCODEUR_URL', 'http://127.0.0.1:9/');\n\$dbaddr = %s;\n\$dbname = %s;\n\$dbuser = %s;\n\$dbpass = %s;\n",
            var_export($this->connexion['hote'], true),
            var_export($this->connexion['nom'], true),
            var_export($this->connexion['utilisateur'], true),
            var_export($this->connexion['passe'], true)
        ));
    }

    private function arreterServeur(): void
    {
        if (is_resource($this->serveur)) {
            proc_terminate($this->serveur);
            proc_close($this->serveur);
        }
        $this->serveur = null;
    }

    private function lancerServeur(bool $avantInstallation = false): void
    {
        if ($this->port === 0) {
            $sonde = stream_socket_server('tcp://127.0.0.1:0');
            $this->port = (int) substr(strrchr(stream_socket_get_name($sonde, false), ':'), 1);
            fclose($sonde);
        }
        $dossier = $avantInstallation ? $this->infrastructure : dirname($this->journal);
        $journal = $avantInstallation ? $dossier . '/php.log' : $this->journal;
        $boite = $avantInstallation ? $dossier . '/courriels.txt' : $this->boite;
        $reglages = [];
        foreach ($this->reglagesPhp as $reglage) {
            array_push($reglages, '-d', $reglage);
        }
        $this->serveur = proc_open(
            [
                PHP_BINARY, ...$reglages, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . $journal,
                '-d', 'max_execution_time=120', '-d', 'session.save_path=' . $dossier, '-d', 'sendmail_path=tee -a ' . escapeshellarg($boite) . ' > /dev/null',
                '-S', '127.0.0.1:' . $this->port, '-t', $this->racine, __DIR__ . '/routeur.php',
            ],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $tubes,
            $this->racine
        );
        $limite = microtime(true) + 5;
        while (microtime(true) < $limite) {
            $connexion = @fsockopen('127.0.0.1', $this->port, $code, $message, 0.2);
            if ($connexion !== false) {
                fclose($connexion);
                return;
            }
            usleep(100000);
        }
        throw new \RuntimeException('Le serveur de test ne répond pas');
    }

    private function installer(): void
    {
        $client = new Client('http://127.0.0.1:' . $this->port);
        $formulaire = $client->get('/install.php');
        self::exiger(preg_match('/name="jeton" value="([0-9a-f]{64})"/', $formulaire->corps, $jeton) === 1, 'Jeton d’installation absent');
        $reponse = $client->post('/install.php', [
            'url_site' => 'http://127.0.0.1:' . $this->port . '/',
            'jeton' => $jeton[1], 'serveur' => $this->connexion['hote'], 'base' => $this->connexion['nom'],
            'utilisateur' => $this->connexion['utilisateur'], 'mot_de_passe_mysql' => $this->connexion['passe'], 'prefixe' => 'gm',
            'login' => self::COMPTE_INSTALLATION['login'], 'courriel' => 'installation@example.org',
            'mot_de_passe' => self::COMPTE_INSTALLATION['passe'], 'confirmation' => self::COMPTE_INSTALLATION['passe'],
        ]);
        self::exiger($reponse->statut === 200 && str_contains($reponse->corps, 'Installation terminée'), 'Installation : HTTP ' . $reponse->statut);
        self::exiger(is_file($this->racine . '/_config/connect.inc.php'), 'Connexion non publiée');
        self::exiger(\ActaLibre\Install\EtatSite::lire($this->base, 'gm', $this->racine . '/composer.json')->etat === 'a_jour', 'Installation non finalisée');
        foreach (['.htaccess', '_config/.htaccess', '_config/actes.css', 'tmp/.htaccess', 'tmp/logs/.keep', 'tmp/backups/.keep', 'admin/_upload/.htaccess'] as $destination) {
            self::exiger(is_file($this->racine . '/' . $destination), 'Destination non préparée : ' . $destination);
        }
        self::exiger($this->valeur("SELECT valeur FROM gm_params WHERE param = 'URL_PUBLIQUE'") !== '', 'Adresse publique non remplie');
        $this->base->query("UPDATE gm_params SET valeur = '' WHERE param = 'URL_PUBLIQUE'");
    }

    private function charger(): void
    {
        $this->personnaliserLeBanc();
        self::exiger($this->valeur('SELECT COUNT(*) FROM gm_log') === '0', 'L’installateur a écrit dans le journal');
        $action = '127.0.0.1:Création de la base de données';
        $requete = $this->base->prepare("INSERT INTO gm_log (`user`, `date`, `action`, commune, nb_actes) VALUES (0, '2024-01-15 12:00:00', ?, '-', 0)");
        $requete->execute([$action]);
        self::exiger($this->base->query('SELECT * FROM gm_log')->fetch_all() === [['0', '2024-01-15 12:00:00', $action, '-', '0']], 'Événement historique du journal incorrect');
        file_put_contents($this->boite, ''); // Témoin de fichier restauré par InstantaneTest ; aucun courrier envoyé.
        $donnees = $this->depot . '/tests/Donnees';
        $admin = new Client('http://127.0.0.1:' . $this->port);
        $admin->post('/admin/index.php', [
            'login' => self::COMPTE_INSTALLATION['login'], 'passwd' => self::COMPTE_INSTALLATION['passe'],
        ]);
        $admin->post('/admin/loaduser.php', ['action' => 'submitted', 'lelevel' => '1', 'regime' => '0'], ['Users' => "$donnees/utilisateurs.csv"]);
        self::exiger($this->valeur('SELECT COUNT(*) FROM gm_user3 WHERE ID IN (11,16,19)') === '3', 'Comptes non chargés');

        $this->enregistrerCoordonnees($donnees);
        foreach (self::TABLES as $type => $table) {
            $nom = "actes_$type.001.bea";
            copy("$donnees/$nom", $this->racine . "/tmp/backups/$nom");
            $reponse = $admin->post('/admin/charge.php', ['Origine' => 'B', 'action' => 'submitted', 'TypeActes' => $type, 'bkfile' => "../tmp/backups/$nom"]);
            for ($reprise = 0; $reprise < 20 && preg_match('/autokey=([0-9a-f]{32})/', $reponse->corps, $cle) === 1; $reprise++) {
                $reponse = $admin->get('/admin/charge.php', ['action' => 'go', 'TypeActes' => $type, 'Origine' => 'B', 'autokey' => $cle[1], 'jeton' => Client::JETON]);
            }
            self::exiger(!str_contains($reponse->corps, 'géocodé'), 'Géocodage tenté');
            self::exiger($this->valeur("SELECT COUNT(*) FROM $table") === (string) self::ACTES_PAR_TYPE, "$table incomplète");
        }

        foreach (array_keys(self::TABLES) as $type) {
            $reponse = $admin->get('/admin/maj_sums.php', ['xtyp' => $type, 'mode' => 'A', 'com' => '', 'jeton' => Client::JETON]);
            self::exiger(!str_contains($reponse->corps, 'géocodé'), 'Géocodage tenté');
        }
        self::exiger($this->valeur('SELECT COUNT(*) FROM gm_sums') !== '0', 'Statistiques non calculées');
    }

    /** Valeurs de l’ancien banc, après preuve des vrais défauts de l’installation neuve. */
    private function personnaliserLeBanc(): void
    {
        $this->base->set_charset('utf8mb4');
        foreach (['ACTALIBRE_SCHEMA', 'ACTALIBRE_VERSION'] as $nom) {
            self::exiger($this->valeur("SELECT valeur FROM gm_params WHERE param = '$nom'") === '1.0.0', 'Marqueur neuf absent');
        }
        self::exiger($this->valeur("SELECT COUNT(*) FROM gm_params WHERE param IN ('ACTALIBRE_INSTALLATION','ACTALIBRE_MIGRATION','EA_VERSION')") === '0', 'Marqueur temporaire ou historique inattendu');
        $q = $this->base->prepare("UPDATE gm_user3 SET nom='FICTIF', prenom='Installation' WHERE login=?");
        $q->execute([self::COMPTE_INSTALLATION['login']]);
        $historique = [
            'SITENAME' => ['Mon site principal', 'Expoactes'], 'SITE_URL' => ['/', ''],
            'CHERCH_TS_TYP' => ['1', '0'], 'ECLAIR_LOG' => ['1', '0'], 'EA_ERROR' => ['0', '3'],
            'PUB_ZONE_MENU' => ['', 'Zone info libre'],
        ];
        foreach ($historique as $nom => [$avant, $apres]) {
            self::exiger($this->valeur("SELECT COUNT(*) FROM gm_params WHERE param = '$nom'") === '1', 'Paramètre historique absent ou dupliqué');
            self::exiger($this->valeur("SELECT valeur FROM gm_params WHERE param = '$nom'") === $avant, 'Défaut XML inattendu : ' . $nom);
            $q = $this->base->prepare('UPDATE gm_params SET valeur=? WHERE param=?');
            $q->execute([$apres, $nom]);
            self::exiger($this->valeur("SELECT valeur FROM gm_params WHERE param = '$nom'") === $apres, 'Valeur historique non vérifiée');
        }
    }

    /** Des coordonnées déjà connues évitent tout géocodage vers Google quand les statistiques se calculent. */
    private function enregistrerCoordonnees(string $donnees): void
    {
        $this->base->set_charset('utf8mb4');
        $requete = $this->base->prepare("INSERT IGNORE INTO gm_geoloc (COMMUNE, DEPART, LON, LAT, STATUT) VALUES (?, ?, 2.5, 47.5, 'M')");
        foreach (file("$donnees/actes_N.001.bea") as $ligne) {
            $champs = str_getcsv($ligne, ';', '"', '');
            $requete->execute([$champs[2], $champs[4]]);
            // admin/suppr_acte.php recalcule les statistiques sans transmettre le département.
            $requete->execute([$champs[2], '']);
        }
    }

    private function valeur(string $requete): string
    {
        return (string) $this->base->query($requete)->fetch_row()[0];
    }

    private static function exiger(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException('Chargement : ' . $message);
        }
    }

    private static function copierDossier(string $source, string $cible): void
    {
        mkdir($cible, 0777, true);
        $elements = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($elements as $element) {
            $chemin = $cible . substr($element->getPathname(), strlen($source));
            $element->isDir() ? mkdir($chemin) : copy($element->getPathname(), $chemin);
        }
    }

    private static function supprimerDossier(string $dossier): void
    {
        if (!is_dir($dossier)) {
            return;
        }
        $elements = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dossier, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($elements as $element) {
            $element->isDir() ? rmdir($element->getPathname()) : unlink($element->getPathname());
        }
        rmdir($dossier);
    }
}
