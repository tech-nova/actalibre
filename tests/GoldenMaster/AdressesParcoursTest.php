<?php
declare(strict_types=1);

namespace ActaLibre\Tests\GoldenMaster;

use PHPUnit\Framework\TestCase;

/** Les adresses construites sont brutes : chaque lien est échappé une seule fois, dans les deux modes d'adresse. */
final class AdressesParcoursTest extends TestCase
{
    private SiteJetable $site;

    protected function setUp(): void
    {
        $hote = getenv('GM_DB_HOST');
        $nom = getenv('GM_DB_NAME');
        $utilisateur = getenv('GM_DB_USER');
        $passe = getenv('GM_DB_PASS');
        if ($hote === false || $nom === false || $utilisateur === false || $passe === false) {
            self::markTestSkipped('AdressesParcoursTest ignoré : définir GM_DB_HOST, GM_DB_NAME, GM_DB_USER et GM_DB_PASS.');
        }
        $this->site = SiteJetable::partage(['hote' => $hote, 'nom' => $nom, 'utilisateur' => $utilisateur, 'passe' => $passe]);
    }

    public function testLesLiensSontEchappesUneSeuleFoisDansLesDeuxModes(): void
    {
        foreach (['0', '1'] as $mode) {
            $this->site->base->query("UPDATE gm_params SET valeur = '$mode' WHERE param = 'FULL_URL'");
            // Chaque mode lit l'adresse sous la forme que produit mkurl().
            $commune = urlencode("L'Orméane [Val d'Ombre]");
            $page = $this->site->client('niveau9')->suivre($mode === '0' ? "/tab_naiss.php?args=$commune,BELCOUR" : "/tab_naiss.php/$commune/BELCOUR");
            self::assertSame(200, $page->statut, $mode);
            self::assertStringNotContainsString('&amp;amp;', $page->corps, $mode);
            self::assertSame(1, preg_match('/<a href="([^"]*xord=[^"]*)">/', $page->corps, $lien), $mode);
            $suivi = $this->site->client('niveau9')->suivre(html_entity_decode($lien[1], ENT_QUOTES));
            self::assertSame(200, $suivi->statut, $mode);
            self::assertStringContainsString('xord=', $lien[1], $mode);
            self::assertStringContainsString('BELCOUR', $suivi->corps, $mode);
            if ($mode === '0') {
                self::assertStringContainsString('&amp;xord=', $lien[1]);
            }
        }
    }

    public function testUneAdresseAvecParametresApresLeDossier(): void
    {
        $page = $this->site->client('niveau9')->suivre('/?origine=essai');
        self::assertSame(200, $page->statut);
        self::assertStringNotContainsString('%3Forigine', $page->corps);
        self::assertStringNotContainsString('%3Dessai', $page->corps);
    }

    public function testAucuneCaptureNEstEchappeeDeuxFois(): void
    {
        foreach (glob(__DIR__ . '/captures/*') ?: [] as $capture) {
            self::assertStringNotContainsString('&amp;amp;', (string) file_get_contents($capture), basename($capture));
        }
    }
}
