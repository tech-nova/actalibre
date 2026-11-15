<?php
declare(strict_types=1);

namespace ActaLibre\Tests\Geoloc;

use ActaLibre\Geoloc\Fiches;
use ActaLibre\Geoloc\Recensement;
use PHPUnit\Framework\TestCase;

final class RecensementTest extends TestCase
{
    private \mysqli $lien;

    protected function setUp(): void
    {
        $this->lien = BaseEssai::connecter($this);
        BaseEssai::creerGeoloc($this->lien, 'essai');
        foreach (Recensement::TABLES as $suffixe) {
            BaseEssai::creerActes($this->lien, 'essai' . $suffixe);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->lien)) {
            $this->lien->close();
        }
    }

    private function acte(string $suffixe, ?string $commune, ?string $depart): void
    {
        $requete = $this->lien->prepare("INSERT INTO essai$suffixe (COMMUNE, DEPART) VALUES (?, ?)");
        $requete->bind_param('ss', $commune, $depart);
        $requete->execute();
    }

    /** @return list<array{0:string,1:string,2:string}> */
    private function fiches(): array
    {
        return $this->lien->query('SELECT COMMUNE, DEPART, STATUT FROM essai_geoloc ORDER BY COMMUNE, DEPART')->fetch_all();
    }

    private function toutRecenser(int $limite): int
    {
        $recensement = new Recensement($this->lien, 'essai', new Fiches($this->lien, 'essai'));
        $etat = ['table' => 0, 'apres' => '', 'crees' => 0, 'termine' => false];
        $crees = 0;
        for ($tour = 0; !$etat['termine']; $tour++) {
            self::assertLessThan(100, $tour, 'Le recensement progresse');
            $etat = $recensement->tranche($etat['table'], $etat['apres'], $limite);
            $crees += $etat['crees'];
        }
        return $crees;
    }

    public function testToutesLesTablesSontRecenseesParTranches(): void
    {
        foreach (['Aube', 'Brume', 'Cendre', 'Dune', 'Écume'] as $commune) {
            $this->acte('_nai3', $commune, 'XA');
        }
        $this->acte('_mar3', 'Brume', 'XB');
        $this->acte('_dec3', 'Forge', null);
        $this->acte('_div3', 'Givre', 'XA');
        self::assertSame(8, $this->toutRecenser(2));
        self::assertContains(['Brume', 'XB', 'N'], $this->fiches());
        self::assertContains(['Forge', '', 'N'], $this->fiches());
        self::assertContains(['Écume', 'XA', 'N'], $this->fiches());
    }

    public function testUneCommuneAPlusieursDepartementsNEstPasCoupeeEntreDeuxTranches(): void
    {
        $this->acte('_nai3', 'Aube', 'XA');
        foreach (['XA', 'XB', 'XC'] as $depart) {
            $this->acte('_nai3', 'Brume', $depart);
        }
        $this->acte('_nai3', 'Cendre', 'XA');
        self::assertSame(5, $this->toutRecenser(1));
    }

    public function testLesFichesExistantesEtLesCommunesVidesSontRespectees(): void
    {
        $this->lien->query("INSERT INTO essai_geoloc (COMMUNE, DEPART, LON, LAT, STATUT, NOTE_N) VALUES ('Brume', 'XA', 1.5, 45.5, 'M', 'note')");
        $this->acte('_nai3', 'BRUME', 'XA');
        $this->acte('_nai3', '', 'XA');
        $this->acte('_nai3', null, 'XA');
        self::assertSame(0, $this->toutRecenser(10));
        self::assertSame([['Brume', 'XA', 'M']], $this->fiches());
    }
}
