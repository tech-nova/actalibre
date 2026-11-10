<?php
declare(strict_types=1);

namespace ActaLibre\Tests\Donnees;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JeuDeDonneesTest extends TestCase
{
    private const SQL = __DIR__ . '/../../install/creetables.sql';

    /** @return array<string,string> */
    private static function fichiers(): array
    {
        return (new GenerateurDonnees(self::SQL))->fichiers();
    }

    /** @return list<array<string,string>> une ligne par acte, indexée par zone */
    private static function actesBea(string $type): array
    {
        $colonnes = GenerateurDonnees::colonnes($type);
        $actes = [];
        foreach (explode("\r\n", rtrim(self::fichiers()["actes_{$type}.001.bea"])) as $ligne) {
            $champs = str_getcsv($ligne, ';', '"', '');
            self::assertCount(count($colonnes) + 1, $champs, 'colonnes + « ; » final');
            self::assertSame('', array_pop($champs));
            $actes[] = array_combine($colonnes, $champs);
        }
        return $actes;
    }

    /** @return array<string,array{string}> */
    public static function types(): array
    {
        return ['N' => ['N'], 'M' => ['M'], 'D' => ['D'], 'V' => ['V']];
    }

    public function testDeuxGenerationsSontIdentiques(): void
    {
        self::assertSame(self::fichiers(), self::fichiers());
    }

    public function testListeDesFichiers(): void
    {
        self::assertSame(
            ['actes_N.001.bea', 'actes_M.001.bea', 'actes_D.001.bea', 'actes_V.001.bea', 'mariages.csv', 'utilisateurs.csv'],
            array_keys(self::fichiers())
        );
    }

    #[DataProvider('types')]
    public function testVolumeEnTeteEtType(string $type): void
    {
        $actes = self::actesBea($type);
        self::assertCount(GenerateurDonnees::ACTES_PAR_TYPE, $actes);
        foreach ($actes as $acte) {
            self::assertSame('EA32', $acte['BIDON']);
            self::assertSame($type, $acte['TYPACT']);
        }
    }

    #[DataProvider('types')]
    public function testValeursTiennentDansLesColonnes(string $type): void
    {
        $table = ['N' => 'nai3', 'M' => 'mar3', 'D' => 'dec3', 'V' => 'div3'][$type];
        $largeurs = GenerateurDonnees::lireLargeurs((string) file_get_contents(self::SQL))[$table];
        self::assertArrayHasKey('NOM', $largeurs);
        foreach (self::actesBea($type) as $acte) {
            foreach ($largeurs as $zone => $max) {
                self::assertLessThanOrEqual($max, mb_strlen($acte[$zone] ?? ''), $zone);
            }
        }
    }

    #[DataProvider('types')]
    public function testCasLimites(string $type): void
    {
        $actes = self::actesBea($type);
        self::assertSame("D'ARCY", $actes[0]['NOM']);
        self::assertSame('Dit "le Grand" ; présent à l\'acte', $actes[1]['COMGEN']);
        self::assertSame('??/??/1790', $actes[2]['DATETXT']);
        self::assertSame('Catherine ?', $actes[3]['PRE']);
        self::assertSame('', $actes[4]['PRE']);
        self::assertSame(30, mb_strlen($actes[5]['NOM']));
        self::assertSame(35, mb_strlen($actes[5]['PRE']));
        self::assertSame('Âge < 30 ans & sans profession', $actes[6]['COMGEN']);
        self::assertSame('ŒUVRARD', $actes[7]['NOM']);
    }

    public function testPlusieursCommunesParType(): void
    {
        foreach (array_keys(self::types()) as $type) {
            self::assertCount(6, array_unique(array_column(self::actesBea($type), 'COMMUNE')));
        }
    }

    public function testDatesRepublicainesPresentes(): void
    {
        $avecDrepub = array_filter(array_column(self::actesBea('N'), 'DREPUB'));
        self::assertNotEmpty($avecDrepub);
    }

    public function testCsvNimegue(): void
    {
        $lignes = explode("\r\n", rtrim(self::fichiers()['mariages.csv']));
        self::assertCount(GenerateurDonnees::MARIAGES_CSV, $lignes);
        foreach ($lignes as $ligne) {
            $champs = explode(';', $ligne);
            self::assertCount(61, $champs);
            self::assertSame('NIMEGUEV3', $champs[0]);
            self::assertSame('M', $champs[5]);
            self::assertGreaterThan(0, (int) $champs[59], 'IDNIM');
            self::assertSame('', $champs[60], 'PHOTOS en fin de ligne');
        }
    }

    public function testCsvMeleLatin1EtCp850(): void
    {
        $contenu = self::fichiers()['mariages.csv'];
        self::assertStringContainsString("C\xF4te-Sauvage", $contenu, 'département en latin-1');
        self::assertStringContainsString("Pontar\x8Aze", $contenu, 'commune en CP850');
        self::assertFalse(mb_check_encoding($contenu, 'UTF-8'));
    }

    /** admin/chargecsv.php perd toute ligne que iconv ne peut pas lire en Windows-1252 (ea_utf8_encode). */
    public function testCsvLisibleParImport(): void
    {
        foreach (explode("\r\n", rtrim(self::fichiers()['mariages.csv'])) as $numero => $ligne) {
            self::assertNotFalse(@iconv('Windows-1252', 'UTF-8', $ligne), "ligne $numero");
        }
    }

    public function testComptes(): void
    {
        $lignes = explode("\r\n", rtrim(self::fichiers()['utilisateurs.csv']));
        self::assertCount(3, $lignes);
        $niveaux = [];
        foreach ($lignes as $ligne) {
            $champs = explode(';', $ligne);
            self::assertCount(16, $champs);
            self::assertStringEndsWith('@example.org', $champs[2]);
            $niveaux[(int) $champs[11]] = (int) $champs[5];
        }
        self::assertSame([11 => 1, 16 => 6, 19 => 9], $niveaux);
    }

    public function testValeurTropLongueRefusee(): void
    {
        $this->expectException(\LengthException::class);
        (new GenerateurDonnees(self::SQL))->verifierLargeurs('N', ['NOM' => str_repeat('X', 31)]);
    }

    public function testFichiersVersionnesAJour(): void
    {
        foreach (self::fichiers() as $nom => $contenu) {
            self::assertFileExists(__DIR__ . '/' . $nom);
            self::assertSame($contenu, file_get_contents(__DIR__ . '/' . $nom), $nom);
        }
    }
}
