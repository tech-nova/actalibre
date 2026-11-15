<?php
declare(strict_types=1);

namespace ActaLibre\Tests\Geoloc;

use ActaLibre\Geoloc\Vue;
use PHPUnit\Framework\TestCase;

final class VueTest extends TestCase
{
    public function testLaFenetreAnnonceSonTitreEtSaPortee(): void
    {
        $html = Vue::dialogue('/site "x"/admin/geoloc_travail.php');
        self::assertStringContainsString('<dialog id="geoloc-dialogue" aria-labelledby="geoloc-titre" aria-describedby="geoloc-avertissement"', $html);
        self::assertStringContainsString('data-api="/site &quot;x&quot;/admin/geoloc_travail.php"', $html);
        self::assertStringContainsString('>Mise à jour des coordonnées géographiques</h2>', $html);
        foreach (['Trouvées', 'Introuvables', 'Manuelles conservées', 'À vérifier', 'Mettre en pause', 'Reprendre', 'Fermer', 'OpenStreetMap', 'Nominatim'] as $texte) {
            self::assertStringContainsString($texte, $html);
        }
        self::assertStringContainsString('Fermer cette fenêtre suspend la mise à jour', $html);
        self::assertStringContainsString('id="geoloc-etat" role="status"', $html);
        self::assertStringContainsString('<span class="geoloc-roue" aria-hidden="true"></span>', $html);
    }

    public function testLEnteteChargeLesFichiersLocaux(): void
    {
        $html = Vue::entete('/site');
        self::assertStringContainsString('href="/site/tools/css/geoloc.css"', $html);
        self::assertStringContainsString('src="/site/tools/js/geoloc.js" defer', $html);
    }
}
