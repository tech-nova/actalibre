<?php
declare(strict_types=1);

namespace ActaLibre\Tests\Install;

use ActaLibre\Install\InstallGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InstallGuardTest extends TestCase
{
    private string $config;

    protected function setUp(): void
    {
        $this->config = sys_get_temp_dir() . '/actalibre-config-' . bin2hex(random_bytes(4));
        mkdir($this->config);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->config . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->config);
    }

    public function testSansConfigurationLeSiteEstNonInstalle(): void
    {
        self::assertSame(InstallGuard::NON_INSTALLE, InstallGuard::etat($this->config));
    }

    public function testUnFichierDeConnexionPresentSuffitQuelQueSoitSonContenu(): void
    {
        file_put_contents($this->config . '/connect.inc.php', '<?php $dbpass = "a@@b";');
        self::assertSame(InstallGuard::INSTALLE, InstallGuard::etat($this->config));
    }

    public function testUneConfigurationRenseigneeEstInstallee(): void
    {
        file_put_contents($this->config . '/connect.inc.php', '<?php $dbaddr = "localhost";');
        self::assertSame(InstallGuard::INSTALLE, InstallGuard::etat($this->config));
    }

    public function testUnFichierParServeurSeulSuffitAMarquerLInstallation(): void
    {
        file_put_contents($this->config . '/BD-exemple-org-connect.inc.php', '<?php $dbaddr = "db";');
        self::assertSame(InstallGuard::INSTALLE, InstallGuard::etat($this->config));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function nomsHote(): iterable
    {
        yield 'domaine' => ['www.exemple.org', true];
        yield 'local' => ['localhost', true];
        yield 'ipv4' => ['127.0.0.1', true];
        yield 'ipv6' => ['[::1]', true];
        yield 'double point' => ['a..b', false];
        yield 'tirets' => ['mon-site.exemple.fr', true];
        yield 'vide' => ['', false];
        yield 'remontée' => ['../../tmp', false];
        yield 'slash' => ['a/b', false];
        yield 'octet nul' => ["a\0b", false];
        yield 'espace' => ['a b', false];
        yield 'trop long' => [str_repeat('a', 254), false];
    }

    #[DataProvider('nomsHote')]
    public function testNomHoteValide(string $nom, bool $attendu): void
    {
        self::assertSame($attendu, InstallGuard::nomHoteValide($nom));
    }
}
