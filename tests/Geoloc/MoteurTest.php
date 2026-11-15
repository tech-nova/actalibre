<?php
declare(strict_types=1);

namespace ActaLibre\Tests\Geoloc;

use ActaLibre\Geoloc\Cache;
use ActaLibre\Geoloc\Issue;
use ActaLibre\Geoloc\LimiteDebit;
use ActaLibre\Geoloc\Moteur;
use PHPUnit\Framework\TestCase;

final class MoteurTest extends TestCase
{
    private DossierEssai $essai;
    private FichesMemoire $fiches;
    /** @var list<string> */
    private array $appels = [];
    /** @var array<string,array{lon:float,lat:float}|null|\RuntimeException> */
    private array $reponses = [];
    /** @var (\Closure(): void)|null */
    private ?\Closure $pendantLaRecherche = null;

    protected function setUp(): void
    {
        $this->essai = new DossierEssai();
        $this->fiches = new FichesMemoire();
    }

    protected function tearDown(): void
    {
        $this->essai->supprimer();
    }

    private function moteur(): Moteur
    {
        $stockage = $this->essai->stockage();
        return new Moteur(
            $this->fiches,
            new Cache($stockage, $this->essai->horloge(), 'https://exemple.org/search'),
            new LimiteDebit($stockage, $this->essai->horloge(), $this->essai->sommeil()),
            function (string $requete): ?array {
                $this->appels[] = $requete;
                if ($this->pendantLaRecherche !== null) {
                    ($this->pendantLaRecherche)();
                }
                $reponse = $this->reponses[$requete] ?? null;
                if ($reponse instanceof \RuntimeException) {
                    throw $reponse;
                }
                return $reponse;
            }
        );
    }

    public function testLaRequeteDecodeLesNomsHistoriques(): void
    {
        self::assertSame("L'Isle & Fils, Hautes-Brumes", Moteur::requete('L&#039;Isle &amp; Fils', 'Hautes-Brumes'));
        self::assertSame('Vauclair', Moteur::requete('Vauclair', ''));
    }

    public function testUneFicheNonDefinieTrouveeDevientAutomatique(): void
    {
        $this->fiches->ajouter(1, 'Brume', 'XA', '0', '0', 'N');
        $this->reponses['Brume, XA'] = ['lon' => 2.35, 'lat' => 48.85];
        $resultat = $this->moteur()->traiter(1);
        self::assertSame(Issue::Trouvee, $resultat->issue);
        self::assertSame('Brume [XA]', $resultat->libelle);
        self::assertSame(['A', '2.35', '48.85'], [$this->fiches->fiches[1]['statut'], $this->fiches->fiches[1]['lon'], $this->fiches->fiches[1]['lat']]);
    }

    public function testUneFicheNonDefinieIntrouvableDevientI(): void
    {
        $this->fiches->ajouter(1, 'Vauclairmont', 'XA', '0', '0', 'N');
        self::assertSame(Issue::Introuvable, $this->moteur()->traiter(1)->issue);
        self::assertSame(['I', '0', '0'], [$this->fiches->fiches[1]['statut'], $this->fiches->fiches[1]['lon'], $this->fiches->fiches[1]['lat']]);
    }

    public function testUneFicheIntrouvableRetrouveeDevientAutomatique(): void
    {
        $this->fiches->ajouter(1, 'Brume', 'XA', '0', '0', 'I');
        $this->reponses['Brume, XA'] = ['lon' => 2.35, 'lat' => 48.85];
        self::assertSame(Issue::Trouvee, $this->moteur()->traiter(1)->issue);
        self::assertSame('A', $this->fiches->fiches[1]['statut']);
    }

    public function testUneFicheAutomatiqueEstEcraseeParUnNouveauResultat(): void
    {
        $this->fiches->ajouter(1, 'Brume', 'XA', '1', '1', 'A');
        $this->reponses['Brume, XA'] = ['lon' => 2.35, 'lat' => 48.85];
        self::assertSame(Issue::Trouvee, $this->moteur()->traiter(1)->issue);
        self::assertSame('2.35', $this->fiches->fiches[1]['lon']);
    }

    public function testUneFicheAutomatiqueSansResultatGardeSesCoordonnees(): void
    {
        $this->fiches->ajouter(1, 'Brume', 'XA', '1', '1', 'A');
        self::assertSame(Issue::SansNouveauResultat, $this->moteur()->traiter(1)->issue);
        self::assertSame(['A', '1', '1'], [$this->fiches->fiches[1]['statut'], $this->fiches->fiches[1]['lon'], $this->fiches->fiches[1]['lat']]);
    }

    public function testUneFicheManuelleNEstNiRechercheeNiModifiee(): void
    {
        $this->fiches->ajouter(1, 'Brume', 'XA', '1.5', '45.5', 'M');
        self::assertSame(Issue::ManuelleConservee, $this->moteur()->traiter(1)->issue);
        self::assertSame([], $this->appels);
        self::assertSame('M', $this->fiches->fiches[1]['statut']);
    }

    public function testUneCorrectionManuellePendantLaRechercheEstPreservee(): void
    {
        $this->fiches->ajouter(1, 'Brume', 'XA', '1', '1', 'A');
        $this->reponses['Brume, XA'] = ['lon' => 2.35, 'lat' => 48.85];
        $this->pendantLaRecherche = function (): void {
            $this->fiches->ajouter(1, 'Brume', 'XA', '1.5', '45.5', 'M');
        };
        self::assertSame(Issue::Modifiee, $this->moteur()->traiter(1)->issue);
        self::assertSame(['M', '1.5'], [$this->fiches->fiches[1]['statut'], $this->fiches->fiches[1]['lon']]);
    }

    public function testUneFicheSupprimeeNEstPasRecreee(): void
    {
        $this->fiches->ajouter(1, 'Brume', 'XA', '0', '0', 'N');
        $this->reponses['Brume, XA'] = ['lon' => 2.35, 'lat' => 48.85];
        $this->pendantLaRecherche = function (): void {
            unset($this->fiches->fiches[1]);
        };
        self::assertSame(Issue::Supprimee, $this->moteur()->traiter(1)->issue);
        self::assertSame([], $this->fiches->fiches);
        self::assertSame(Issue::Supprimee, $this->moteur()->traiter(99)->issue);
    }

    public function testUnePanneNeModifieRienEtNEstPasMiseEnCache(): void
    {
        $this->fiches->ajouter(1, 'Brume', 'XA', '0', '0', 'N');
        $this->reponses['Brume, XA'] = new \RuntimeException('coupure');
        try {
            $this->moteur()->traiter(1);
            self::fail('La panne est transmise');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }
        self::assertSame('N', $this->fiches->fiches[1]['statut']);
        $this->reponses['Brume, XA'] = ['lon' => 2.35, 'lat' => 48.85];
        self::assertSame(Issue::Trouvee, $this->moteur()->traiter(1)->issue);
        self::assertCount(2, $this->appels, 'La panne n’a pas été mémorisée comme une absence');
    }

    public function testDesCoordonneesHorsBornesSontUnePanne(): void
    {
        $this->fiches->ajouter(1, 'Brume', 'XA', '0', '0', 'N');
        $this->reponses['Brume, XA'] = ['lon' => 200.0, 'lat' => 48.85];
        $this->expectException(\RuntimeException::class);
        try {
            $this->moteur()->traiter(1);
        } finally {
            self::assertSame('N', $this->fiches->fiches[1]['statut']);
        }
    }

    public function testUneReponseRecenteEstReutilisee(): void
    {
        $this->fiches->ajouter(1, 'Brume', 'XA', '0', '0', 'N');
        $this->fiches->ajouter(2, 'BRUME', 'XA', '0', '0', 'N');
        $this->reponses['Brume, XA'] = ['lon' => 2.35, 'lat' => 48.85];
        self::assertFalse($this->moteur()->traiter(1)->depuisCache);
        $this->fiches->ajouter(1, 'Brume', 'XA', '0', '0', 'N');
        $resultat = $this->moteur()->traiter(1);
        self::assertSame(Issue::Trouvee, $resultat->issue);
        self::assertTrue($resultat->depuisCache);
        self::assertCount(1, $this->appels);
    }

    public function testDepuisUneFicheNonManuelleLeResultatEstEnregistre(): void
    {
        $this->fiches->ajouter(1, 'Brume', 'XA', '0', '0', 'N');
        $this->reponses['Brume, XA'] = ['lon' => 2.35, 'lat' => 48.85];
        $reponse = $this->moteur()->rechercherPourFiche(1);
        self::assertSame('trouvee', $reponse['issue']);
        self::assertSame('A', $reponse['fiche']['statut']);
    }

    public function testDepuisUneFicheManuelleLeResultatEstSeulementPropose(): void
    {
        $this->fiches->ajouter(1, 'Brume', 'XA', '1.5', '45.5', 'M');
        $this->reponses['Brume, XA'] = ['lon' => 2.35, 'lat' => 48.85];
        $reponse = $this->moteur()->rechercherPourFiche(1);
        self::assertSame('proposition', $reponse['issue']);
        self::assertSame(['lon' => 2.35, 'lat' => 48.85], $reponse['proposition']);
        self::assertSame('M', $this->fiches->fiches[1]['statut']);

        $version = ['statut' => 'M', 'lon' => '1.5', 'lat' => '45.5'];
        self::assertSame('trouvee', $this->moteur()->remplacerManuelle(1, $version, 2.35, 48.85)['issue']);
        self::assertSame(['A', '2.35'], [$this->fiches->fiches[1]['statut'], $this->fiches->fiches[1]['lon']]);
        self::assertCount(1, $this->appels, 'La validation reprend le résultat en cache');
    }

    public function testLaValidationRefuseDesCoordonneesQueLeServeurNAPasObtenues(): void
    {
        $this->fiches->ajouter(1, 'Brume', 'XA', '1.5', '45.5', 'M');
        $this->reponses['Brume, XA'] = ['lon' => 2.35, 'lat' => 48.85];
        $this->moteur()->rechercherPourFiche(1);
        $this->expectException(\InvalidArgumentException::class);
        $this->moteur()->remplacerManuelle(1, ['statut' => 'M', 'lon' => '1.5', 'lat' => '45.5'], 9.0, 9.0);
    }

    public function testLaValidationRefuseUneFicheModifieeDepuisLaProposition(): void
    {
        $this->fiches->ajouter(1, 'Brume', 'XA', '1.5', '45.5', 'M');
        $this->reponses['Brume, XA'] = ['lon' => 2.35, 'lat' => 48.85];
        $this->moteur()->rechercherPourFiche(1);
        $this->fiches->ajouter(1, 'Brume', 'XA', '1.75', '45.25', 'M');
        self::assertSame('modifiee', $this->moteur()->remplacerManuelle(1, ['statut' => 'M', 'lon' => '1.5', 'lat' => '45.5'], 2.35, 48.85)['issue']);
        self::assertSame('1.75', $this->fiches->fiches[1]['lon']);
    }
}
