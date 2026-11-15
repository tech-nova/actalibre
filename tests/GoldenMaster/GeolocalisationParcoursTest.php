<?php
declare(strict_types=1);

namespace ActaLibre\Tests\GoldenMaster;

use PHPUnit\Framework\TestCase;

/** Géolocalisation explicite des localités : recensement, travaux, fiche individuelle. */
final class GeolocalisationParcoursTest extends TestCase
{
    private SiteJetable $site;

    protected function setUp(): void
    {
        $hote = getenv('GM_DB_HOST');
        $nom = getenv('GM_DB_NAME');
        $utilisateur = getenv('GM_DB_USER');
        $passe = getenv('GM_DB_PASS');
        if ($hote === false || $nom === false || $utilisateur === false || $passe === false) {
            self::markTestSkipped('GeolocalisationParcoursTest ignoré : définir GM_DB_HOST, GM_DB_NAME, GM_DB_USER et GM_DB_PASS.');
        }
        $this->site = SiteJetable::partage(['hote' => $hote, 'nom' => $nom, 'utilisateur' => $utilisateur, 'passe' => $passe]);
    }

    protected function tearDown(): void
    {
        if (isset($this->site)) {
            $this->site->reinitialiser();
        }
    }

    private function nombre(string $requete): int
    {
        return (int) $this->site->base->query($requete)->fetch_row()[0];
    }

    public function testLeRecensementRattrapeLesLocalitesDesActes(): void
    {
        $attendu = $this->nombre("SELECT COUNT(*) FROM (SELECT COMMUNE, IFNULL(DEPART, '') FROM gm_nai3 WHERE COMMUNE <> '' UNION SELECT COMMUNE, IFNULL(DEPART, '') FROM gm_mar3 WHERE COMMUNE <> '' UNION SELECT COMMUNE, IFNULL(DEPART, '') FROM gm_dec3 WHERE COMMUNE <> '' UNION SELECT COMMUNE, IFNULL(DEPART, '') FROM gm_div3 WHERE COMMUNE <> '') AS lieux");
        $this->site->base->query('DELETE FROM gm_geoloc');
        $client = $this->site->client('niveau9');

        $page = $client->post('/admin/listgeolocs.php', ['action' => 'recenser']);
        for ($tour = 0; preg_match('~name="recensement_table" value="(\d+)".*?name="recensement_apres" value="([^"]*)"~s', $page->corps, $suite) === 1; $tour++) {
            self::assertLessThan(50, $tour);
            $page = $client->post('/admin/listgeolocs.php', ['action' => 'recenser', 'recensement_table' => $suite[1], 'recensement_apres' => html_entity_decode($suite[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')]);
        }

        self::assertSame(200, $page->statut);
        self::assertStringContainsString('Recensement terminé', $page->corps);
        self::assertSame($attendu, $this->nombre('SELECT COUNT(*) FROM gm_geoloc'));
        self::assertSame($attendu, $this->nombre("SELECT COUNT(*) FROM gm_geoloc WHERE STATUT = 'N'"));
    }

    public function testLeRecensementExigeLeJeton(): void
    {
        $this->site->base->query('DELETE FROM gm_geoloc');
        $page = $this->site->client('niveau9')->post('/admin/listgeolocs.php', ['action' => 'recenser', 'jeton' => 'faux']);
        self::assertSame(403, $page->statut);
        self::assertSame(0, $this->nombre('SELECT COUNT(*) FROM gm_geoloc'));
    }

    private string $journal = '';

    private function fiche(string $commune, string $statut, float $lon = 0.0, float $lat = 0.0): int
    {
        $requete = $this->site->base->prepare('INSERT INTO gm_geoloc (COMMUNE, DEPART, LON, LAT, STATUT) VALUES (?, ?, ?, ?, ?)');
        $depart = 'XG';
        $requete->bind_param('ssdds', $commune, $depart, $lon, $lat, $statut);
        $requete->execute();
        return (int) $this->site->base->insert_id;
    }

    /** @return array{0:string,1:string,2:string} statut, longitude, latitude */
    private function etat(int $id): array
    {
        return $this->site->base->query("SELECT STATUT, CAST(ROUND(LON, 4) AS CHAR), CAST(ROUND(LAT, 4) AS CHAR) FROM gm_geoloc WHERE ID = $id")->fetch_row();
    }

    /** @return array<string,mixed> */
    private function json(Reponse $reponse, int $statut = 200): array
    {
        self::assertSame($statut, $reponse->statut, $reponse->corps);
        self::assertStringContainsStringIgnoringCase('content-type: application/json', $reponse->entetes);
        $donnees = json_decode($reponse->corps, true);
        self::assertIsArray($donnees, $reponse->corps);
        return $donnees;
    }

    /** @param list<int> $ids @return array<string,mixed> */
    private function creer(Client $client, array $ids): array
    {
        $parametres = ['action' => 'creer'];
        foreach ($ids as $rang => $id) {
            $parametres['ids[' . $rang . ']'] = $id;
        }
        return $this->json($client->post('/admin/geoloc_travail.php', $parametres));
    }

    /** @return array<string,mixed> */
    private function terminer(Client $client, string $travail): array
    {
        for ($tour = 0; $tour < 20; $tour++) {
            $vue = $this->json($client->post('/admin/geoloc_travail.php', ['action' => 'avancer', 'travail' => $travail]));
            if (!in_array($vue['etat'], ['pret', 'actif'], true)) {
                return $vue;
            }
        }
        self::fail('Le travail ne se termine pas');
    }

    /** @return list<array{0:float,1:string}> */
    private function appels(): array
    {
        $lignes = file($this->journal, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        return array_map(static function (string $ligne): array {
            [$temps, $requete] = explode("\t", $ligne, 2);
            return [(float) $temps, $requete];
        }, $lignes);
    }

    public function testUnTravailAppliqueLesReglesSansDepasserLeDebit(): void
    {
        $this->journal = $this->site->nominatimSimule();
        $nouvelle = $this->fiche('GeoNouvelle', 'N');
        $introuvable = $this->fiche('GeoIntrouvable', 'N');
        $manuelle = $this->fiche('GeoManuelle', 'M', 1.5, 45.5);
        $auto = $this->fiche('GeoAuto', 'A', 1.0, 1.0);
        $client = $this->site->client('niveau9');

        $vue = $this->terminer($client, $this->creer($client, [$nouvelle, $introuvable, $manuelle, $auto])['travail']);

        self::assertSame('termine', $vue['etat']);
        self::assertSame(['trouvee' => 2, 'introuvable' => 1, 'manuelle' => 1, 'a_verifier' => 0], $vue['compteurs']);
        self::assertSame(['A', '2.3522', '48.8566'], $this->etat($nouvelle));
        self::assertSame('I', $this->etat($introuvable)[0]);
        self::assertSame(['M', '1.5', '45.5'], $this->etat($manuelle));
        self::assertSame(['A', '2.3522', '48.8566'], $this->etat($auto));
        $appels = $this->appels();
        self::assertSame(['GeoNouvelle, XG', 'GeoIntrouvable, XG', 'GeoAuto, XG'], array_column($appels, 1));
        for ($i = 1; $i < count($appels); $i++) {
            self::assertGreaterThanOrEqual(0.95, $appels[$i][0] - $appels[$i - 1][0], 'Une requête par seconde au plus');
        }
    }

    public function testUnNouveauTravailReutiliseLesReponsesRecentes(): void
    {
        $this->journal = $this->site->nominatimSimule();
        $id = $this->fiche('GeoNouvelle', 'N');
        $client = $this->site->client('niveau9');
        $this->terminer($client, $this->creer($client, [$id])['travail']);
        $this->site->base->query("UPDATE gm_geoloc SET STATUT = 'N', LON = 0, LAT = 0 WHERE ID = $id");

        $vue = $this->terminer($client, $this->creer($client, [$id])['travail']);

        self::assertTrue($vue['dernier']['cache']);
        self::assertCount(1, $this->appels());
    }

    public function testUnRefusDeDebitSuspendSansRienModifier(): void
    {
        $this->journal = $this->site->nominatimSimule();
        $id = $this->fiche('GeoSaturee', 'N');
        $client = $this->site->client('niveau9');
        $travail = $this->creer($client, [$id])['travail'];

        $vue = $this->json($client->post('/admin/geoloc_travail.php', ['action' => 'avancer', 'travail' => $travail]));
        self::assertSame(['suspendu', 'debit'], [$vue['etat'], $vue['cause']]);
        self::assertSame(['N', '0', '0'], $this->etat($id));

        $this->json($client->post('/admin/geoloc_travail.php', ['action' => 'reprendre', 'travail' => $travail]));
        $vue = $this->json($client->post('/admin/geoloc_travail.php', ['action' => 'avancer', 'travail' => $travail]));
        self::assertSame('suspendu', $vue['etat']);
        self::assertCount(1, $this->appels(), 'Aucune relance pendant la pause demandée');
    }

    public function testSansSessionLaReponseEstUnJsonIdentifiable(): void
    {
        $donnees = $this->json($this->site->client('nouveau')->get('/admin/geoloc_travail.php', ['action' => 'actif']), 401);
        self::assertSame('session', $donnees['erreur']);
    }

    public function testLesActionsNonAutoriseesSontRefuseesSansAppel(): void
    {
        $this->journal = $this->site->nominatimSimule();
        $id = $this->fiche('GeoNouvelle', 'N');
        $admin = $this->site->client('niveau9');

        self::assertSame('droits', $this->json($this->site->client('niveau6')->post('/admin/geoloc_travail.php', ['action' => 'creer', 'ids[0]' => $id]), 403)['erreur']);
        self::assertSame('jeton', $this->json($admin->post('/admin/geoloc_travail.php', ['action' => 'creer', 'ids[0]' => $id, 'jeton' => 'faux']), 403)['erreur']);
        self::assertSame('methode', $this->json($admin->get('/admin/geoloc_travail.php', ['action' => 'avancer', 'travail' => str_repeat('a', 32)]), 405)['erreur']);
        self::assertSame('requete', $this->json($admin->post('/admin/geoloc_travail.php', ['action' => 'creer', 'ids[0]' => 'abc']), 400)['erreur']);
        $travail = $this->creer($admin, [$id])['travail'];
        self::assertSame('travail', $this->json($this->site->client('temoin')->post('/admin/geoloc_travail.php', ['action' => 'avancer', 'travail' => $travail]), 404)['erreur']);
        self::assertSame([], $this->appels());
    }

    public function testDepuisUneFicheManuelleRienNEstEcritSansValidation(): void
    {
        $this->journal = $this->site->nominatimSimule();
        $id = $this->fiche('GeoManuelle', 'M', 1.5, 45.5);
        $client = $this->site->client('niveau9');

        $reponse = $this->json($client->post('/admin/geoloc_travail.php', ['action' => 'rechercher', 'id' => $id]));
        self::assertSame('proposition', $reponse['issue']);
        self::assertSame(['M', '1.5', '45.5'], $this->etat($id));

        $version = ['version_statut' => $reponse['fiche']['statut'], 'version_lon' => $reponse['fiche']['lon'], 'version_lat' => $reponse['fiche']['lat']];
        $refus = $this->json($client->post('/admin/geoloc_travail.php', ['action' => 'remplacer', 'id' => $id, 'lon' => '9', 'lat' => '9'] + $version), 400);
        self::assertSame('requete', $refus['erreur']);
        self::assertSame(['M', '1.5', '45.5'], $this->etat($id));

        $succes = $this->json($client->post('/admin/geoloc_travail.php', ['action' => 'remplacer', 'id' => $id, 'lon' => (string) $reponse['proposition']['lon'], 'lat' => (string) $reponse['proposition']['lat']] + $version));
        self::assertSame('trouvee', $succes['issue']);
        self::assertSame(['A', '2.3522', '48.8566'], $this->etat($id));
        self::assertCount(1, $this->appels());
    }

    public function testDepuisUneFicheNonDefinieLeResultatEstEnregistre(): void
    {
        $this->journal = $this->site->nominatimSimule();
        $id = $this->fiche('GeoNouvelle', 'N');
        $reponse = $this->json($this->site->client('niveau9')->post('/admin/geoloc_travail.php', ['action' => 'rechercher', 'id' => $id]));
        self::assertSame('trouvee', $reponse['issue']);
        self::assertSame('A', $reponse['fiche']['statut']);
        self::assertSame(['A', '2.3522', '48.8566'], $this->etat($id));
    }

    public function testUnePanneDuServiceEstSignaleeSansRienModifier(): void
    {
        $id = $this->fiche('GeoNouvelle', 'N');
        $donnees = $this->json($this->site->client('niveau9')->post('/admin/geoloc_travail.php', ['action' => 'rechercher', 'id' => $id]), 503);
        self::assertSame('panne', $donnees['erreur']);
        self::assertSame(['N', '0', '0'], $this->etat($id));
    }

    public function testLaListeProposeLaSelectionDesLignesAffichees(): void
    {
        $introuvable = $this->fiche('GeoIntrouvable', 'I');
        $corps = $this->site->client('niveau9')->get('/admin/listgeolocs.php', ['xord' => 'S'])->corps;

        foreach (['id="geoloc-tout" aria-label="Tout sélectionner sur cette page"', '>Tout sélectionner</button>', '>Sélectionner les communes non géolocalisées</button>',
            'Coordonnées géographiques des éléments sélectionnés : <button type="button" id="geoloc-maj" disabled', '>Mettre à jour</button>',
            'La mise à jour groupée des coordonnées nécessite JavaScript', 'tools/js/geoloc.js', 'id="geoloc-dialogue"', '<form id="geoloc-selection" autocomplete="off">'] as $attendu) {
            self::assertStringContainsString($attendu, $corps);
        }
        self::assertStringContainsString('<tr class="row0" data-id="' . $introuvable . '" data-statut="I">', str_replace('row1', 'row0', $corps));
        self::assertStringContainsString('aria-label="Sélectionner GeoIntrouvable, XG"', $corps);
        $cases = substr_count($corps, 'name="ids[]"');
        self::assertSame(substr_count($corps, ' data-id="'), $cases);
        self::assertGreaterThan(0, $cases);
        self::assertLessThanOrEqual(100, $cases, 'Seules les lignes de la page affichée sont sélectionnables');
        self::assertStringContainsString('>Introuvable</td>', $corps);
    }

    /** @return array<string,string> champs version_* du formulaire de la fiche */
    private function version(Client $client, int $id): array
    {
        $corps = $client->get('/admin/gestgeoloc.php', ['id' => $id])->corps;
        $version = [];
        foreach (['version_statut', 'version_lon', 'version_lat'] as $champ) {
            self::assertSame(1, preg_match('~name="' . $champ . '" id="' . $champ . '" value="([^"]*)"~', $corps, $m), $champ);
            $version[$champ] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return $version;
    }

    /** @param array<string,string> $version */
    private function enregistrer(Client $client, int $id, string $lon, string $lat, array $version): string
    {
        return $client->post('/admin/gestgeoloc.php', ['id' => $id, 'action' => 'submitted', 'lon' => $lon, 'lat' => $lat,
            'noteN' => 'note n', 'noteM' => '', 'noteD' => '', 'noteV' => '', 'commune' => 'x'] + $version)->corps;
    }

    public function testLaFicheProposeLaRechercheNominatim(): void
    {
        $corps = $this->site->client('niveau9')->get('/admin/gestgeoloc.php', ['id' => $this->fiche('GeoNouvelle', 'N')])->corps;
        self::assertStringContainsString('>Rechercher les coordonnées avec Nominatim</button>', $corps);
        self::assertStringContainsString('id="geoloc-dialogue"', $corps);
        self::assertStringContainsString('tools/js/geoloc.js', $corps);
        self::assertStringContainsString('<td id="geoloc-fiche-statut">Non définie</td>', $corps);
    }

    public function testUnEnregistrementApresUnGeocodageEstRefuse(): void
    {
        $id = $this->fiche('GeoAuto', 'A', 1.5, 45.5);
        $client = $this->site->client('niveau9');
        $version = $this->version($client, $id);
        $this->site->base->query("UPDATE gm_geoloc SET LON = 2.3522, LAT = 48.8566 WHERE ID = $id");

        $corps = $this->enregistrer($client, $id, '1.5', '45.5', $version);

        self::assertStringContainsString('a été modifiée depuis son ouverture', $corps);
        self::assertSame(['A', '2.3522', '48.8566'], $this->etat($id));
    }

    public function testUnDeplacementManuelEnregistreLeStatutManuel(): void
    {
        $id = $this->fiche('GeoAuto', 'A', 1.5, 45.5);
        $client = $this->site->client('niveau9');
        $corps = $this->enregistrer($client, $id, '1.75', '45.25', $this->version($client, $id));
        self::assertStringContainsString('Fiche enregistrée', $corps);
        self::assertSame(['M', '1.75', '45.25'], $this->etat($id));
    }

    public function testUnEnregistrementDesSeulesNotesGardeLeStatut(): void
    {
        $id = $this->fiche('GeoAuto', 'A', 1.5, 45.5);
        $client = $this->site->client('niveau9');
        $version = $this->version($client, $id);
        $this->enregistrer($client, $id, $version['version_lon'], $version['version_lat'], $version);
        self::assertSame(['A', '1.5', '45.5'], $this->etat($id));
        self::assertSame('note n', $this->site->base->query("SELECT NOTE_N FROM gm_geoloc WHERE ID = $id")->fetch_row()[0]);
    }

    public function testDesCoordonneesHorsBornesSontRefusees(): void
    {
        $id = $this->fiche('GeoAuto', 'A', 1.5, 45.5);
        $client = $this->site->client('niveau9');
        $corps = $this->enregistrer($client, $id, '200', '45.5', $this->version($client, $id));
        self::assertStringContainsString('Coordonnées invalides', $corps);
        self::assertSame(['A', '1.5', '45.5'], $this->etat($id));
    }
}
