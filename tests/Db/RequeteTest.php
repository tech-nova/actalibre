<?php
declare(strict_types=1);

namespace ActaLibre\Tests\Db;

use ActaLibre\Db\Requete;
use PHPUnit\Framework\TestCase;

final class RequeteTest extends TestCase
{
    private \mysqli $lien;

    protected function setUp(): void
    {
        $hote = getenv('GM_DB_HOST');
        $nom = getenv('GM_DB_NAME');
        $utilisateur = getenv('GM_DB_USER');
        $passe = getenv('GM_DB_PASS');
        if ($hote === false || $nom === false || $utilisateur === false || $passe === false) {
            self::markTestSkipped('RequeteTest ignoré : définir GM_DB_HOST, GM_DB_NAME, GM_DB_USER et GM_DB_PASS.');
        }
        require_once dirname(__DIR__, 2) . '/tools/EA_sql.inc.php';
        mysqli_report(MYSQLI_REPORT_OFF);
        $this->lien = new \mysqli($hote, $utilisateur, $passe, $nom);
        $this->lien->set_charset('utf8mb4');
        self::assertTrue(
            $this->lien->query('CREATE TEMPORARY TABLE essai_requete (ID INT PRIMARY KEY, NOM VARCHAR(40) NULL, LON FLOAT NULL)'),
            $this->lien->error
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->lien)) {
            $this->lien->close();
        }
    }

    private function inserer(int $id, ?string $nom, ?float $lon = null): void
    {
        self::assertTrue(Requete::executer($this->lien, 'INSERT INTO essai_requete VALUES (?, ?, ?)', [$id, $nom, $lon]));
    }

    public function testLectureSansMarqueur(): void
    {
        $this->inserer(1, 'Orméane');
        $resultat = Requete::executer($this->lien, 'SELECT NOM FROM essai_requete');
        self::assertInstanceOf(\mysqli_result::class, $resultat);
        self::assertSame(1, EA_sql_num_rows($resultat));
        self::assertSame('Orméane', EA_sql_fetch_array($resultat)['NOM']);
    }

    /** @return array<string,array{string}> */
    public static function textes(): array
    {
        return ['apostrophe' => ["D'Arc"], 'pourcent' => ['50%'], 'accents' => ['Étienne'], 'guillemets' => ['a"b\\c']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('textes')]
    public function testTexteConserve(string $texte): void
    {
        $this->inserer(1, $texte);
        $this->inserer(2, 'autre');
        $resultat = Requete::executer($this->lien, 'SELECT ID FROM essai_requete WHERE NOM = ?', [$texte]);
        self::assertInstanceOf(\mysqli_result::class, $resultat);
        self::assertSame(1, EA_sql_num_rows($resultat));
        self::assertSame(1, EA_sql_fetch_row($resultat)[0]);
    }

    public function testValeurNulle(): void
    {
        $this->inserer(1, null);
        $resultat = Requete::executer($this->lien, 'SELECT NOM FROM essai_requete WHERE ID = ?', [1]);
        self::assertInstanceOf(\mysqli_result::class, $resultat);
        self::assertNull(EA_sql_fetch_assoc($resultat)['NOM']);
    }

    public function testTypesNatifs(): void
    {
        $this->inserer(7, 'x', 2.35);
        $resultat = Requete::executer($this->lien, 'SELECT ID, LON FROM essai_requete WHERE ID = ?', [7]);
        self::assertInstanceOf(\mysqli_result::class, $resultat);
        $ligne = EA_sql_fetch_assoc($resultat);
        self::assertSame(7, $ligne['ID'], 'Une requête préparée rend les entiers avec leur type natif.');
        self::assertSame('2.35', (string) $ligne['LON'], 'Pour cette valeur, le texte reste celui de EA_sql_query().');
    }

    public function testNombreDeLignesModifiees(): void
    {
        $this->inserer(1, 'a');
        $this->inserer(2, 'b');
        self::assertTrue(Requete::executer($this->lien, 'UPDATE essai_requete SET NOM = ?', ['c']));
        self::assertSame(2, EA_sql_affected_rows($this->lien));
    }

    public function testErreurDExecution(): void
    {
        $this->inserer(1, 'a');
        self::assertFalse(Requete::executer($this->lien, 'INSERT INTO essai_requete VALUES (?, ?, ?)', [1, 'b', null]));
        self::assertStringContainsString('Duplicate', EA_sql_error($this->lien));
    }

    public function testRequeteInvalide(): void
    {
        self::assertFalse(Requete::executer($this->lien, 'SELECT ABSENTE FROM essai_requete'));
        self::assertStringContainsString('ABSENTE', EA_sql_error($this->lien));
    }

    public function testDeuxResultatsLisiblesEnParallele(): void
    {
        $this->inserer(1, 'a');
        $this->inserer(2, 'b');
        $premier = Requete::executer($this->lien, 'SELECT NOM FROM essai_requete WHERE ID = ?', [1]);
        $second = Requete::executer($this->lien, 'SELECT NOM FROM essai_requete WHERE ID = ?', [2]);
        self::assertInstanceOf(\mysqli_result::class, $premier);
        self::assertInstanceOf(\mysqli_result::class, $second);
        self::assertSame('a', EA_sql_fetch_row($premier)[0]);
        self::assertSame('b', EA_sql_fetch_row($second)[0]);
    }

    public function testMauvaisNombreDeValeurs(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Requete::executer($this->lien, 'SELECT NOM FROM essai_requete WHERE ID = ?', []);
    }
}
