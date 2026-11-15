<?php
declare(strict_types=1);

namespace ActaLibre\Tests\Geoloc;

use ActaLibre\Geoloc\Cache;
use ActaLibre\Geoloc\LimiteDebit;
use ActaLibre\Geoloc\Moteur;
use ActaLibre\Geoloc\Travaux;
use PHPUnit\Framework\TestCase;

/** Trois cents lieux mêlant les quatre statuts, sans attendre cinq minutes réelles. */
final class RecetteTest extends TestCase
{
    public function testTroisCentsLieuxSontTraitesAuRythmeDuServiceAvecUnBilanCoherent(): void
    {
        $essai = new DossierEssai();
        try {
            $fiches = new FichesMemoire();
            $statuts = ['N', 'I', 'A', 'M'];
            for ($id = 1; $id <= 300; $id++) {
                $fiches->ajouter($id, 'Lieu' . $id, 'XA', '0', '0', $statuts[$id % 4]);
            }
            $appels = 0;
            $stockage = $essai->stockage();
            $moteur = new Moteur($fiches, new Cache($stockage, $essai->horloge(), 'f'), new LimiteDebit($stockage, $essai->horloge(), $essai->sommeil()),
                static function (string $requete) use (&$appels): ?array {
                    $appels++;
                    return (int) substr($requete, 4) % 10 === 0 ? null : ['lon' => 2.0, 'lat' => 47.0];
                });
            $travaux = new Travaux($stockage, $essai->horloge(), 'base/gm');
            $travail = $travaux->creer(1, range(1, 300));
            $debut = $essai->temps;
            do {
                $vue = $travaux->avancer($travail, 1, $moteur);
            } while (in_array($vue['etat'], ['pret', 'actif'], true));

            self::assertSame('termine', $vue['etat']);
            self::assertSame(300, $vue['traites']);
            self::assertSame(75, $vue['compteurs']['manuelle']);
            self::assertSame(300, array_sum($vue['compteurs']));
            self::assertSame(225, $appels, 'Aucun appel pour les fiches manuelles');
            self::assertGreaterThanOrEqual(224.0, $essai->temps - $debut, 'Au moins une seconde entre deux appels');
            foreach ($fiches->fiches as $fiche) {
                if ($fiche['id'] % 4 === 3) {
                    self::assertSame('M', $fiche['statut']);
                }
            }
        } finally {
            $essai->supprimer();
        }
    }
}
