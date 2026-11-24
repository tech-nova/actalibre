<?php
declare(strict_types=1);

namespace ActaLibre\Tests\GoldenMaster;

use PHPUnit\Framework\TestCase;

/** Les pages n'appellent aucun service tiers avant le consentement du visiteur. */
final class CarteParcoursTest extends TestCase
{
    private SiteJetable $site;

    protected function setUp(): void
    {
        $hote = getenv('GM_DB_HOST');
        $nom = getenv('GM_DB_NAME');
        $utilisateur = getenv('GM_DB_USER');
        $passe = getenv('GM_DB_PASS');
        if ($hote === false || $nom === false || $utilisateur === false || $passe === false) {
            self::markTestSkipped('CarteParcoursTest ignoré : définir GM_DB_HOST, GM_DB_NAME, GM_DB_USER et GM_DB_PASS.');
        }
        $this->site = SiteJetable::partage(['hote' => $hote, 'nom' => $nom, 'utilisateur' => $utilisateur, 'passe' => $passe]);
    }

    protected function tearDown(): void
    {
        if (isset($this->site)) {
            $this->site->reinitialiser();
        }
    }

    private function parametre(string $nom, string $valeur): void
    {
        $this->site->base->query("UPDATE gm_params SET valeur = '" . $this->site->base->real_escape_string($valeur) . "' WHERE param = '$nom'");
    }

    private function assertSansServiceTiers(string $corps): void
    {
        self::assertSame(0, preg_match('~<(?:script|link|img|iframe)\b[^>]*(?:src|href)="(?:https?:)?//(?!127\.0\.0\.1)~i', $corps), 'Aucune ressource tierce');
        self::assertStringNotContainsStringIgnoringCase('google', $corps);
        self::assertStringNotContainsString('cookieconsent', $corps);
    }

    public function testLaCarteDAccueilAttendLeConsentement(): void
    {
        $this->parametre('GEO_MODE_PUBLIC', '5');
        $corps = $this->site->client('nouveau')->get('/index.php')->corps;
        $this->assertSansServiceTiers($corps);
        self::assertStringContainsString('id="carte-autoriser"', $corps);
        self::assertStringContainsString('tools/leaflet/leaflet.markercluster.js', $corps);
        self::assertSame(1, preg_match('~id="carte-donnees">(.*?)</script>~s', $corps, $bloc));
        self::assertNotSame([], json_decode($bloc[1], true)['marqueurs'], 'Les communes géolocalisées sont sur la carte');
    }

    public function testLeContenuDeLaPageDAccueilSEnregistre(): void
    {
        $this->parametre('GEO_MODE_PUBLIC', '2');
        $page = $this->site->client('niveau9')->post('/admin/gest_params.php', [
            'parname1' => 'GEO_MODE_PUBLIC', 'parvalue1' => '1', 'parnbr' => '6', 'grp' => 'Géoloc', 'xconfirm' => 'confirmed',
        ]);
        self::assertStringContainsString('Sauvegarde : 1 paramètre(s) modifié(s).', $page->corps);
        self::assertSame('1', $this->site->base->query("SELECT valeur FROM gm_params WHERE param = 'GEO_MODE_PUBLIC'")->fetch_row()[0]);
        self::assertStringContainsString('id="carte-donnees"', $this->site->client('nouveau')->get('/index.php')->corps);
    }

    public function testLaFicheDUneLocaliteAfficheSaCarte(): void
    {
        $corps = $this->site->client('nouveau')->get('/localite.php', ['id' => 3])->corps;
        $this->assertSansServiceTiers($corps);
        self::assertStringContainsString('id="carte-donnees"', $corps);
    }

    public function testLaFicheDAdministrationAUnMarqueurDeplacable(): void
    {
        $corps = $this->site->client('niveau9')->get('/admin/gestgeoloc.php', ['id' => 1])->corps;
        $this->assertSansServiceTiers($corps);
        self::assertSame(1, preg_match('~id="carte-donnees">(.*?)</script>~s', $corps, $bloc));
        self::assertTrue(json_decode($bloc[1], true)['deplacable']);
    }

    public function testUnePageSansIdentifiantNeSArretePasSurLaCarte(): void
    {
        foreach (['/localite.php' => 'nouveau', '/admin/gestgeoloc.php' => 'niveau9'] as $page => $profil) {
            $reponse = $this->site->client($profil)->get($page);
            self::assertSame(200, $reponse->statut, $page);
            self::assertStringContainsString('</html>', $reponse->corps, $page);
            self::assertStringNotContainsString('id="carte-donnees"', $reponse->corps, $page);
        }
        self::assertStringNotContainsString('Fatal error', (string) @file_get_contents($this->site->journal));
    }

    public function testLeBandeauEstSurLesPagesAvecSesReglages(): void
    {
        $this->parametre('COOKIE_STYLE', '3');
        $this->parametre('COOKIE_URL_INFO', 'https://exemple.org/infos?a=1&b=2');
        $corps = $this->site->client('nouveau')->get('/index.php', ['vue' => 'T'])->corps;
        $this->assertSansServiceTiers($corps);
        self::assertStringContainsString('tools/tarteaucitron/tarteaucitron.min.js', $corps);
        self::assertSame(1, preg_match('~id="consentement-reglages">(.*?)</script>~s', $corps, $bloc));
        $reglages = json_decode($bloc[1], true);
        self::assertSame('bottom', $reglages['position']);
        self::assertSame('https://exemple.org/infos?a=1&b=2', $reglages['adresse']);
    }

    public function testLeRecalculNeCreePasDeFichePourUneCommuneSansActe(): void
    {
        $this->site->base->query("DELETE FROM gm_geoloc WHERE COMMUNE = 'FictifGeo'");
        $this->site->base->query("INSERT INTO gm_sums (COMMUNE, DEPART, TYPACT, LIBELLE, DEPOSANT) VALUES ('FictifGeo', 'XA', 'N', '', 19)");
        $page = $this->site->client('niveau9')->get('/admin/maj_sums.php', ['xtyp' => 'N', 'mode' => 'A', 'com' => 'FictifGeo [XA]', 'jeton' => Client::JETON]);
        self::assertSame(200, $page->statut);
        self::assertStringNotContainsString('géocod', $page->corps);
        self::assertSame(0, $this->site->base->query("SELECT 1 FROM gm_geoloc WHERE COMMUNE = 'FictifGeo'")->num_rows);
    }

    public function testLeRecalculCreeLesLocalitesSansCoordonneesEtLeSignale(): void
    {
        $this->site->base->query('DELETE FROM gm_geoloc');
        $page = $this->site->client('niveau9')->get('/admin/maj_sums.php', ['xtyp' => 'N', 'mode' => 'A', 'jeton' => Client::JETON]);

        self::assertSame(200, $page->statut);
        self::assertStringNotContainsString('Le service de géocodage ne répond pas', $page->corps);
        self::assertStringContainsString('Mise à jour globale des statistiques', $page->corps);
        $total = (int) $this->site->base->query('SELECT COUNT(*) FROM gm_geoloc')->fetch_row()[0];
        self::assertGreaterThan(0, $total);
        self::assertSame($total, (int) $this->site->base->query("SELECT COUNT(*) FROM gm_geoloc WHERE STATUT = 'N' AND LON = 0 AND LAT = 0")->fetch_row()[0]);
        self::assertStringContainsString("n'ont pas de coordonnées géographiques", $page->corps);
        self::assertStringContainsString('Géolocaliser les localités</a>', $page->corps);
    }

    public function testLeMessageNOffrePasLeLienSousLeNiveauNeuf(): void
    {
        $this->site->base->query('DELETE FROM gm_geoloc');
        $page = $this->site->client('niveau6')->get('/admin/maj_sums.php', ['xtyp' => 'N', 'mode' => 'A', 'jeton' => Client::JETON]);
        self::assertStringContainsString("n'ont pas de coordonnées géographiques", $page->corps);
        self::assertStringNotContainsString('Géolocaliser les localités</a>', $page->corps);
    }

    public function testUneCommuneSansDepartementSAfficheSansCrochets(): void
    {
        $this->site->base->query("INSERT INTO gm_sums (COMMUNE, DEPART, TYPACT, LIBELLE, DEPOSANT) VALUES ('FictifSansDep', '', 'N', '', 19)");
        $this->site->base->query("INSERT INTO gm_geoloc (COMMUNE, DEPART, LON, LAT, STATUT) VALUES ('FictifSansDep', '', 2.5, 46.5, 'A')");
        $id = (int) $this->site->base->query("SELECT ID FROM gm_geoloc WHERE COMMUNE = 'FictifSansDep'")->fetch_row()[0];
        $client = $this->site->client('niveau9');

        $carte = $client->get('/index.php', ['vue' => 'C'])->corps;
        self::assertStringContainsString('FictifSansDep', $carte);
        self::assertStringNotContainsString('FictifSansDep []', $carte);

        $fiche = $client->get('/localite.php', ['id' => $id])->corps;
        self::assertStringContainsString('<h2>Commune/Paroisse : FictifSansDep</h2>', $fiche);

        $this->site->base->query("DELETE FROM gm_geoloc WHERE ID = $id");
    }

    public function testUneCommuneAvecDepartementGardeSesCrochets(): void
    {
        $this->site->base->query("INSERT INTO gm_sums (COMMUNE, DEPART, TYPACT, LIBELLE, DEPOSANT) VALUES ('FictifAvecDep', 'XA', 'N', '', 19)");
        $this->site->base->query("INSERT INTO gm_geoloc (COMMUNE, DEPART, LON, LAT, STATUT) VALUES ('FictifAvecDep', 'XA', 2.5, 46.5, 'A')");

        self::assertStringContainsString('FictifAvecDep [XA]', $this->site->client('niveau9')->get('/index.php', ['vue' => 'C'])->corps);
    }

    public function testLePiedDePageNommeActaLibre(): void
    {
        self::assertStringContainsString('>Actalibre</p>', $this->site->client('nouveau')->get('/index.php')->corps);
    }
}
