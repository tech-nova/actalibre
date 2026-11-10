<?php
declare(strict_types=1);

namespace ActaLibre\Tests\GoldenMaster;

use PHPUnit\Framework\TestCase;

final class FinitionsParcoursTest extends TestCase
{
    private const COMMUNE_LONGUE = 'Saint-Jean-de-la-Grande-Vallée-Longue';

    private static SiteJetable $site;

    public static function setUpBeforeClass(): void
    {
        $hote = getenv('GM_DB_HOST');
        $nom = getenv('GM_DB_NAME');
        $utilisateur = getenv('GM_DB_USER');
        $passe = getenv('GM_DB_PASS');
        if ($hote === false || $nom === false || $utilisateur === false || $passe === false) {
            self::markTestSkipped('FinitionsParcoursTest ignoré : définir GM_DB_HOST, GM_DB_NAME, GM_DB_USER et GM_DB_PASS.');
        }
        self::$site = SiteJetable::partage(['hote' => $hote, 'nom' => $nom, 'utilisateur' => $utilisateur, 'passe' => $passe]);
    }

    /** @param array<string,scalar> $filtre @return array{0:Reponse,1:string} */
    private function chargerCsv(string $ligne, array $filtre): array
    {
        $source = sys_get_temp_dir() . '/gm-finitions-' . getmypid() . '.csv';
        file_put_contents($source, $ligne . "\n");
        $client = self::$site->client('niveau9');
        $commun = ['TypeActes' => 'N', 'Commune' => self::COMMUNE_LONGUE, 'Depart' => 'Hautes-Brumes'];
        try {
            $page = $client->post('/admin/chargecsv.php', $commun + ['action' => 'phase_1', 'submitD' => '1'], ['Actes' => $source]);
        } finally {
            unlink($source);
        }
        self::assertSame(1, preg_match('/name="fileuploaded" value="([^"]+)"/', $page->corps, $fichier));
        self::assertGreaterThan(0, preg_match_all('/name="ZID(\d+)" value="(\d+)"/', $page->corps, $zones, PREG_SET_ORDER));
        $zoneNom = self::$site->base->query("SELECT ZID FROM gm_metadb WHERE ZONE = 'NOM' AND dtable = 'N'")->fetch_row()[0];
        $parametres = $commun + $filtre + ['action' => 'phase_2', 'submitC' => '1', 'fileuploaded' => html_entity_decode($fichier[1])];
        foreach ($zones as [, $rang, $zid]) {
            $parametres['ZID' . $rang] = $zid;
            $parametres['Zone' . $rang] = $zid === $zoneNom ? '1' : '0';
            $parametres['Trait' . $rang] = '0';
        }
        return [$client->post('/admin/chargecsv.php', $parametres), 'gm_nai3'];
    }

    public function testLeFiltreDeDatesDeLaRechercheLevenshtein(): void
    {
        $client = self::$site->client('niveau6');
        $recherche = ['achercher' => 'BELCOUR', 'comp' => 'D', 'TypNDMV' => 'N', 'ComDep' => '*** Toutes ***'];
        $dans = $client->get('/chercherlevenshtein.php', $recherche + ['amin' => 1500, 'amax' => 2100]);
        self::assertStringContainsString('acte_naiss.php?xid=', $dans->corps);
        self::assertStringNotContainsString('Incorrect', $dans->corps);
        $hors = $client->get('/chercherlevenshtein.php', $recherche + ['amin' => 2100]);
        self::assertStringNotContainsString('acte_naiss.php?xid=', $hors->corps);
        self::assertStringNotContainsString('Incorrect', $hors->corps);
    }

    public function testUnImportEntierementFiltreAfficheSonBilan(): void
    {
        [$page] = $this->chargerCsv('FILTRECSV;Jean;01/02/1790', ['Filtre' => 1, 'Compare' => 'AUTRENOM', 'Condition' => '=']);
        self::assertStringContainsString('Actes filtré  : 1', $page->corps);
        self::assertSame('0', self::$site->base->query("SELECT COUNT(*) FROM gm_nai3 WHERE NOM = 'FILTRECSV'")->fetch_row()[0]);
    }

    public function testUneCommuneLongueResteAuJournal(): void
    {
        $this->chargerCsv('JOURNALCSV;Jean;01/02/1790', []);
        $ligne = self::$site->base->query("SELECT commune FROM gm_log WHERE ACTION LIKE 'Ajout CSV%' AND commune LIKE 'Saint-Jean%'")->fetch_row();
        self::assertIsArray($ligne, 'La ligne du journal est écrite');
        self::assertSame(30, mb_strlen($ligne[0]));
    }

    public function testUneFicheDeLocaliteInconnueRepondSansAvertissement(): void
    {
        foreach (['/localite.php' => 'nouveau', '/admin/gestgeoloc.php' => 'niveau9'] as $page => $profil) {
            $reponse = self::$site->client($profil)->get($page, ['id' => 999999]);
            self::assertSame(200, $reponse->statut, $page);
            self::assertStringContainsString('</html>', $reponse->corps, $page);
        }
        $journal = is_file(self::$site->journal) ? (string) file_get_contents(self::$site->journal) : '';
        self::assertStringNotContainsString('localite.php', $journal);
        self::assertStringNotContainsString('gestgeoloc.php', $journal);
    }

    public function testLeFormulaireDeContactEnvoieSonMessage(): void
    {
        self::$site->base->query("UPDATE gm_params SET valeur = '0' WHERE param = 'AUTO_CAPTCHA'");
        $avant = self::$site->courriels();
        $page = self::$site->client('nouveau')->post('/form_contact.php', ['action' => 'submitted', 'nompre' => "Jeanne D'Arc", 'email' => 'contact@example.org',
            'objet' => 'Question sur un acte', 'txtmsg' => 'Bonjour, ceci est un essai.']);
        self::assertStringNotContainsString("n'a pas pu", html_entity_decode($page->corps, ENT_QUOTES));
        self::assertStringContainsString('Bonjour, ceci est un essai.', substr(self::$site->courriels(), strlen($avant)));
    }
}
