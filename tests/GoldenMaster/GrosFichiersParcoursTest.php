<?php
declare(strict_types=1);

namespace ActaLibre\Tests\GoldenMaster;

use PHPUnit\Framework\TestCase;

/** Un fichier d'import plus gros que la mémoire allouée à PHP se charge en plusieurs passes. */
final class GrosFichiersParcoursTest extends TestCase
{
    private const LIGNES = 900000;

    private SiteJetable $site;
    private string $source = '';

    protected function setUp(): void
    {
        $hote = getenv('GM_DB_HOST');
        $nom = getenv('GM_DB_NAME');
        $utilisateur = getenv('GM_DB_USER');
        $passe = getenv('GM_DB_PASS');
        if ($hote === false || $nom === false || $utilisateur === false || $passe === false) {
            self::markTestSkipped('GrosFichiersParcoursTest ignoré : définir GM_DB_HOST, GM_DB_NAME, GM_DB_USER et GM_DB_PASS.');
        }
        $reglages = ['memory_limit=64M', 'upload_max_filesize=200M', 'post_max_size=200M'];
        $this->site = new SiteJetable(dirname(__DIR__, 2), ['hote' => $hote, 'nom' => $nom, 'utilisateur' => $utilisateur, 'passe' => $passe], $reglages);
        $this->site->demarrer();
        $this->site->base->query("UPDATE gm_params SET valeur = '6' WHERE param = 'MAX_EXEC_TIME'");
    }

    protected function tearDown(): void
    {
        if ($this->source !== '' && is_file($this->source)) {
            unlink($this->source);
        }
        if (isset($this->site)) {
            $this->site->arreter();
        }
    }

    private function ecrire(callable $ligne, string $extension): void
    {
        $this->source = sys_get_temp_dir() . '/gm-gros-' . getmypid() . '.' . $extension;
        $sortie = fopen($this->source, 'w');
        for ($i = 1; $i <= self::LIGNES; $i++) {
            fwrite($sortie, $ligne($i));
        }
        fclose($sortie);
        self::assertGreaterThan(80 * 1024 * 1024, filesize($this->source));
    }

    private function assertPremierePasse(Reponse $page): void
    {
        self::assertSame(200, $page->statut);
        self::assertStringNotContainsString('Fatal error', $page->corps);
        self::assertStringContainsString("Temps maximum d'exécution écoulé", $page->corps);
        $journal = is_file($this->site->journal) ? (string) file_get_contents($this->site->journal) : '';
        self::assertSame('', $journal, substr($journal, 0, 1500));
    }

    public function testUnGrosFichierNimegueSeChargeParPasses(): void
    {
        $this->ecrire(static fn (int $i): string => 'NIMEGUEV3;XA001;Vauclairmont;XA;Hautes-Brumes;N;01/02/1790;;;;FICTIFGROS;Jean' . $i . ';' . str_repeat(';', 60) . "\n", 'txt');
        $page = $this->site->client('niveau9')->post('/admin/charge.php', ['action' => 'submitted', 'Origine' => 'N', 'TypeActes' => 'N', 'bkfile' => 'NULL', 'Dedoublon' => 'A', 'logOk' => '0'], ['Actes' => $this->source]);
        $this->assertPremierePasse($page);
        self::assertGreaterThan(0, (int) $this->site->base->query("SELECT COUNT(*) FROM gm_nai3 WHERE NOM = 'FICTIFGROS'")->fetch_row()[0]);
    }

    public function testUnGrosFichierCsvSeChargeParPasses(): void
    {
        $this->ecrire(static fn (int $i): string => 'FICTIFGROS;Jean' . $i . ';01/02/1790;' . str_repeat('x', 80) . "\n", 'csv');
        $client = $this->site->client('niveau9');
        $commun = ['TypeActes' => 'N', 'Commune' => 'Vauclairmont', 'Depart' => 'Hautes-Brumes'];
        $page = $client->post('/admin/chargecsv.php', $commun + ['action' => 'phase_1', 'submitD' => '1'], ['Actes' => $this->source]);
        self::assertSame(1, preg_match('/name="fileuploaded" value="([^"]+)"/', $page->corps, $fichier), 'Phase 1 : ' . $page->statut . ' ' . substr(trim(strip_tags($page->corps)), -400));
        self::assertGreaterThan(0, preg_match_all('/name="ZID(\d+)" value="(\d+)"/', $page->corps, $zones, PREG_SET_ORDER));
        $colonnes = [];
        foreach (['NOM' => '1', 'PRE' => '2', 'DATETXT' => '3'] as $zone => $colonne) {
            $colonnes[$this->site->base->query("SELECT ZID FROM gm_metadb WHERE ZONE = '$zone' AND dtable = 'N'")->fetch_row()[0]] = $colonne;
        }
        $parametres = $commun + ['action' => 'phase_2', 'submitC' => '1', 'fileuploaded' => html_entity_decode($fichier[1])];
        foreach ($zones as [, $rang, $zid]) {
            $parametres['ZID' . $rang] = $zid;
            $parametres['Zone' . $rang] = $colonnes[$zid] ?? '0';
            $parametres['Trait' . $rang] = '0';
        }
        $page = $client->post('/admin/chargecsv.php', $parametres);
        $this->assertPremierePasse($page);
        self::assertGreaterThan(0, (int) $this->site->base->query("SELECT COUNT(*) FROM gm_nai3 WHERE NOM = 'FICTIFGROS'")->fetch_row()[0]);
    }
}
