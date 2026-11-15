<?php
declare(strict_types=1);

namespace ActaLibre\Tests\Geoloc;

use ActaLibre\Geoloc\Cache;
use PHPUnit\Framework\TestCase;

final class CacheTest extends TestCase
{
    private DossierEssai $essai;

    protected function setUp(): void
    {
        $this->essai = new DossierEssai();
    }

    protected function tearDown(): void
    {
        $this->essai->supprimer();
    }

    private function cache(string $fournisseur = 'https://exemple.org/search'): Cache
    {
        return new Cache($this->essai->stockage(), $this->essai->horloge(), $fournisseur);
    }

    public function testUnResultatTrouveEstGardeSeptJours(): void
    {
        $this->cache()->ecrire("L'Isle & Fils, XA", ['lon' => 2.3522, 'lat' => 48.8566]);
        self::assertSame(['trouve' => true, 'lon' => 2.3522, 'lat' => 48.8566], $this->cache()->lire("L'Isle & Fils, XA"));
        self::assertNull($this->cache()->lire("L'Isle & Fils, XB"));
        self::assertNull($this->cache('https://autre.example/search')->lire("L'Isle & Fils, XA"), 'Le fournisseur fait partie de la clé');
        $this->essai->temps += Cache::DUREE_TROUVE + 1;
        self::assertNull($this->cache()->lire("L'Isle & Fils, XA"));
    }

    public function testUneAbsenceEstGardeeVingtQuatreHeures(): void
    {
        $this->cache()->ecrire('Vauclairmont', null);
        self::assertSame(['trouve' => false, 'lon' => 0.0, 'lat' => 0.0], $this->cache()->lire('Vauclairmont'));
        $this->essai->temps += Cache::DUREE_ABSENT + 1;
        self::assertNull($this->cache()->lire('Vauclairmont'));
    }

    public function testUneCoordonneeEntiereResteUnNombreDecimal(): void
    {
        $this->cache()->ecrire('Origine', ['lon' => 0.0, 'lat' => 2.0]);
        self::assertSame(['trouve' => true, 'lon' => 0.0, 'lat' => 2.0], $this->cache()->lire('Origine'));
    }
}
