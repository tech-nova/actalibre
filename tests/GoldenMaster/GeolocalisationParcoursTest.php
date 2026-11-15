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
}
