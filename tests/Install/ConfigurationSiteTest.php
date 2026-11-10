<?php
declare(strict_types=1);
namespace ActaLibre\Tests\Install;

use ActaLibre\Install\ConfigurationSite;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\DataProvider;

#[RunTestsInSeparateProcesses]
final class ConfigurationSiteTest extends BaseInstallation
{
    private function ecrire(string $fichier = 'connect.inc.php', string $supplement = ''): void
    {
        if (!is_dir($this->racine . '/_config')) mkdir($this->racine . '/_config');
        $v = ['addr' => getenv('GM_DB_HOST'), 'name' => getenv('GM_DB_NAME'), 'user' => getenv('GM_DB_USER'), 'pass' => getenv('GM_DB_PASS')];
        $php = '<?php define("EA_DB", ' . var_export($this->prefixe, true) . ');';
        foreach ($v as $k => $valeur) $php .= '$db' . $k . '=' . var_export($valeur, true) . ';';
        file_put_contents($this->racine . '/_config/' . $fichier, $php . $supplement);
    }
    public function testUneAbsenceReelleSeuleAutoriseLInstallation(): void
    {
        self::assertNull(ConfigurationSite::charger($this->racine, 'localhost'));
        $this->ecrire('BD-autre-connect.inc.php');
        $this->expectException(\RuntimeException::class);
        ConfigurationSite::charger($this->racine, 'localhost');
    }
    public function testUnFichierVideEstInvalide(): void
    {
        mkdir($this->racine . '/_config');
        file_put_contents($this->racine . '/_config/connect.inc.php', '');
        $this->expectException(\RuntimeException::class);
        ConfigurationSite::charger($this->racine, 'localhost');
    }
    public function testLeFichierSpecifiqueEstPrioritaire(): void
    {
        $this->ecrire('BD-localhost-connect.inc.php');
        file_put_contents($this->racine . '/_config/connect.inc.php', '<?php throw new Exception("non sélectionné");');
        $c = ConfigurationSite::charger($this->racine, 'www.localhost');
        self::assertSame($this->prefixe, $c->actes->prefixe);
        self::assertStringEndsWith('/BD-localhost-connect.inc.php', $c->fichier);
    }
    public function testUnHoteMalFormeEstRefuse(): void
    {
        $this->expectException(\RuntimeException::class);
        ConfigurationSite::charger($this->racine, '../ailleurs');
    }
    public function testUneConfigurationComptesPartielleEstRefusee(): void
    {
        $this->ecrire(supplement: '$udbname="actalibre2";');
        $this->expectException(\RuntimeException::class);
        ConfigurationSite::charger($this->racine, 'localhost');
    }
    public static function champsComptes(): iterable
    {
        foreach (['addr','name','user','pass','prefixe'] as $champ) yield $champ => [$champ];
    }
    #[DataProvider('champsComptes')]
    public function testChaqueChampComptesManquantEstUneErreur(string $manquant): void
    {
        $s = '';
        if ($manquant !== 'prefixe') $s .= 'define("EA_UDB", "comptes");';
        foreach (['addr'=>'HOST','name'=>'NAME_COMPTES','user'=>'USER','pass'=>'PASS'] as $k=>$env) {
            if ($k !== $manquant) $s .= '$udb'.$k.'='.var_export(getenv('GM_DB_'.$env),true).';';
        }
        $this->ecrire(supplement:$s);
        $this->expectException(\RuntimeException::class);
        ConfigurationSite::charger($this->racine, 'localhost');
    }
    public function testLePerimetreCommunReutiliseLaConnexionSansEcrire(): void
    {
        $this->ecrire(); $avant = $this->empreinte();
        $c = ConfigurationSite::charger($this->racine, 'localhost');
        $liens = $c->ouvrir();
        self::assertSame($liens->actes, $liens->comptes);
        $liens->fermer();
        self::assertSame($avant, $this->empreinte());
    }
    public function testLesComptesOuvrentLaSecondeBasePhysique(): void
    {
        $s = 'define("EA_UDB", "' . $this->prefixe . 'u");';
        foreach (['addr'=>'HOST','name'=>'NAME_COMPTES','user'=>'USER','pass'=>'PASS'] as $k=>$env) $s .= '$udb'.$k.'='.var_export(getenv('GM_DB_'.$env),true).';';
        $this->ecrire(supplement: $s);
        $liens = ConfigurationSite::charger($this->racine, 'localhost')->ouvrir();
        try {
            self::assertNotSame($liens->actes, $liens->comptes);
            self::assertSame('actalibre2', $liens->comptes->query('SELECT DATABASE()')->fetch_row()[0]);
        } finally { $liens->fermer(); }
    }
}
