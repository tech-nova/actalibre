<?php

declare (strict_types=1);
namespace ActaLibre\Tests\Install;

use ActaLibre\Install\DonneesInitiales;
use PHPUnit\Framework\Attributes\DataProvider;
final class DonneesInitialesTest extends BaseInstallation
{
    protected function setUp(): void
    {
        parent::setUp();
        mkdir($this->racine . '/install');
        foreach (['creetables.sql', 'act_params.xml', 'liste_prenoms.csv'] as $f) {
            copy(dirname(__DIR__, 2) . '/install/' . $f, $this->racine . '/install/' . $f);
        }
    }
    public function testLesDonneesLivreesSontCompletesEtRejouables(): void
    {
        $d = new DonneesInitiales($this->racine);
        $d->creerParametres($this->base, $this->prefixe);
        self::assertSame('0', $this->base->query("SELECT COUNT(*) FROM {$this->prefixe}_params")->fetch_row()[0]);
        $d->completer($this->base, $this->prefixe, 'admin@example.org', 'https://example.org');
        foreach (['metadb' => 228, 'metalg' => 228, 'mgrplg' => 39, 'prenom' => 197, 'user3' => 0] as $table => $nombre) {
            self::assertSame($nombre, (int) $this->base->query("SELECT COUNT(*) FROM {$this->prefixe}_{$table}")->fetch_row()[0]);
        }
        $params = array_column($this->base->query("SELECT param,valeur FROM {$this->prefixe}_params")->fetch_all(MYSQLI_ASSOC), 'valeur', 'param');
        foreach (['SITENAME' => 'Mon site principal', 'SITE_URL' => '/', 'CHERCH_TS_TYP' => '1', 'ECLAIR_LOG' => '1', 'EA_ERROR' => '0', 'PUB_ZONE_MENU' => '', 'LOC_MAIL' => 'admin@example.org', 'URL_PUBLIQUE' => 'https://example.org'] as $nom => $valeur) {
            self::assertSame($valeur, $params[$nom]);
        }
        self::assertArrayNotHasKey('EA_VERSION', $params);
        self::assertArrayNotHasKey('ACTALIBRE_VERSION', $params);
        $avant = $this->empreinte();
        $d->completer($this->base, $this->prefixe, 'autre@example.org', 'https://autre.org');
        self::assertSame($avant, $this->empreinte());
    }
    public static function interruptions(): array
    {
        return array_map(static fn($t) => [$t], ['metadb', 'metalg', 'mgrplg', 'prenom', 'params']);
    }
    #[DataProvider('interruptions')]
    public function testUneInterruptionAuMilieuDesDonneesNeDupliqueRien(string $table): void
    {
        $d = new DonneesInitiales($this->racine);
        $d->creerParametres($this->base, $this->prefixe);
        try {
            $d->completer($this->base, $this->prefixe, 'a@example.org', 'https://example.org', static function ($etape) use ($table) {
                if ($etape === "donnee:{$table}:1") {
                    throw new \RuntimeException('Interruption');
                }
            });
            self::fail('Interruption non atteinte');
        } catch (\RuntimeException $e) {
            self::assertSame('Interruption', $e->getMessage());
        }
        $d->completer($this->base, $this->prefixe, 'a@example.org', 'https://example.org');
        $avant = $this->empreinte();
        $d->completer($this->base, $this->prefixe, 'a@example.org', 'https://example.org');
        self::assertSame($avant, $this->empreinte());
        self::assertSame(39, (int) $this->base->query("SELECT COUNT(*) FROM {$this->prefixe}_mgrplg")->fetch_row()[0]);
    }
    public function testLesClesNullesEtLesPersonnalisationsSontPreservees(): void
    {
        file_put_contents($this->racine . '/install/creetables.sql', "\nINSERT INTO EA_DB_mgrplg (grp,dtable,lg,sigle,getiq) VALUES ('ZZ','N','fr',NULL,'Témoin');", FILE_APPEND);
        $d = new DonneesInitiales($this->racine);
        $d->creerParametres($this->base, $this->prefixe);
        $d->completer($this->base, $this->prefixe, 'a@example.org', 'https://example.org');
        $this->base->query("UPDATE {$this->prefixe}_mgrplg SET getiq='Personnalisé' WHERE grp='ZZ'");
        $d->completer($this->base, $this->prefixe, 'a@example.org', 'https://example.org');
        self::assertSame([['Personnalisé', null]], $this->base->query("SELECT getiq,sigle FROM {$this->prefixe}_mgrplg WHERE grp='ZZ'")->fetch_all());
    }
    public function testUnXMLInvalideNeCommencePasLaCreation(): void
    {
        file_put_contents($this->racine . '/install/act_params.xml', '<expoactes><act_params>');
        $avant = $this->empreinte();
        try {
            new DonneesInitiales($this->racine);
            self::fail('XML accepté');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('données initiales', $e->getMessage());
        }
        self::assertSame($avant, $this->empreinte());
    }
    public function testRefuseLesInstructionsNonPrevues(): void
    {
        file_put_contents($this->racine . '/install/creetables.sql', 'DROP TABLE EA_DB_params;', FILE_APPEND);
        $this->expectException(\RuntimeException::class);
        new DonneesInitiales($this->racine);
    }
    public function testLeFormatageXMLNeDevientPasUneValeurDeParametre(): void
    {
        $sql=file_get_contents($this->racine.'/install/creetables.sql');
        preg_match('/CREATE TABLE IF NOT EXISTS EA_DB_params .*?;/s',$sql,$m);
        $schema=$m[0];foreach(['user3','metadb','metalg','mgrplg'] as $t)$schema.="\nCREATE TABLE IF NOT EXISTS EA_DB_$t (id INT);";
        file_put_contents($this->racine.'/install/creetables.sql',$schema);
        file_put_contents($this->racine.'/install/liste_prenoms.csv','');
        file_put_contents($this->racine.'/install/act_params.xml', <<<'XML'
<expoactes><act_params><param>MESSAGE</param><groupe>Mail</groupe><ordre>100</ordre><type>T</type><valeur>
Bonjour\nMonde.\n
        </valeur><aide>Une aide </aide><libelle>  Message  </libelle></act_params></expoactes>
XML);
        $d=new DonneesInitiales($this->racine);$d->creerParametres($this->base,$this->prefixe);$d->completer($this->base,$this->prefixe,'a@example.org','https://example.org');
        $r=$this->base->query("SELECT valeur,aide,libelle FROM {$this->prefixe}_params")->fetch_row();
        self::assertSame(["Bonjour\r\nMonde.",'Une aide','Message'],$r);
    }

    public function testUnNomNormaliseNePeutPasDevenirUnMarqueurReserve(): void
    {
        $f=$this->racine.'/install/act_params.xml';
        file_put_contents($f,str_replace('<param>EA_MAINTENANCE</param>','<param> ACTALIBRE_VERSION </param>',file_get_contents($f)));
        $this->expectException(\RuntimeException::class);
        new DonneesInitiales($this->racine);
    }

}
