<?php
declare(strict_types=1);
namespace ActaLibre\Tests\Install;
use ActaLibre\Install\Conservation;
final class ConservationTest extends BaseDiagnostic
{
    public function testReconnaissanceDesModelesEtDesNomsDesProducteurs(): void
    {
        $regles = new Conservation($this->inventaire(), 'ma.base', 'depot/imports');
        foreach (['tmp/.htaccess','tmp/logs/.keep','tmp/backups/.keep','tmp/archive/.keep','_config/connect.inc.php','_config/BD-exemple.org-connect.inc.php','_config/actes.css','tmp/logs/eclair-2026-11.log','tmp/backups/ma.base_2026-11-09.sql','tmp/backups/backup_2026-11-09_Paris_N.001.bea','tmp/backups/admin.txt','tmp/actalibre/protection.json','admin/depot/imports/admin.csv','admin/depot/imports/N-Mon modele.m_csv'] as $p) {
            self::assertSame('conserve', $regles->classer($p,['mode'=>0100644]),$p);
        }
        foreach (['tmp/backups/maXbase_2026-11-09.sql','tmp/backups/autre_2026-11-09.sql','tmp/intrus.php','_config/debug.php','admin/depot/imports/code.php'] as $p) { self::assertSame('inconnu',$regles->classer($p,['mode'=>0100644]),$p); }
        self::assertTrue($regles->doitDescendre('admin/depot'));
        self::assertFalse($regles->doitDescendre('dossier-inconnu'));
        self::assertSame('inconnu',$regles->classer('_config/connect.inc.php',['mode'=>0120777]));
    }
    public function testRefuseUnDepotHorsArborescence(): void
    {
        $this->expectException(\RuntimeException::class);
        new Conservation($this->inventaire(), 'base', '../config');
    }
}
