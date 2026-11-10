<?php
declare(strict_types=1);
namespace ActaLibre\Tests\Install;
use ActaLibre\Install\{ConfigurationSite,ConnexionsSite};
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
#[RunTestsInSeparateProcesses]
final class MiseAJourPerimetreCommunTest extends BaseMiseAJour
{
    public function testDeuxConnexionsAuMemePerimetreNeSeBloquentPasMutuellement(): void
    {
        $original=$this->c;$a=$original->configuration->actes;$u=$a->ouvrir();
        $configuration=new ConfigurationSite($original->configuration->fichier,$a,$a);
        $this->c=new ConnexionsSite($configuration,$this->base,$u);
        try {
            self::assertSame($this->perimetre,$configuration->perimetre());
            $this->terminer();self::assertTrue($this->etat()->accessible);
        } finally { $this->c=$original;$u->close(); }
    }
}
