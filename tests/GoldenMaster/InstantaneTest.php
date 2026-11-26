<?php
declare(strict_types=1);

namespace ActaLibre\Tests\GoldenMaster;

use PHPUnit\Framework\TestCase;

/** Le site partagé revient, à chaque appel, à l'état qui suit son installation et le chargement des données. */
final class InstantaneTest extends TestCase
{
    private const DOSSIERS = ['tmp', '_config', 'admin/_upload'];

    /** @return array{hote:string,nom:string,utilisateur:string,passe:string} */
    private static function connexion(): array
    {
        $hote = getenv('GM_DB_HOST');
        $nom = getenv('GM_DB_NAME');
        $utilisateur = getenv('GM_DB_USER');
        $passe = getenv('GM_DB_PASS');
        if ($hote === false || $nom === false || $utilisateur === false || $passe === false) {
            self::markTestSkipped('InstantaneTest ignoré : définir GM_DB_HOST, GM_DB_NAME, GM_DB_USER et GM_DB_PASS.');
        }
        return ['hote' => $hote, 'nom' => $nom, 'utilisateur' => $utilisateur, 'passe' => $passe];
    }

    /** Structure exacte (AUTO_INCREMENT compris) et contenu de chaque table gm_*, puis chaque dossier et l'empreinte de chaque fichier écrit par le site. */
    private static function empreinte(SiteJetable $site): string
    {
        $empreinte = '';
        foreach ($site->base->query("SHOW TABLES LIKE 'gm\\_%'")->fetch_all() as [$table]) {
            $lignes = [];
            foreach ($site->base->query("SELECT * FROM `$table`")->fetch_all() as $ligne) {
                // serialize() garde chaque octet : les données historiques ne sont pas toutes en UTF-8 valide.
                $lignes[] = serialize($ligne);
            }
            sort($lignes);
            $empreinte .= $site->base->query("SHOW CREATE TABLE `$table`")->fetch_row()[1] . "\n" . hash('sha256', implode("\n", $lignes)) . "\n";
        }
        foreach (self::DOSSIERS as $dossier) {
            $fichiers = [];
            $elements = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($site->racine . '/' . $dossier, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($elements as $element) {
                $chemin = substr($element->getPathname(), strlen($site->racine));
                // Les dossiers comptent aussi : un sous-dossier vide ajouté doit se voir.
                $fichiers[] = $element->isDir() ? $chemin . '/' : $chemin . ' ' . hash_file('sha256', $element->getPathname());
            }
            sort($fichiers);
            $empreinte .= implode("\n", $fichiers) . "\n";
        }
        return $empreinte;
    }

    public function testLeSitePartageRevientALEtatDeDepart(): void
    {
        $site = SiteJetable::partage(self::connexion());
        self::assertDirectoryDoesNotExist($site->racine . '/.superpowers', 'Les preuves privées restent hors du site servi');
        self::assertDirectoryDoesNotExist($site->racine . '/.phpstan.cache');
        self::assertDirectoryDoesNotExist($site->racine . '/graft');
        self::assertSame(['1.0.0','1.2.1'], array_column($site->base->query("SELECT valeur FROM gm_params WHERE param IN ('ACTALIBRE_SCHEMA','ACTALIBRE_VERSION') ORDER BY param")->fetch_all(MYSQLI_ASSOC),'valeur'));
        self::assertSame('0',$site->base->query("SELECT COUNT(*) FROM gm_params WHERE param='EA_VERSION'")->fetch_row()[0]);
        $rapport = file_get_contents($site->racine . '/tmp/actalibre/protection.json');
        $avant = self::empreinte($site);

        $site->base->query("UPDATE gm_params SET valeur = 'http://modifie.example' WHERE param = 'URL_PUBLIQUE'");
        $site->base->query("INSERT INTO gm_nai3 (COMMUNE, DEPART, TYPACT, NOM, PRE, DATETXT, LADATE, DEPOSANT) VALUES ('Intrus', 'XA', 'N', 'INTRUS', 'Test', '01/01/2000', '2000-01-01', 1)");
        $site->base->query('DELETE FROM gm_nai3 WHERE ID = (SELECT m FROM (SELECT MIN(ID) AS m FROM gm_nai3) AS t)');
        $site->base->query('DROP TABLE gm_acte_vu');
        $site->base->query('CREATE TABLE gm_intrus (id INT)');
        file_put_contents($site->racine . '/tmp/backups/intrus.txt', 'x');
        file_put_contents($site->racine . '/tmp/actalibre/protection.json', '{}');
        // Fichiers présents dans l'instantané : l'un est modifié, l'autre supprimé.
        self::assertFileExists($site->racine . '/tmp/backups/actes_N.001.bea');
        file_put_contents($site->racine . '/tmp/backups/actes_N.001.bea', 'modifié');
        self::assertFileExists($site->racine . '/tmp/logs/courriels.txt', "La fixture fournit un fichier témoin de courriels");
        unlink($site->racine . '/tmp/logs/courriels.txt');
        mkdir($site->racine . '/admin/_upload/vide');
        $site->client('niveau9')->get('/index.php');
        self::assertNotSame($avant, self::empreinte($site), 'Les modifications sont bien visibles');

        self::assertSame($site, SiteJetable::partage(self::connexion()), 'Un seul site par exécution');
        self::assertSame($avant, self::empreinte($site));
        self::assertSame($rapport, file_get_contents($site->racine . '/tmp/actalibre/protection.json'));
    }

    public function testInventairePrecedeLesDonneesEtNeMasquePasLesInconnus(): void
    {
        $site=SiteJetable::partage(self::connexion());
        $i=\ActaLibre\Install\Inventaire::lire($site->racine);
        self::assertNotContains('tmp/actalibre/protection.json',$i->fichiers());
        $f=$site->racine.'/inconnu-test.php';file_put_contents($f,'SENTINELLE INERTE');
        try {
            $r=new \ActaLibre\Install\Conservation($i,(string)getenv('GM_DB_NAME'),'_upload');
            $liste=iterator_to_array((new \ActaLibre\Install\SignalementFichiers($site->racine,$i,$r))->lister(),false);
            self::assertContains('inconnu-test.php',array_column($liste,'chemin'));
            self::assertNotContains('tmp/.htaccess',array_column($liste,'chemin'));
            self::assertSame('SENTINELLE INERTE',file_get_contents($f));
            self::assertContains(\ActaLibre\Install\RapportProtection::lire($site->racine)->etat,['refuse','indetermine']);
        } finally { unlink($f); }
    }

    /** La réinitialisation efface les sessions : un client gardé en mémoire ne serait plus connecté. */
    public function testLesClientsSeReconnectentApresUneReinitialisation(): void
    {
        $site = SiteJetable::partage(self::connexion());
        self::assertSame(302, $site->client('nouveau')->get('/admin/listusers.php')->statut, 'Page réservée au niveau 9');
        self::assertSame(200, $site->client('niveau9')->get('/admin/listusers.php')->statut, 'Avant la réinitialisation');
        $site = SiteJetable::partage(self::connexion());
        self::assertSame(200, $site->client('niveau9')->get('/admin/listusers.php')->statut, 'Après la réinitialisation');
    }

    public function testUnAutreSiteNEffacePasLInstantane(): void
    {
        $site = SiteJetable::partage(self::connexion());
        $avant = self::empreinte($site);
        // Comme MiseAJourTest : un site séparé, arrêté, efface les tables gm_*.
        (new SiteJetable(dirname(__DIR__, 2), self::connexion()))->arreter();
        self::assertSame($avant, self::empreinte(SiteJetable::partage(self::connexion())));
    }

    public function testUneAutreExecutionNePeutPasPrendreLaBase(): void
    {
        $connexion = self::connexion();
        new SiteJetable(dirname(__DIR__, 2), $connexion);
        $autre = new \mysqli($connexion['hote'], $connexion['utilisateur'], $connexion['passe'], $connexion['nom']);
        try {
            $verrou = $autre->real_escape_string($connexion['nom'] . '.actalibre_golden_master');
            self::assertSame('0', (string) $autre->query("SELECT GET_LOCK('$verrou', 0)")->fetch_row()[0]);
        } finally {
            $autre->close();
        }
    }

    public function testDeuxSitesNOntPasLeMemeDossier(): void
    {
        $connexion = self::connexion();
        self::assertNotSame(
            (new SiteJetable(dirname(__DIR__, 2), $connexion))->racine,
            (new SiteJetable(dirname(__DIR__, 2), $connexion))->racine
        );
    }
}
