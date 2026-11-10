<?php

declare (strict_types=1);
namespace ActaLibre\Tests\Install;

use ActaLibre\Install\Verrou;
final class VerrouTest extends BaseInstallation
{
    public function testDeuxConnexionsNePeuventPasInstallerEnsemble(): void
    {
        $autre = self::connexion();
        $v = Verrou::prendre($this->base, 'base', $this->prefixe, 'installation');
        try {
            try {
                Verrou::prendre($autre, 'base', $this->prefixe, 'installation');
                self::fail('Deux verrous pris');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('installation en cours', $e->getMessage());
            }
        } finally {
            $v->liberer();
        }
        Verrou::prendre($autre, 'base', $this->prefixe, 'installation')->liberer();
        $autre->close();
    }
    public function testLeNomLongEstBorneEtDistingueBasesPrefixesEtOperations(): void
    {
        $base = str_repeat('b', 64);
        $prefixe = str_repeat('p', 55);
        $nom = 'actalibre_installation_' . substr(hash('sha256', "{$base}.{$prefixe}_actalibre_installation"), 0, 40);
        $v = Verrou::prendre($this->base, $base, $prefixe, 'installation');
        self::assertNotNull($this->base->query("SELECT IS_USED_LOCK('{$nom}')")->fetch_row()[0]);
        $autre = self::connexion();
        foreach ([[$base . 'x', $prefixe, 'installation'], [$base, $prefixe . 'x', 'installation'], [$base, $prefixe, 'migration']] as [$b, $p, $o]) {
            Verrou::prendre($autre, $b, $p, $o)->liberer();
        }
        $v->liberer();
        $autre->close();
    }
    public function testUneConnexionFermeeLibereLeVerrou(): void
    {
        $autre = self::connexion();
        $v = Verrou::prendre($autre, 'base', $this->prefixe, 'installation');
        $autre->close();
        $repris = Verrou::prendre($this->base, 'base', $this->prefixe, 'installation');
        self::assertInstanceOf(Verrou::class, $repris);
        $repris->liberer();
    }
    public static function bases(): array
    {
        return [['base'], [str_repeat('b', 64)]];
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('bases')]
    public function testLesVariantesDeCassePartagentLeVerrou(string $base): void
    {
        $v = Verrou::prendre($this->base, $base, $this->prefixe, 'installation');
        $autre = self::connexion();
        try {
            try {
                Verrou::prendre($autre, strtoupper($base), strtoupper($this->prefixe), 'installation');
                self::fail('Verrous distincts pour un alias de casse');
            } catch (\RuntimeException $e) {
                self::assertSame('installation en cours', $e->getMessage());
            }
        } finally {
            $v->liberer();
            $autre->close();
        }
    }
}
