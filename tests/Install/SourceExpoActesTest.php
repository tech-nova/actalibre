<?php
declare(strict_types=1);
namespace ActaLibre\Tests\Install;
use ActaLibre\Install\EtatSite;
final class SourceExpoActesTest extends BaseInstallation
{
    public function testLaSourceEstLeSchemaHistoriqueAvecDeuxBases(): void
    {
        $u = new \mysqli((string)getenv('GM_DB_HOST'), (string)getenv('GM_DB_USER'), (string)getenv('GM_DB_PASS'), (string)getenv('GM_DB_NAME_COMPTES'));
        $p = $this->prefixe . 'u';
        try {
            SourceExpoActes::creer($this->base, $this->prefixe, $u, $p);
            $etat = EtatSite::lire($this->base,$this->prefixe,dirname(__DIR__,2).'/composer.json',$u,$p);
            self::assertSame(EtatSite::EXPOACTES_ADMIS,$etat->etat);
            self::assertSame('varchar(40)',$u->query("SHOW COLUMNS FROM {$p}_user3 LIKE 'hashpass'")->fetch_assoc()['Type']);
            self::assertSame('MyISAM',$u->query("SHOW TABLE STATUS WHERE Name='{$p}_user3'")->fetch_assoc()['Engine']);
            self::assertSame('varchar(30)',$this->base->query("SHOW COLUMNS FROM {$this->prefixe}_mar3 LIKE 'C_PRO'")->fetch_assoc()['Type']);
            self::assertSame(' 3.2.4 ',$this->base->query("SELECT valeur FROM {$this->prefixe}_params WHERE param='EA_VERSION'")->fetch_row()[0]);
            self::assertCount(1, $u->query("SHOW TABLES LIKE '{$p}\\_%'")->fetch_all());
            self::assertSame('CONSERVE',$this->base->query("SELECT NOM FROM {$this->prefixe}_nai3 WHERE ID=1")->fetch_row()[0]);
        } finally { SourceExpoActes::supprimer($u,$p); $u->close(); }
    }
}
