<?php
declare(strict_types=1);
namespace ActaLibre\Tests\Parametres;
use ActaLibre\Parametres\Sauvegarde;
use ActaLibre\Parametres\Restauration;
use PHPUnit\Framework\TestCase;
final class RestaurationTest extends TestCase
{
    private \mysqli $db;
    private string $prefixe;
    private string $fichier;
    protected function setUp(): void
    {
        if (getenv('GM_DB_NAME') === false) { self::markTestSkipped('Base de test requise.'); }
        $this->db = new \mysqli(getenv('GM_DB_HOST'), getenv('GM_DB_USER'), getenv('GM_DB_PASS'), getenv('GM_DB_NAME'));
        $this->db->set_charset('utf8mb4');
        $this->prefixe = 'al5r_' . bin2hex(random_bytes(5));
        $schema = file_get_contents(dirname(__DIR__, 2).'/install/creetables.sql');
        foreach (['params','metadb','metalg','mgrplg'] as $famille) {
            preg_match('/CREATE TABLE IF NOT EXISTS EA_DB_'.$famille.' \(.*?\) ENGINE=.*?;/s', $schema, $match);
            $this->db->query(str_replace('EA_DB_'.$famille, $this->prefixe.'_'.$famille, $match[0]));
        }
        $this->fichier = tempnam(sys_get_temp_dir(), 'al5-restore');
    }
    protected function tearDown(): void
    {
        if (!isset($this->db)) { return; }
        foreach (['params','metadb','metalg','mgrplg'] as $f) { $this->db->query('DROP TABLE '.$this->prefixe.'_'.$f); }
        $this->db->close(); unlink($this->fichier);
    }
    private function preparer(string $xml): Restauration
    {
        file_put_contents($this->fichier, '<expoactes>'.$xml.'</expoactes>');
        $r = new Restauration($this->db, $this->prefixe);
        $r->preparer(Sauvegarde::lire($this->fichier), true);
        return $r;
    }
    private function groupe(string $sigle, string $texte): string
    {
        return '<gm_mgrplg><grp>1</grp><dtable>N</dtable><lg>fr</lg><sigle>'.$sigle.'</sigle><getiq>'.$texte.'</getiq></gm_mgrplg>';
    }
    public function testRetrouveNullSansLeTransformerEtPeutRejouer(): void
    {
        $this->db->query('INSERT INTO '.$this->prefixe."_mgrplg (grp,dtable,lg,sigle,getiq) VALUES (1,'N','fr',NULL,'Avant')");
        $xml = $this->groupe('', 'Après');
        self::assertSame(['ajouts'=>0,'modifications'=>1], $this->preparer($xml)->appliquer());
        $ligne = $this->db->query('SELECT sigle,getiq FROM '.$this->prefixe.'_mgrplg')->fetch_assoc();
        self::assertNull($ligne['sigle']); self::assertSame('Après', $ligne['getiq']);
        self::assertSame(['ajouts'=>0,'modifications'=>0], $this->preparer($xml)->appliquer());
    }
    public function testAmbiguiteEstRefuseeAvantTouteEcriture(): void
    {
        $this->db->query('INSERT INTO '.$this->prefixe."_mgrplg (grp,dtable,lg,sigle,getiq) VALUES (1,'N','fr',NULL,'A'),(1,'N','fr','','B')");
        try {
            $this->preparer('<gm_params><param>SITENAME</param><groupe>Affichage</groupe><ordre>1</ordre><type>T</type><valeur>Nouveau</valeur><listval/><libelle/><aide/></gm_params>'.$this->groupe('', 'Intrus'));
            self::fail('Ambiguïté acceptée');
        } catch (\RuntimeException) {
            self::assertSame('0', $this->db->query('SELECT COUNT(*) FROM '.$this->prefixe.'_params')->fetch_row()[0]);
        }
    }
    public function testInsereVideEtRefuseLesClesDupliquees(): void
    {
        self::assertSame(1, $this->preparer($this->groupe('', 'Texte'))->appliquer()['ajouts']);
        self::assertSame('', $this->db->query('SELECT sigle FROM '.$this->prefixe.'_mgrplg')->fetch_row()[0]);
        $this->expectException(\RuntimeException::class);
        $this->preparer($this->groupe('', 'A').$this->groupe('', 'B'));
    }
    public function testConserveProvenanceAdresseEtParametresCaches(): void
    {
        $q = $this->db->prepare('INSERT INTO '.$this->prefixe."_params (param,groupe,ordre,type,valeur,listval,libelle,aide) VALUES (?, ?,1,'T',?,'','','')");
        foreach ([['URL_PUBLIQUE','Mail','https://local.invalid/'], ['EA_VERSION','Hidden','3.2.4'], ['CACHE','Hidden','local']] as $v) { $q->execute($v); }
        $xml = '';
        foreach (['URL_PUBLIQUE','EA_VERSION','CACHE','actalibre_version '] as $nom) {
            $xml .= '<gm_params><param>'.$nom.'</param><groupe>Affichage</groupe><ordre>1</ordre><type>T</type><valeur>intrus</valeur><listval/><libelle/><aide/></gm_params>';
        }
        $avant = $this->db->query('SELECT * FROM '.$this->prefixe.'_params ORDER BY param')->fetch_all(MYSQLI_ASSOC);
        $this->preparer($xml)->appliquer();
        $apres = $this->db->query('SELECT * FROM '.$this->prefixe.'_params ORDER BY param')->fetch_all(MYSQLI_ASSOC);
        self::assertSame('https://local.invalid/', $apres[2]['valeur']);
        self::assertSame($avant[0], $apres[0]);
        self::assertSame($avant[1], $apres[1]);
        self::assertCount(3, $apres);
        $this->db->query('DELETE FROM '.$this->prefixe."_params WHERE param='EA_VERSION'");
        $this->preparer($xml)->appliquer();
        self::assertSame('0', $this->db->query('SELECT COUNT(*) FROM '.$this->prefixe."_params WHERE param='EA_VERSION'")->fetch_row()[0]);
    }
    public function testArreteSurErreurSqlSansAnnoncerUnSucces(): void
    {
        $r = $this->preparer($this->groupe('', 'A'));
        $this->db->query('ALTER TABLE '.$this->prefixe.'_mgrplg DROP COLUMN getiq');
        $this->expectException(\RuntimeException::class);
        $r->appliquer();
    }
    public function testRefuseDeuxClesEquivalentesSelonLaCollationAvantEcriture(): void
    {
        $this->db->query('ALTER TABLE '.$this->prefixe.'_mgrplg MODIFY sigle VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL');
        $this->expectException(\RuntimeException::class);
        $this->preparer($this->groupe('e', 'A').$this->groupe('é', 'B'));
    }

}
