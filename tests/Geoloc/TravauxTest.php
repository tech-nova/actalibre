<?php
declare(strict_types=1);

namespace ActaLibre\Tests\Geoloc;

use ActaLibre\Carte\RefusDebit;
use ActaLibre\Geoloc\Cache;
use ActaLibre\Geoloc\LimiteDebit;
use ActaLibre\Geoloc\Moteur;
use ActaLibre\Geoloc\Occupe;
use ActaLibre\Geoloc\TravailIntrouvable;
use ActaLibre\Geoloc\Travaux;
use PHPUnit\Framework\TestCase;

final class TravauxTest extends TestCase
{
    private DossierEssai $essai;
    private FichesMemoire $fiches;
    /** @var list<string> */
    private array $appels = [];
    private ?\RuntimeException $panne = null;

    protected function setUp(): void
    {
        $this->essai = new DossierEssai();
        $this->fiches = new FichesMemoire();
        $this->fiches->ajouter(1, 'Aube', 'XA', '0', '0', 'N');
        $this->fiches->ajouter(2, 'Vauclairmont', 'XA', '0', '0', 'N');
        $this->fiches->ajouter(3, 'Brume', 'XA', '1.5', '45.5', 'M');
    }

    protected function tearDown(): void
    {
        $this->essai->supprimer();
    }

    private function travaux(string $portee = 'base/gm'): Travaux
    {
        return new Travaux($this->essai->stockage(), $this->essai->horloge(), $portee);
    }

    private function moteur(): Moteur
    {
        $stockage = $this->essai->stockage();
        return new Moteur($this->fiches, new Cache($stockage, $this->essai->horloge(), 'f'), new LimiteDebit($stockage, $this->essai->horloge(), $this->essai->sommeil()),
            function (string $requete): ?array {
                $this->appels[] = $requete;
                if ($this->panne !== null) {
                    throw $this->panne;
                }
                return $requete === 'Aube, XA' ? ['lon' => 2.35, 'lat' => 48.85] : null;
            });
    }

    public function testUneSelectionInvalideEstRefusee(): void
    {
        foreach ([[], ['abc'], [0], [-3], [1.5], range(1, Travaux::MAX_IDS + 1)] as $ids) {
            try {
                $this->travaux()->creer(7, $ids);
                self::fail('Sélection refusée : ' . json_encode($ids));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testUnTravailTraiteUnLieuParAppelJusquAuBilan(): void
    {
        $id = $this->travaux()->creer(7, ['1', 2, 3, 3]);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $id);
        self::assertSame(['etat' => 'pret', 'total' => 3, 'traites' => 0], array_intersect_key(Travaux::vue($this->travaux()->lire($id, 7)), ['etat' => 1, 'total' => 1, 'traites' => 1]));

        $vue = $this->travaux()->avancer($id, 7, $this->moteur());
        self::assertSame(['actif', 1], [$vue['etat'], $vue['traites']]);
        self::assertSame(['id' => 1, 'libelle' => 'Aube [XA]', 'issue' => 'trouvee', 'cache' => false], $vue['dernier']);
        $this->travaux()->avancer($id, 7, $this->moteur());
        $vue = $this->travaux()->avancer($id, 7, $this->moteur());

        self::assertSame('termine', $vue['etat']);
        self::assertSame(['trouvee' => 1, 'introuvable' => 1, 'manuelle' => 1, 'a_verifier' => 0], $vue['compteurs']);
        self::assertSame(['Aube, XA', 'Vauclairmont, XA'], $this->appels, 'Aucun appel pour la fiche manuelle');
        self::assertSame('termine', $this->travaux()->avancer($id, 7, $this->moteur())['etat']);
        self::assertCount(2, $this->appels, 'Un travail terminé ne refait rien');
    }

    public function testUnTravailNEstVisibleQueDeSonProprietaireEtDeSonSite(): void
    {
        $id = $this->travaux()->creer(7, [1]);
        foreach ([fn () => $this->travaux()->lire($id, 8), fn () => $this->travaux('autre/gm')->lire($id, 7), fn () => $this->travaux()->lire('../../x', 7), fn () => $this->travaux()->lire(str_repeat('0', 32), 7)] as $lecture) {
            try {
                $lecture();
                self::fail('Travail inaccessible');
            } catch (TravailIntrouvable) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testUnRefusDeDebitSuspendEtLaRepriseRespecteLaPause(): void
    {
        $id = $this->travaux()->creer(7, [1, 2]);
        $this->panne = new RefusDebit('trop vite', 120);
        $vue = $this->travaux()->avancer($id, 7, $this->moteur());
        self::assertSame(['suspendu', 'debit', 0], [$vue['etat'], $vue['cause'], $vue['traites']]);
        self::assertEqualsWithDelta($this->essai->temps + 120, $vue['reprise'], 1);
        self::assertSame('N', $this->fiches->fiches[1]['statut']);

        $this->panne = null;
        self::assertSame('suspendu', $this->travaux()->avancer($id, 7, $this->moteur())['etat'], 'Un travail suspendu attend une reprise explicite');
        self::assertSame('pret', $this->travaux()->reprendre($id, 7)['etat']);
        $vue = $this->travaux()->avancer($id, 7, $this->moteur());
        self::assertSame(['suspendu', 'debit'], [$vue['etat'], $vue['cause']], 'La pause demandée n’est pas écoulée');
        self::assertCount(1, $this->appels);

        $this->essai->temps += 121;
        $this->travaux()->reprendre($id, 7);
        self::assertSame(1, $this->travaux()->avancer($id, 7, $this->moteur())['traites']);
    }

    public function testUnePanneSuspendSansPerdreLesLieuxRestants(): void
    {
        $id = $this->travaux()->creer(7, [1, 2]);
        $this->travaux()->avancer($id, 7, $this->moteur());
        $this->panne = new \RuntimeException('coupure');
        $vue = $this->travaux()->avancer($id, 7, $this->moteur());
        self::assertSame(['suspendu', 'panne', 1], [$vue['etat'], $vue['cause'], $vue['traites']]);
        $this->panne = null;
        $this->travaux()->reprendre($id, 7);
        self::assertSame('termine', $this->travaux()->avancer($id, 7, $this->moteur())['etat']);
    }

    public function testDeuxRequetesSimultaneesNAvancentPasLeMemeTravail(): void
    {
        $id = $this->travaux()->creer(7, [1]);
        $poignee = fopen($this->essai->chemin . '/geocodage/travaux.lock', 'c');
        flock($poignee, LOCK_EX);
        try {
            $this->expectException(Occupe::class);
            $this->travaux()->avancer($id, 7, $this->moteur());
        } finally {
            flock($poignee, LOCK_UN);
            fclose($poignee);
            self::assertSame([], $this->appels);
        }
    }

    public function testUnArretEntreLEcritureEtLeCheckpointNeCompteQuUneFoisSansNouvelAppel(): void
    {
        $id = $this->travaux()->creer(7, [1]);
        $this->moteur()->traiter(1);
        $vue = $this->travaux()->avancer($id, 7, $this->moteur());
        self::assertSame(['termine', 1, 1], [$vue['etat'], $vue['traites'], $vue['compteurs']['trouvee']]);
        self::assertTrue($vue['dernier']['cache']);
        self::assertCount(1, $this->appels);
    }

    public function testUnTravailInactifExpireApresSeptJours(): void
    {
        $id = $this->travaux()->creer(7, [1, 2]);
        $this->essai->temps += Travaux::EXPIRATION + 1;
        self::assertSame('expire', $this->travaux()->avancer($id, 7, $this->moteur())['etat']);
        self::assertSame('expire', $this->travaux()->reprendre($id, 7)['etat']);
        self::assertSame([], $this->appels);
        self::assertNull($this->travaux()->dernierActif(7));
    }

    public function testLeDernierTravailInacheveEstRetrouve(): void
    {
        $termine = $this->travaux()->creer(7, [3]);
        $this->travaux()->avancer($termine, 7, $this->moteur());
        $this->essai->temps += 10;
        $suspendu = $this->travaux()->creer(7, [1, 2]);
        $this->travaux()->suspendre($suspendu, 7);
        self::assertSame($suspendu, $this->travaux()->dernierActif(7)['id']);
        self::assertNull($this->travaux()->dernierActif(8));
    }

    public function testLaCreationPurgeLesFichiersExpires(): void
    {
        $ancien = $this->travaux()->creer(7, [1]);
        touch($this->essai->chemin . '/geocodage/travail-' . $ancien . '.json', (int) $this->essai->temps - Travaux::EXPIRATION - 10);
        $this->travaux()->creer(7, [2]);
        self::assertFileDoesNotExist($this->essai->chemin . '/geocodage/travail-' . $ancien . '.json');
    }
}
