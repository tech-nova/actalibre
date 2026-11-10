<?php
declare(strict_types=1);

namespace ActaLibre\Tests\Compte;

use ActaLibre\Compte\ActesVus;
use ActaLibre\Compte\Migration;
use PHPUnit\Framework\TestCase;

final class ActesVusTest extends TestCase
{
    private \mysqli $lien;
    private ActesVus $vus;

    protected function setUp(): void
    {
        $hote = getenv('GM_DB_HOST');
        $nom = getenv('GM_DB_NAME');
        $utilisateur = getenv('GM_DB_USER');
        $passe = getenv('GM_DB_PASS');
        if ($hote === false || $nom === false || $utilisateur === false || $passe === false) {
            self::markTestSkipped('ActesVusTest ignoré : définir GM_DB_HOST, GM_DB_NAME, GM_DB_USER et GM_DB_PASS.');
        }
        require_once dirname(__DIR__, 2) . '/tools/EA_sql.inc.php';
        mysqli_report(MYSQLI_REPORT_OFF);
        $this->lien = new \mysqli($hote, $utilisateur, $passe, $nom);
        $this->nettoyer();
        self::assertTrue(Migration::preparerParcours($this->lien, 'essai_vus'), $this->lien->error);
        self::assertTrue(Migration::preparerParcours($this->lien, 'essai_vus'), 'Relancer est sans effet');
        $this->vus = new ActesVus($this->lien, 'essai_vus_acte_vu');
    }

    protected function tearDown(): void
    {
        if (isset($this->lien)) {
            $this->nettoyer();
        }
    }

    private function nettoyer(): void
    {
        foreach (['essai', 'reinit', 'acte_vu', 'courriel'] as $table) {
            $this->lien->query("DROP TABLE IF EXISTS essai_vus_$table");
        }
    }

    public function testUnActeNeSeNoteQuUneFoisParJour(): void
    {
        self::assertFalse($this->vus->dejaVu(11, 'N', 5, '2026-11-05'));
        self::assertTrue($this->vus->noter(11, 'N', 5, '2026-11-05'), 'Première écriture');
        self::assertTrue($this->vus->dejaVu(11, 'N', 5, '2026-11-05'));
        self::assertFalse($this->vus->noter(11, 'N', 5, '2026-11-05'), 'Doublon');
    }

    public function testLeCompteLeTypeLActeEtLeJourComptentChacun(): void
    {
        $this->vus->noter(11, 'N', 5, '2026-11-05');
        self::assertFalse($this->vus->dejaVu(12, 'N', 5, '2026-11-05'));
        self::assertFalse($this->vus->dejaVu(11, 'M', 5, '2026-11-05'));
        self::assertFalse($this->vus->dejaVu(11, 'N', 6, '2026-11-05'));
        self::assertFalse($this->vus->dejaVu(11, 'N', 5, '2026-11-06'));
    }

    public function testLaPurgeRetireLesJoursPasses(): void
    {
        $this->vus->noter(11, 'N', 5, '2026-11-04');
        $this->vus->noter(11, 'N', 6, '2026-11-05');
        self::assertTrue($this->vus->purger('2026-11-05'));
        self::assertFalse($this->vus->dejaVu(11, 'N', 5, '2026-11-04'));
        self::assertTrue($this->vus->dejaVu(11, 'N', 6, '2026-11-05'));
    }

    public function testUneErreurDeBaseSeDistingueDUnDoublon(): void
    {
        $sansTable = new ActesVus($this->lien, 'essai_vus_absente');
        self::assertNull($sansTable->noter(11, 'N', 5, '2026-11-05'));
    }
}
