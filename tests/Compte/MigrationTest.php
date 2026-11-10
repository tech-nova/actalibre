<?php
declare(strict_types=1);

namespace ActaLibre\Tests\Compte;

use ActaLibre\Compte\Migration;
use ActaLibre\Compte\MotDePasse;
use PHPUnit\Framework\TestCase;

final class MigrationTest extends TestCase
{
    private const COMPTES = ['fictif_public' => 'Test-public-2026', 'fictif_depot' => 'Test-depot-2026', 'fictif_admin' => 'Test-admin-2026'];
    private \mysqli $lien;

    protected function setUp(): void
    {
        $hote = getenv('GM_DB_HOST');
        $nom = getenv('GM_DB_NAME');
        $utilisateur = getenv('GM_DB_USER');
        $passe = getenv('GM_DB_PASS');
        if ($hote === false || $nom === false || $utilisateur === false || $passe === false) {
            self::markTestSkipped('MigrationTest ignoré : définir GM_DB_HOST, GM_DB_NAME, GM_DB_USER et GM_DB_PASS.');
        }
        require_once dirname(__DIR__, 2) . '/tools/EA_sql.inc.php';
        mysqli_report(MYSQLI_REPORT_OFF);
        $this->lien = new \mysqli($hote, $utilisateur, $passe, $nom);
        self::assertTrue($this->lien->query("CREATE TEMPORARY TABLE essai_comptes (ID INT AUTO_INCREMENT PRIMARY KEY, login VARCHAR(15) NOT NULL, hashpass VARCHAR(40) COLLATE latin1_general_ci NOT NULL DEFAULT '') ENGINE=MyISAM"), $this->lien->error);
        foreach (self::COMPTES as $login => $passe) {
            $this->lien->query("INSERT INTO essai_comptes (login, hashpass) VALUES ('$login', '" . sha1($passe) . "')");
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->lien)) {
            $this->lien->close();
        }
    }

    /** @return array<string,string> */
    private function valeurs(): array
    {
        $valeurs = [];
        foreach ($this->lien->query('SELECT login, hashpass FROM essai_comptes')->fetch_all(MYSQLI_ASSOC) as $ligne) {
            $valeurs[$ligne['login']] = $ligne['hashpass'];
        }
        return $valeurs;
    }

    public function testChaqueCompteSeConnecteApresDeuxPassages(): void
    {
        self::assertTrue(Migration::preparerComptes($this->lien, 'essai_comptes'), $this->lien->error);
        self::assertSame(0, Migration::migrerMotsDePasse($this->lien, 'essai_comptes', 30));
        $premier = $this->valeurs();
        foreach (self::COMPTES as $login => $passe) {
            self::assertFalse(MotDePasse::estAncienFormat($premier[$login]));
            self::assertTrue(MotDePasse::verifier($passe, $premier[$login]));
            self::assertFalse(MotDePasse::verifier('mauvais', $premier[$login]));
        }

        self::assertTrue(Migration::preparerComptes($this->lien, 'essai_comptes'));
        self::assertSame(0, Migration::migrerMotsDePasse($this->lien, 'essai_comptes', 30));
        self::assertSame($premier, $this->valeurs(), 'Relancer est sans effet');
    }

    public function testLesLotsSontBornesEtLeRejeuConserveLesEmpreintes(): void
    {
        self::assertTrue(Migration::preparerComptes($this->lien,'essai_comptes'));
        $a=Migration::migrerLot($this->lien,'essai_comptes',0,2);
        self::assertSame(2,$a['dernierId']);self::assertFalse($a['terminee']);
        $hash=$this->valeurs()['fictif_public'];
        $b=Migration::migrerLot($this->lien,'essai_comptes',$a['dernierId'],2);
        self::assertTrue($b['terminee']);
        Migration::migrerLot($this->lien,'essai_comptes',0,2);
        self::assertSame($hash,$this->valeurs()['fictif_public']);
    }
    public function testSansTempsDisponibleIlResteDesComptes(): void
    {
        self::assertTrue(Migration::preparerComptes($this->lien, 'essai_comptes'));
        self::assertSame(3, Migration::migrerMotsDePasse($this->lien, 'essai_comptes', 0));
    }

    public function testUneDateNulleNEmpechePasDePreparerLesComptes(): void
    {
        $mode = $this->lien->query('SELECT @@SESSION.sql_mode')->fetch_row()[0];
        $this->lien->query("SET SESSION sql_mode = ''");
        $this->lien->query('ALTER TABLE essai_comptes ADD dtcreation DATE NULL, ADD dtexpiration DATE NULL');
        $this->lien->query("UPDATE essai_comptes SET dtcreation = '0000-00-00', dtexpiration = '0000-00-00' WHERE login = 'fictif_public'");
        $this->lien->query("UPDATE essai_comptes SET dtcreation = '2020-05-01' WHERE login = 'fictif_depot'");
        $this->lien->query("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE'");
        try {
            self::assertTrue(Migration::preparerComptes($this->lien, 'essai_comptes'), $this->lien->error);
        } finally {
            $this->lien->query("SET SESSION sql_mode = '$mode'");
        }
        $dates = $this->lien->query("SELECT login, dtcreation, dtexpiration FROM essai_comptes ORDER BY login")->fetch_all(MYSQLI_NUM);
        self::assertSame([['fictif_admin', null, null], ['fictif_depot', '2020-05-01', null], ['fictif_public', '1001-01-01', '1001-01-01']], $dates);
    }

    public function testColonneAgrandieEtMoteurTransactionnel(): void
    {
        self::assertTrue(Migration::preparerComptes($this->lien, 'essai_comptes'));
        $creation = $this->lien->query('SHOW CREATE TABLE essai_comptes')->fetch_row()[1];
        self::assertStringContainsString('varchar(255)', $creation);
        self::assertStringContainsString('ENGINE=InnoDB', $creation);
    }

    public function testErreur(): void
    {
        self::assertFalse(Migration::preparerComptes($this->lien, 'essai_absente'));
        self::assertSame(-1, Migration::migrerMotsDePasse($this->lien, 'essai_absente', 30));
    }
}
