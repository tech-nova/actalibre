<?php
declare(strict_types=1);

namespace ActaLibre\Tests\Carte;

use ActaLibre\Carte\Carte;
use PHPUnit\Framework\TestCase;

final class CarteTest extends TestCase
{
    /** @return array<string,mixed> */
    private function donnees(Carte $carte): array
    {
        self::assertSame(1, preg_match('~<script type="application/json" id="carte-donnees">(.*)</script>~s', $carte->afficher(), $bloc));
        self::assertStringNotContainsString('<', $bloc[1], 'Le bloc JSON ne peut pas fermer sa balise');
        return json_decode($bloc[1], true, 512, JSON_THROW_ON_ERROR);
    }

    public function testUneValeurSpecialeResteInerte(): void
    {
        $carte = new Carte('100%', 400);
        $carte->marqueur(2.5, 48.5, "D'Arc & <Fils>", '<b>D&#039;Arc</b>', 'https://exemple.org/pin_N.png');

        $marqueur = $this->donnees($carte)['marqueurs'][0];

        self::assertSame("D'Arc & <Fils>", $marqueur['titre']);
        self::assertSame('<b>D&#039;Arc</b>', $marqueur['bulle']);
        self::assertSame(['lon' => 2.5, 'lat' => 48.5], ['lon' => $marqueur['lon'], 'lat' => $marqueur['lat']]);
    }

    public function testLesReglagesSontTransmis(): void
    {
        $carte = new Carte('500', 300);
        $carte->icone('/pin.png', 10, 35);
        $carte->zoom(11);
        $carte->centre(5.0, 50.0);
        $carte->regrouper(10);
        $carte->marqueurDeplacable();

        $donnees = $this->donnees($carte);

        self::assertSame(['adresse' => '/pin.png', 'x' => 10, 'y' => 35], $donnees['icone']);
        self::assertSame(11, $donnees['zoom']);
        self::assertEqualsWithDelta(50.0, $donnees['centre']['lat'], 0.001);
        self::assertSame(10, $donnees['degroupage']);
        self::assertTrue($donnees['deplacable']);
        self::assertStringContainsString('width:500px;height:300px', $carte->afficher());
    }

    public function testLeRegroupementNeChargeSonModuleQueSiBesoin(): void
    {
        $carte = new Carte('100%', 400);
        self::assertStringNotContainsString('markercluster', $carte->enTete('/site'));
        $carte->regrouper(10);
        self::assertStringContainsString('/site/tools/leaflet/leaflet.markercluster.js', $carte->enTete('/site'));
    }

    public function testRienNeSolliciteUnServiceTiersAvantLeConsentement(): void
    {
        $carte = new Carte('100%', 400);
        $carte->marqueur(1.0, 2.0);
        $sortie = $carte->enTete('') . $carte->afficher();
        self::assertStringNotContainsString('https://', $sortie);
        self::assertStringNotContainsString('openstreetmap.org', $sortie);
        self::assertStringNotContainsString('class="openstreetmap"', $sortie);
    }

    public function testLesTuilesIndiquentLeSite(): void
    {
        // Les serveurs de tuiles refusent les demandes sans Referer, que la politique des pages supprime.
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/tools/js/carte.js');

        self::assertStringContainsString("referrerPolicy: 'strict-origin-when-cross-origin'", $script);
    }
}
