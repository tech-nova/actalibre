<?php
declare(strict_types=1);
namespace ActaLibre\Tests\Parametres;
use ActaLibre\Parametres\EditionGroupe;
use PHPUnit\Framework\TestCase;

final class EditionGroupeTest extends TestCase
{
    private \mysqli $db;
    private string $prefixe;
    protected function setUp(): void
    {
        if (getenv('GM_DB_NAME') === false) { self::markTestSkipped('Base de test requise.'); }
        $this->db = new \mysqli(getenv('GM_DB_HOST'), getenv('GM_DB_USER'), getenv('GM_DB_PASS'), getenv('GM_DB_NAME'));
        $this->db->set_charset('utf8mb4');
        $this->prefixe = 'al5e_' . bin2hex(random_bytes(5));
        $this->db->query('CREATE TABLE ' . $this->prefixe . "_params (param varchar(40) PRIMARY KEY, groupe varchar(40), type char(1), valeur text) COLLATE utf8mb4_general_ci");
        $q = $this->db->prepare('INSERT INTO ' . $this->prefixe . '_params VALUES (?,?,?,?)');
        foreach ([['SITENAME', 'Affichage', 'T', 'Avant'], ['AUTRE', 'Affichage', 'B', '1'], ['ACTALIBRE_VERSION', 'Affichage', 'T', '1.0.0'], ['MAIL', 'Mail', 'T', 'prive'], ['EA_VERSION', 'Hidden', 'T', '3.2.4']] as $ligne) { $q->execute($ligne); }
    }
    protected function tearDown(): void
    {
        if (isset($this->db)) { $this->db->query('DROP TABLE ' . $this->prefixe . '_params'); $this->db->close(); }
    }
    private function valeur(string $nom): string
    {
        return $this->db->query('SELECT valeur FROM ' . $this->prefixe . "_params WHERE param='$nom'")->fetch_row()[0];
    }
    public function testUnPostMixteNeModifieQueLaLigneDuGroupe(): void
    {
        self::assertSame(1, EditionGroupe::enregistrer($this->db, $this->prefixe, 'Affichage', [
            ['nom' => 'SITENAME', 'valeur' => 'Après'], ['nom' => 'MAIL', 'valeur' => 'intrus'], ['nom' => 'actalibre_version ', 'valeur' => 'intrus'],
        ]));
        self::assertSame('Apr&egrave;s', $this->valeur('SITENAME'));
        self::assertSame('prive', $this->valeur('MAIL'));
        self::assertSame('1.0.0', $this->valeur('ACTALIBRE_VERSION'));
    }
    public function testRefuseLesGroupesNonEditablesEtConserveLesConversions(): void
    {
        foreach (['Hidden', 'Deleted', 'inexistant', 'affichage', 'Affichage '] as $g) {
            self::assertSame(0, EditionGroupe::enregistrer($this->db, $this->prefixe, $g, [['nom' => 'EA_VERSION', 'valeur' => 'intrus']]));
        }
        self::assertSame(2, EditionGroupe::enregistrer($this->db, $this->prefixe, 'Affichage', [['nom' => 'AUTRE', 'valeur' => ''], ['nom' => 'sitename ', 'valeur' => ' <b> & ']]));
        self::assertSame('0', $this->valeur('AUTRE'));
        self::assertSame('&lt;b&gt; &amp;', $this->valeur('SITENAME'));
    }
    /** Les tables livrées sont en latin1, la page est en UTF-8 : le nom accentué n'a pas les mêmes octets. */
    #[\PHPUnit\Framework\Attributes\DataProvider('jeux')]
    public function testEnregistreUnGroupeAccentue(string $jeuTable, string $jeuConnexion): void
    {
        $this->db->query('ALTER TABLE ' . $this->prefixe . "_params CONVERT TO CHARACTER SET $jeuTable");
        $this->db->set_charset($jeuConnexion);
        $this->db->query('INSERT INTO ' . $this->prefixe . "_params VALUES ('GEO_MODE_PUBLIC', 'Géoloc', 'L', '2')");
        self::assertSame(0, EditionGroupe::enregistrer($this->db, $this->prefixe, 'Geoloc', [['nom' => 'GEO_MODE_PUBLIC', 'valeur' => '1']]));
        self::assertSame(1, EditionGroupe::enregistrer($this->db, $this->prefixe, 'Géoloc', [['nom' => 'GEO_MODE_PUBLIC', 'valeur' => '1']]));
        self::assertSame('1', $this->valeur('GEO_MODE_PUBLIC'));
    }
    /** @return iterable<string,array{string,string}> */
    public static function jeux(): iterable
    {
        foreach (['latin1', 'utf8mb4'] as $table) {
            foreach (['utf8', 'utf8mb4'] as $connexion) { yield "$table, connexion $connexion" => [$table, $connexion]; }
        }
    }
}
