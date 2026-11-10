<?php
declare(strict_types=1);
namespace ActaLibre\Tests\Install;
use ActaLibre\Install\DiagnosticInstallation;
use ActaLibre\Install\RapportProtection;
final class ResumeProtectionTest extends BaseDiagnostic
{
    private const SONDES = ['public','tmp','tmp_php','configuration','css','installation','depot','composer','inventaire'];
    private \DateTimeImmutable $maintenant;
    private string $zone;
    protected function setUp(): void
    {
        parent::setUp();
        $this->zone = date_default_timezone_get();
        date_default_timezone_set('Europe/Paris');
        $this->maintenant = new \DateTimeImmutable('2026-09-21 09:30:00');
    }
    protected function tearDown(): void
    {
        date_default_timezone_set($this->zone);
        parent::tearDown();
    }
    private function resume(): array
    {
        return DiagnosticInstallation::resume($this->root, 'base', '_upload', $this->maintenant);
    }
    public function testSansRapportNiFichierInconnu(): void
    {
        self::assertSame(['protection'=>'État : non contrôlé','fichiers'=>'Aucun chemin inconnu détecté le 21/09/2026'], $this->resume());
    }
    public function testRapportVerifieDateEnHeureLocale(): void
    {
        $sondes = array_fill_keys(self::SONDES, ['etat'=>'verifie','http'=>403,'cause'=>null]);
        // 23 h 30 UTC le 14 correspond au 15 à Paris.
        self::assertTrue((new RapportProtection('verifie','1.0.0','http://localhost/',$sondes,[],'2026-09-14T23:30:00Z'))->enregistrer($this->root));
        self::assertSame('État : vérifiée le 15/09/2026', $this->resume()['protection']);
    }
    public function testRapportIndetermine(): void
    {
        $sondes = array_fill_keys(self::SONDES, ['etat'=>'indetermine','http'=>null,'cause'=>'public']);
        self::assertTrue((new RapportProtection('indetermine','1.0.0','http://localhost/',$sondes,[],'2026-09-15T08:00:00Z'))->enregistrer($this->root));
        self::assertSame('État : indéterminée le 15/09/2026', $this->resume()['protection']);
    }
    public function testCheminsInconnusAuSingulierEtAuPluriel(): void
    {
        $this->poser('inconnu.txt', 'x');
        self::assertSame('1 chemin inconnu détecté le 21/09/2026', $this->resume()['fichiers']);
        $this->poser('autre/fichier.php', 'x');
        self::assertSame('2 chemins inconnus détectés le 21/09/2026', $this->resume()['fichiers']);
    }
    public function testInventaireAbsentOuDepotInvalide(): void
    {
        unlink($this->root.'/inventaire-actalibre.json');
        self::assertSame('Analyse non réalisée : inventaire absent ou invalide', $this->resume()['fichiers']);
        self::assertSame('Analyse non réalisée : inventaire absent ou invalide', DiagnosticInstallation::resume($this->root, 'base', '../x', $this->maintenant)['fichiers']);
    }
    public function testDepotInvalideAvecInventaire(): void
    {
        self::assertSame('Analyse non réalisée : réglage UPLOAD_DIR invalide', DiagnosticInstallation::resume($this->root, 'base', '../x', $this->maintenant)['fichiers']);
    }
    public function testLaPageDeControleAfficheUneDateLisibleEnHeureLocale(): void
    {
        $sondes = array_fill_keys(self::SONDES, ['etat'=>'verifie','http'=>403,'cause'=>null]);
        self::assertTrue((new RapportProtection('verifie','1.0.0','https://exemple.org/',$sondes,[],'2026-11-09T19:12:32Z'))->enregistrer($this->root));
        $html = DiagnosticInstallation::html($this->root, 'base', '_upload');
        self::assertStringContainsString('Dernier contrôle : le 09/11/2026 à 20h12, adresse https://exemple.org/', $html);
        self::assertStringNotContainsString('2026-11-09T19:12:32Z', $html);
    }
}
