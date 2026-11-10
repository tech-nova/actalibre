<?php
declare(strict_types=1);

namespace ActaLibre\Tests\GoldenMaster;

use PHPUnit\Framework\TestCase;

/** Comportements corrigés sur les pages d'import, d'export, de carte et de gestion. */
final class CorrectionsParcoursTest extends TestCase
{
    private SiteJetable $site;

    protected function setUp(): void
    {
        $hote = getenv('GM_DB_HOST');
        $nom = getenv('GM_DB_NAME');
        $utilisateur = getenv('GM_DB_USER');
        $passe = getenv('GM_DB_PASS');
        if ($hote === false || $nom === false || $utilisateur === false || $passe === false) {
            self::markTestSkipped('CorrectionsParcoursTest ignoré : définir GM_DB_HOST, GM_DB_NAME, GM_DB_USER et GM_DB_PASS.');
        }
        $this->site = SiteJetable::partage(['hote' => $hote, 'nom' => $nom, 'utilisateur' => $utilisateur, 'passe' => $passe]);
    }

    private function parametre(string $nom, string $valeur): void
    {
        $this->site->base->query("UPDATE gm_params SET valeur = '" . $this->site->base->real_escape_string($valeur) . "' WHERE param = '$nom'");
    }

    private function valeur(string $requete): string
    {
        return (string) $this->site->base->query($requete)->fetch_row()[0];
    }

    private function assertPageSansErreur(Reponse $page): void
    {
        self::assertSame(200, $page->statut);
        self::assertStringNotContainsString('Fatal error', $page->corps);
        self::assertStringNotContainsString('-> Erreur', $page->corps);
    }

    private function assertJournalSansErreur(): void
    {
        $journal = is_file($this->site->journal) ? (string) file_get_contents($this->site->journal) : '';
        self::assertSame('', $journal);
    }

    private function ligneNimegue(string $commune, string $prenom = 'Jean'): string
    {
        return 'NIMEGUEV3;XA001;' . $commune . ';XA;Hautes-Brumes;N;01/02/1790;;;;FICTIF;' . $prenom . ';' . str_repeat(';', 60) . "\n";
    }

    private function importerNimegue(string $contenu): Reponse
    {
        $source = sys_get_temp_dir() . '/gm-nimegue-' . getmypid() . '.txt';
        file_put_contents($source, $contenu);
        try {
            return $this->site->client('niveau9')->post('/admin/charge.php', ['action' => 'submitted', 'Origine' => 'N', 'TypeActes' => 'N', 'bkfile' => 'NULL', 'Dedoublon' => 'A', 'logOk' => '0'], ['Actes' => $source]);
        } finally {
            unlink($source);
        }
    }

    private function ajouterNaissance(string $commune, string $departement = 'XA'): void
    {
        $this->site->base->query("INSERT INTO gm_nai3 (COMMUNE, DEPART, TYPACT, NOM, PRE, DATETXT, LADATE, DEPOSANT) VALUES ('$commune', '$departement', 'N', 'FICTIF', 'Jean', '01/02/1790', '1790-02-01', 1)");
    }

    private function sauvegarder(string $commune, string $departement): void
    {
        $this->ajouterNaissance($commune, $departement);
        $this->site->client('niveau9')->post('/admin/exporte.php', ['action' => 'go', 'TypeActes' => 'N', 'Destin' => 'B', 'ComDep' => "$commune [$departement]", 'maxmega' => '1']);
    }

    public function testUneDateLimiteFixeNEmpechePasDeCreerUnCompte(): void
    {
        $this->parametre('LIMITE_EXPIRATION', '31/12/2030');
        $page = $this->site->client('niveau9')->get('/admin/gestuser.php', ['id' => '-1']);
        $this->assertPageSansErreur($page);
        self::assertSame(1, preg_match('/name="dtexpir"[^>]*value="31\/12\/2030"/', $page->corps));
    }

    public function testUnOctetSansCaractereNInterromptPasLImport(): void
    {
        $avant = (int) $this->valeur('SELECT COUNT(*) FROM gm_nai3');
        $page = $this->importerNimegue($this->ligneNimegue('Vauclairmont', "Je\x81an"));
        $this->assertPageSansErreur($page);
        self::assertSame($avant + 1, (int) $this->valeur('SELECT COUNT(*) FROM gm_nai3'));
        self::assertSame('1', $this->valeur("SELECT COUNT(*) FROM gm_nai3 WHERE NOM = 'FICTIF' AND PRE = 'Jean'"));
    }

    public function testUnImportDePlusieursCommunesLesCompteToutes(): void
    {
        $page = $this->importerNimegue($this->ligneNimegue('FictifPremiere') . $this->ligneNimegue('FictifSeconde'));
        $this->assertPageSansErreur($page);
        self::assertSame('1', $this->valeur("SELECT COUNT(*) FROM gm_sums WHERE COMMUNE = 'FictifPremiere' AND TYPACT = 'N'"));
        self::assertSame('1', $this->valeur("SELECT COUNT(*) FROM gm_sums WHERE COMMUNE = 'FictifSeconde' AND TYPACT = 'N'"));
    }

    public function testLaRestaurationDUnCompteSansDateDeCreationAboutit(): void
    {
        $fichier = sys_get_temp_dir() . '/gm-import-' . getmypid() . '.csv';
        file_put_contents($fichier, "FICTIF;Restaure;restaure@example.org;fictif_restaure;Fictif-Passe-1;1;1;5;;2033-12-31;;4242;N;\n");
        try {
            $page = $this->site->client('niveau9')->post('/admin/loaduser.php', ['action' => 'submitted', 'lelevel' => '1', 'regime' => '1'], ['Users' => $fichier]);
        } finally {
            unlink($fichier);
        }
        $this->assertPageSansErreur($page);
        self::assertSame('1001-01-01', $this->valeur("SELECT DTCREATION FROM gm_user3 WHERE LOGIN = 'fictif_restaure'"));
    }

    public function testLaCarteTotaliseLesDeposantsDUneCommune(): void
    {
        $this->site->base->query("INSERT INTO gm_sums (COMMUNE, DEPART, TYPACT, LIBELLE, DEPOSANT, NB_TOT) VALUES ('FictifDeux', 'XA', 'N', '', 1, 30), ('FictifDeux', 'XA', 'N', '', 2, 12)");
        $this->site->base->query("INSERT INTO gm_geoloc (COMMUNE, DEPART, LON, LAT, STATUT) VALUES ('FictifDeux', 'XA', 5.5, 44.2, 'M')");
        $id = (int) $this->valeur("SELECT ID FROM gm_geoloc WHERE COMMUNE = 'FictifDeux'");
        $client = $this->site->client('niveau9');

        $carte = $client->get('/index.php', ['vue' => 'C'])->corps;
        self::assertStringContainsString('42 Naissances', $carte);
        self::assertStringNotContainsString('30 Naissances', $carte);

        self::assertStringContainsString('42 Naissances', $client->get('/localite.php', ['id' => $id])->corps);
    }

    public function testDeuxCommunesProchesGardentChacuneLeurSauvegarde(): void
    {
        $this->sauvegarder('Fictif-Commune-Un', 'XA');
        $this->sauvegarder('Fictif-Commune-Deux', 'XA');
        self::assertCount(2, glob($this->site->racine . '/tmp/backups/backup_*_Fictif_Commune_*_N.001.bea') ?: []);
    }

    public function testDeuxCommunesAuLongNomGardentChacuneLeurSauvegarde(): void
    {
        $this->sauvegarder('Fictif-Commune-Au-Tres-Long-Nom-Un', 'XA');
        $this->sauvegarder('Fictif-Commune-Au-Tres-Long-Nom-De', 'XA');
        self::assertCount(2, glob($this->site->racine . '/tmp/backups/backup_*_Fictif_Commune_*_N.001.bea') ?: []);
    }

    public function testDeuxHomonymesGardentChacunLeurSauvegarde(): void
    {
        $this->sauvegarder('Fictif-Homonyme', 'XA');
        $this->sauvegarder('Fictif-Homonyme', 'XB');
        self::assertCount(1, glob($this->site->racine . '/tmp/backups/backup_*_Fictif_Homonyme_XA_N.001.bea') ?: []);
        self::assertCount(1, glob($this->site->racine . '/tmp/backups/backup_*_Fictif_Homonyme_XB_N.001.bea') ?: []);
    }

    public function testDeuxTypesDiversDUneCommuneGardentChacunLeurSauvegarde(): void
    {
        $client = $this->site->client('niveau9');
        foreach (['Fictif Un', 'Fictif Deux'] as $libelle) {
            $this->site->base->query("INSERT INTO gm_div3 (COMMUNE, DEPART, TYPACT, LIBELLE, NOM, PRE, DATETXT, LADATE, DEPOSANT) VALUES ('FictifDivers', 'XA', 'V', '$libelle', 'FICTIF', 'Jean', '01/02/1790', '1790-02-01', 1)");
            $client->post('/admin/exporte.php', ['action' => 'go', 'TypeActes' => 'V', 'typdivers' => $libelle, 'Destin' => 'B', 'ComDep' => 'FictifDivers [XA]', 'maxmega' => '1']);
        }
        self::assertCount(2, glob($this->site->racine . '/tmp/backups/backup_*_FictifDivers_XA_*_V.001.bea') ?: []);
    }

    public function testUneSauvegardeEnPlusieursFichiersGardeSonTypeDivers(): void
    {
        foreach (['Fictif A', 'Fictif A', 'Fictif B'] as $libelle) {
            $this->site->base->query("INSERT INTO gm_div3 (COMMUNE, DEPART, TYPACT, LIBELLE, NOM, PRE, DATETXT, LADATE, DEPOSANT) VALUES ('FictifSuite', 'XA', 'V', '$libelle', 'FICTIF', 'Jean', '01/02/1790', '1790-02-01', 1)");
        }
        $this->parametre('MAX_SELECT_REC', '1');
        $client = $this->site->client('niveau9');
        $page = $client->post('/admin/exporte.php', ['action' => 'go', 'TypeActes' => 'V', 'typdivers' => 'Fictif A', 'Destin' => 'B', 'ComDep' => 'FictifSuite [XA]', 'maxmega' => '1']);
        self::assertSame(1, preg_match('/<a href="(exporte\.php\?action=go[^"]+)"/', $page->corps, $lien));
        $suite = $client->suivre('/admin/' . html_entity_decode($lien[1], ENT_QUOTES | ENT_HTML401));
        $this->assertPageSansErreur($suite);

        $fichiers = glob($this->site->racine . '/tmp/backups/backup_*_FictifSuite_XA_Fictif_A_V.*.bea') ?: [];
        self::assertCount(2, $fichiers);
        $contenu = implode('', array_map(static fn (string $f): string => (string) file_get_contents($f), $fichiers));
        self::assertSame(2, substr_count($contenu, 'Fictif A'));
        self::assertStringNotContainsString('Fictif B', $contenu);
        self::assertCount(2, glob($this->site->racine . '/tmp/backups/backup_*_FictifSuite_*.bea') ?: []);
    }

    public function testLaProfessionDuConjointTient35Caracteres(): void
    {
        foreach (['gm_mar3' => 2035, 'gm_dec3' => 3030, 'gm_div3' => 4039] as $table => $zone) {
            self::assertStringStartsWith('varchar(35)', $this->site->base->query("SHOW COLUMNS FROM $table LIKE 'C_PRO'")->fetch_row()[1]);
            self::assertSame('35', $this->valeur("SELECT taille FROM gm_metadb WHERE ZID = $zone"));
        }
        $id = (int) $this->valeur('SELECT MIN(ID) FROM gm_mar3');
        $this->site->base->query("UPDATE gm_mar3 SET C_PRO = '" . str_repeat('a', 35) . "' WHERE ID = $id");
        self::assertSame(35, strlen($this->valeur("SELECT C_PRO FROM gm_mar3 WHERE ID = $id")));
    }

    public function testUnExportTropGrosEstRefuseSansFichierTronque(): void
    {
        $this->ajouterNaissance('FictifExport');
        $this->ajouterNaissance('FictifExport');
        $this->parametre('MAX_SELECT_REC', '1');
        $page = $this->site->client('niveau9')->post('/admin/exporte.php', ['action' => 'go', 'TypeActes' => 'N', 'Destin' => 'T', 'ComDep' => 'FictifExport [XA]']);
        $this->assertPageSansErreur($page);
        self::assertStringContainsString('dépassent', $page->corps);
        self::assertStringNotContainsStringIgnoringCase('content-disposition', $page->entetes);
    }

    public function testUneCommuneAvecCrochetsGardeSonNomEtSonDepartement(): void
    {
        $this->ajouterNaissance('Fictif [bis]');
        $this->site->base->query("INSERT INTO gm_sums (COMMUNE, DEPART, TYPACT, LIBELLE, DEPOSANT, NB_TOT) VALUES ('Fictif [bis]', 'XA', 'N', '', 1, 1)");
        $page = $this->site->client('niveau9')->get('/tab_naiss.php', ['args' => 'Fictif [bis] [XA]']);
        $this->assertPageSansErreur($page);
        self::assertStringContainsString('FICTIF', $page->corps);
    }

    public function testUneVisiteSansNavigateurIdentifieNeLaissePasDAvertissement(): void
    {
        $curl = curl_init('http://127.0.0.1:' . $this->site->port() . '/tab_naiss.php');
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Host: localhost'], CURLOPT_TIMEOUT => 60]);
        self::assertNotFalse(curl_exec($curl));
        $this->assertJournalSansErreur();
    }

    public function testLaPageDePermutationPorteSonTitre(): void
    {
        $page = $this->site->client('niveau9')->get('/admin/permute.php');
        self::assertStringContainsString('&gt; Permutation d&#039;un acte', $page->corps);
        self::assertStringNotContainsString('Suppression d&#039;un acte', $page->corps);
    }

    public function testLEditionDUnActeDiversMontreSonEtiquetteSpecifique(): void
    {
        $id = (int) $this->valeur('SELECT MIN(ID) FROM gm_div3');
        $groupe = $this->valeur("SELECT GROUPE FROM gm_metadb WHERE DTABLE = 'V' AND ZONE = 'C_PRO'");
        $this->site->base->query("UPDATE gm_div3 SET SIGLE = 'FX' WHERE ID = $id");
        $this->site->base->query("INSERT INTO gm_mgrplg (grp, dtable, lg, sigle, getiq) VALUES ('$groupe', 'V', 'fr', 'FX', 'Etiquette fictive')");
        $page = $this->site->client('niveau9')->get('/admin/edit_acte.php', ['xid' => $id, 'xtyp' => 'V']);
        $this->assertPageSansErreur($page);
        self::assertStringContainsString('Etiquette fictive', $page->corps);
    }

    public function testUneEtiquetteSpecifiqueVideeRevientALaValeurParDefaut(): void
    {
        $groupe = $this->valeur("SELECT GROUPE FROM gm_metadb WHERE DTABLE = 'V' AND ZONE = 'C_PRO'");
        $this->site->base->query("INSERT INTO gm_mgrplg (grp, dtable, lg, sigle, getiq) VALUES ('$groupe', 'V', 'fr', 'FX', 'Etiquette fictive')");
        $page = $this->site->client('niveau9')->post('/admin/gest_labels.php', ['file' => 'V', 'xconfirm' => 'specifique', 'chsigle' => '0', 'SIGLE' => 'FX', 'grpnbr' => '1', 'grp_1' => $groupe, 'group_1' => '']);
        $this->assertPageSansErreur($page);
        self::assertSame('0', $this->valeur("SELECT COUNT(*) FROM gm_mgrplg WHERE sigle = 'FX'"));
    }
}
