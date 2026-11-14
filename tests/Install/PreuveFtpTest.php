<?php
declare(strict_types=1);
namespace ActaLibre\Tests\Install;

use ActaLibre\Install\PreuveFtp;
use PHPUnit\Framework\TestCase;

final class PreuveFtpTest extends TestCase
{
    private string $racine;
    protected function setUp(): void
    {
        $this->racine = sys_get_temp_dir() . '/preuve_' . bin2hex(random_bytes(6));
        mkdir($this->racine . '/_config', 0777, true);
    }
    protected function tearDown(): void
    {
        BaseInstallation::supprimer($this->racine);
    }
    public function testLeNomEstStableEtAleatoirePourUneSession(): void
    {
        $session = [];
        $nom = PreuveFtp::nom($session, 1000);
        self::assertMatchesRegularExpression('/\Aadmin-[0-9a-f]{32}\.txt\z/', $nom);
        self::assertSame($nom, PreuveFtp::nom($session, 1000 + PreuveFtp::DUREE - 1));
        $autre = [];
        self::assertNotSame($nom, PreuveFtp::nom($autre, 1000));
    }
    public function testLaPreuveExigeLeFichierDeCetteSession(): void
    {
        $session = [];
        $nom = PreuveFtp::nom($session, 1000);
        self::assertFalse(PreuveFtp::presente($this->racine, $session, 1000));
        $autre = [];
        touch($this->racine . '/_config/' . PreuveFtp::nom($autre, 1000));
        self::assertFalse(PreuveFtp::presente($this->racine, $session, 1000));
        touch($this->racine . '/_config/' . $nom);
        self::assertTrue(PreuveFtp::presente($this->racine, $session, 1000));
        self::assertFalse(PreuveFtp::presente($this->racine, [], 1000));
    }
    public function testLaPreuveExpireEtLeNomEstRenouvele(): void
    {
        $session = [];
        $nom = PreuveFtp::nom($session, 1000);
        touch($this->racine . '/_config/' . $nom);
        self::assertFalse(PreuveFtp::presente($this->racine, $session, 1000 + PreuveFtp::DUREE));
        self::assertNotSame($nom, PreuveFtp::nom($session, 1000 + PreuveFtp::DUREE));
    }
    public function testUnLienOuUnDossierNeSontPasUnePreuve(): void
    {
        $session = [];
        $nom = PreuveFtp::nom($session, 1000);
        touch($this->racine . '/cible');
        symlink($this->racine . '/cible', $this->racine . '/_config/' . $nom);
        self::assertFalse(PreuveFtp::presente($this->racine, $session, 1000));
        unlink($this->racine . '/_config/' . $nom);
        mkdir($this->racine . '/_config/' . $nom);
        self::assertFalse(PreuveFtp::presente($this->racine, $session, 1000));
    }
    public function testUneSessionAltereeEstIgnoree(): void
    {
        $session = ['preuve_ftp' => ['nom' => '../connect.inc.php', 'debut' => 1000]];
        self::assertFalse(PreuveFtp::presente($this->racine, $session, 1000));
        PreuveFtp::consommer($this->racine, $session);
        self::assertSame([], $session);
    }
    public function testLaConsommationRetireLeFichierEtLeNom(): void
    {
        $session = [];
        $nom = PreuveFtp::nom($session, time());
        touch($this->racine . '/_config/' . $nom);
        PreuveFtp::consommer($this->racine, $session);
        self::assertFileDoesNotExist($this->racine . '/_config/' . $nom);
        self::assertFalse(PreuveFtp::presente($this->racine, $session));
    }
}
