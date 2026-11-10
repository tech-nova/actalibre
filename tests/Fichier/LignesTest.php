<?php
declare(strict_types=1);

namespace ActaLibre\Tests\Fichier;

use ActaLibre\Fichier\Lignes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LignesTest extends TestCase
{
    private string $fichier;

    protected function setUp(): void
    {
        $this->fichier = (string) tempnam(sys_get_temp_dir(), 'lignes');
    }

    protected function tearDown(): void
    {
        @unlink($this->fichier);
    }

    /** @return array<string,array{string}> */
    public static function contenus(): array
    {
        return [
            'vide' => [''],
            'une ligne sans fin' => ['a;b'],
            'fin de ligne finale' => ["a;b\nc;d\n"],
            'fins Windows' => ["a;b\r\nc;d\r\n"],
            'lignes vides' => ["\n\na\n\n"],
        ];
    }

    #[DataProvider('contenus')]
    public function testSeLitCommeFile(string $contenu): void
    {
        file_put_contents($this->fichier, $contenu);
        $attendu = file($this->fichier);
        $lignes = new Lignes($this->fichier);

        self::assertSame($attendu, iterator_to_array($lignes));
        self::assertCount(count($attendu), $lignes);
        foreach ($attendu as $rang => $ligne) {
            self::assertTrue(isset($lignes[$rang]));
            self::assertSame($ligne, $lignes[$rang]);
        }
        self::assertFalse(isset($lignes[count($attendu)]));
        self::assertNull($lignes[count($attendu)]);
    }

    public function testUnAccesDansLeDesordreRendLaBonneLigne(): void
    {
        file_put_contents($this->fichier, "zero\nun\ndeux\ntrois\n");
        $lignes = new Lignes($this->fichier);
        self::assertSame("trois\n", $lignes[3]);
        self::assertSame("un\n", $lignes[1]);
        self::assertSame("deux\n", $lignes[2]);
    }

    public function testUnRangEcritEnChiffresSeLitCommeDansFile(): void
    {
        file_put_contents($this->fichier, "zero\nun\n");
        $lignes = new Lignes($this->fichier);
        self::assertSame(file($this->fichier)['1'], $lignes['1']);
        self::assertTrue(isset($lignes['1']));
        self::assertNull($lignes['un']);
        self::assertNull($lignes['-1']);
        self::assertNull($lignes[-1]);
    }

    public function testUnFichierAbsentNeDonneAucuneLigne(): void
    {
        $lignes = new Lignes($this->fichier . '-absent');
        self::assertSame([], iterator_to_array($lignes));
        self::assertCount(0, $lignes);
        self::assertNull($lignes[0]);
    }

    public function testUnGrosFichierNeTientPasEnMemoire(): void
    {
        $sortie = fopen($this->fichier, 'w');
        $ligne = str_repeat('x', 99) . "\n";
        for ($i = 0; $i < 200000; $i++) {
            fwrite($sortie, $ligne);
        }
        fclose($sortie);
        $avant = memory_get_usage();
        $lignes = new Lignes($this->fichier);
        $total = 0;
        $pic = 0;
        foreach ($lignes as $texte) {
            $total += strlen($texte);
            $pic = max($pic, memory_get_usage() - $avant);
        }
        self::assertSame(20000000, $total);
        self::assertSame(200000, count($lignes));
        self::assertLessThan(1024 * 1024, $pic);
    }
}
