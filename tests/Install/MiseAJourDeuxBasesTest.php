<?php
declare(strict_types=1);
namespace ActaLibre\Tests\Install;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
#[RunTestsInSeparateProcesses]
final class MiseAJourDeuxBasesTest extends MiseAJourTest
{
    protected function deuxBases(): bool { return true; }
    public function testLesTablesEtrangeresSurviventAuNettoyageDuBanc(): void
    {
        $nom='temoin_al4_'.bin2hex(random_bytes(6));$creees=[];
        try {
            foreach ([$this->base,$this->comptes] as $lien) {
                $lien->query("CREATE TABLE `$nom` (ID INT PRIMARY KEY, valeur VARCHAR(20))");$creees[]=$lien;
                $lien->query("INSERT INTO `$nom` VALUES (7,'conserver')");
            }
            $this->terminer();
            SourceExpoActes::supprimer($this->base,$this->prefixe);
            SourceExpoActes::supprimer($this->comptes,$this->prefixeComptes);
            foreach ($creees as $lien) self::assertSame(['7','conserver'],array_map('strval',$lien->query("SELECT * FROM `$nom`")->fetch_row()));
        } finally { foreach ($creees as $lien) $lien->query("DROP TABLE `$nom`"); }
    }
}
