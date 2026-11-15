<?php
declare(strict_types=1);

namespace ActaLibre\Tests\Geoloc;

use ActaLibre\Geoloc\Fiches;
use PHPUnit\Framework\TestCase;

final class FichesTest extends TestCase
{
    private \mysqli $lien;
    private Fiches $fiches;

    protected function setUp(): void
    {
        $this->lien = BaseEssai::connecter($this);
        BaseEssai::creerGeoloc($this->lien, 'essai');
        $this->fiches = new Fiches($this->lien, 'essai');
    }

    protected function tearDown(): void
    {
        if (isset($this->lien)) {
            $this->lien->close();
        }
    }

    private function inserer(string $commune, string $depart, ?float $lon, ?float $lat, string $statut): int
    {
        $requete = $this->lien->prepare('INSERT INTO essai_geoloc (COMMUNE, DEPART, LON, LAT, STATUT, NOTE_N) VALUES (?, ?, ?, ?, ?, ?)');
        $note = 'note ' . $commune;
        $requete->bind_param('ssddss', $commune, $depart, $lon, $lat, $statut, $note);
        $requete->execute();
        return (int) $this->lien->insert_id;
    }

    public function testLireRendLeTexteBrutDesCoordonnees(): void
    {
        $id = $this->inserer("L'Isle-sur-Brume", 'XA', 2.3522, 48.8566, 'A');
        $fiche = $this->fiches->lire($id);
        self::assertSame($id, $fiche['id']);
        self::assertSame("L'Isle-sur-Brume", $fiche['commune']);
        self::assertSame('A', $fiche['statut']);
        self::assertIsString($fiche['lon']);
        self::assertEqualsWithDelta(2.3522, (float) $fiche['lon'], 1e-4);
        self::assertNull($this->fiches->lire($id + 1000));
    }

    public function testCreerManquanteNAjouteQuUneFicheParLieu(): void
    {
        self::assertTrue($this->fiches->creerManquante('Saint-Jean', 'XA'));
        self::assertFalse($this->fiches->creerManquante('SAINT-JEAN', 'XA'), 'La clé unique ignore la casse');
        self::assertFalse($this->fiches->creerManquante('', 'XA'));
        self::assertFalse($this->fiches->creerManquante(null, 'XA'));
        self::assertTrue($this->fiches->creerManquante('Sansdep', null));
        $lignes = $this->lien->query("SELECT COMMUNE, DEPART, STATUT, LON, LAT FROM essai_geoloc ORDER BY COMMUNE")->fetch_all();
        self::assertSame([['Saint-Jean', 'XA', 'N', '0', '0'], ['Sansdep', '', 'N', '0', '0']], $lignes);
    }

    public function testCreerManquanteNeModifiePasUneFicheExistante(): void
    {
        $id = $this->inserer('Vauclair', 'XA', 1.5, 45.5, 'M');
        self::assertFalse($this->fiches->creerManquante('Vauclair', 'XA'));
        self::assertSame('M', $this->fiches->lire($id)['statut']);
    }

    public function testRemplacerSiInchangeeEcritLesCoordonnees(): void
    {
        $id = $this->inserer('Brume', 'XA', 0.0, 0.0, 'N');
        $lue = $this->fiches->lire($id);
        self::assertTrue($this->fiches->remplacerSiInchangee($lue, 'A', 2.3522, 48.8566));
        $apres = $this->fiches->lire($id);
        self::assertSame('A', $apres['statut']);
        self::assertEqualsWithDelta(48.8566, (float) $apres['lat'], 1e-4);
        self::assertSame('note Brume', $this->lien->query("SELECT NOTE_N FROM essai_geoloc WHERE ID = $id")->fetch_row()[0], 'Les notes sont conservées');
    }

    public function testRemplacerSiInchangeeEstIdempotent(): void
    {
        $id = $this->inserer('Brume', 'XA', 0.0, 0.0, 'N');
        $lue = $this->fiches->lire($id);
        self::assertTrue($this->fiches->remplacerSiInchangee($lue, 'A', 2.3522, 48.8566));
        self::assertTrue($this->fiches->remplacerSiInchangee($this->fiches->lire($id), 'A', 2.3522, 48.8566), 'Une reprise qui réécrit la même valeur réussit');
    }

    public function testRemplacerSiInchangeeRefuseUneFicheModifiee(): void
    {
        $id = $this->inserer('Brume', 'XA', 0.0, 0.0, 'N');
        $lue = $this->fiches->lire($id);
        $this->lien->query("UPDATE essai_geoloc SET STATUT = 'M', LON = 1.25, LAT = 44.75 WHERE ID = $id");
        self::assertFalse($this->fiches->remplacerSiInchangee($lue, 'A', 2.3522, 48.8566));
        self::assertSame('M', $this->fiches->lire($id)['statut']);
    }

    public function testRemplacerSiInchangeeSansCoordonneesNeChangeQueLeStatut(): void
    {
        $id = $this->inserer('Brume', 'XA', 0.0, 0.0, 'N');
        self::assertTrue($this->fiches->remplacerSiInchangee($this->fiches->lire($id), 'I', null, null));
        self::assertSame(['I', '0', '0'], $this->lien->query("SELECT STATUT, LON, LAT FROM essai_geoloc WHERE ID = $id")->fetch_row());
    }

    public function testComptageDesFichesSansCoordonnees(): void
    {
        $this->inserer('Un', 'XA', 0.0, 0.0, 'N');
        $this->inserer('Deux', 'XA', 0.0, 0.0, 'I');
        $this->inserer('Trois', 'XA', 1.0, 1.0, 'A');
        self::assertSame(2, $this->fiches->compterSansCoordonnees());
        self::assertTrue($this->fiches->estSansCoordonnees('Deux', 'XA'));
        self::assertFalse($this->fiches->estSansCoordonnees('Trois', 'XA'));
        self::assertFalse($this->fiches->estSansCoordonnees('Absente', 'XA'));
    }

    public function testEnregistrerFormulaireRefuseUneVersionDepassee(): void
    {
        $id = $this->inserer('Brume', 'XA', 1.5, 45.5, 'A');
        $version = $this->fiches->lire($id);
        $notes = ['N' => 'n', 'M' => 'm', 'D' => 'd', 'V' => 'v'];
        $this->lien->query("UPDATE essai_geoloc SET LON = 2.3522, LAT = 48.8566 WHERE ID = $id");
        self::assertFalse($this->fiches->enregistrerFormulaire($id, $version, 1.5, 45.5, 'A', $notes));
        self::assertTrue($this->fiches->enregistrerFormulaire($id, $this->fiches->lire($id), 1.75, 45.25, 'M', $notes));
        self::assertSame(['M', 'n', 'v'], $this->lien->query("SELECT STATUT, NOTE_N, NOTE_V FROM essai_geoloc WHERE ID = $id")->fetch_row());
    }

    public function testEnregistrerFormulaireSansChangementReussit(): void
    {
        $id = $this->inserer('Brume', 'XA', 1.5, 45.5, 'A');
        $notes = ['N' => 'note Brume', 'M' => '', 'D' => '', 'V' => ''];
        self::assertTrue($this->fiches->enregistrerFormulaire($id, $this->fiches->lire($id), 1.5, 45.5, 'A', $notes));
    }
}
