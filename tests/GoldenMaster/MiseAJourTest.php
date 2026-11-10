<?php
declare(strict_types=1);
namespace ActaLibre\Tests\GoldenMaster;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Le banc normal est ActaLibre ; les migrations réelles sont couvertes par MiseAJourParcoursTest. */
final class MiseAJourTest extends TestCase
{
    private SiteJetable $site;
    protected function setUp(): void
    {
        foreach (['HOST','NAME','USER','PASS'] as $c) if (getenv('GM_DB_'.$c)===false) self::markTestSkipped('Configurer GM_DB_*');
        $this->site=SiteJetable::partage(['hote'=>(string)getenv('GM_DB_HOST'),'nom'=>(string)getenv('GM_DB_NAME'),'utilisateur'=>(string)getenv('GM_DB_USER'),'passe'=>(string)getenv('GM_DB_PASS')]);
    }
    private function empreinte(): string
    {
        $resultat=[];
        foreach ($this->site->base->query("SHOW TABLES LIKE 'gm\\_%'")->fetch_all() as [$table]) {
            $l=array_map('serialize',$this->site->base->query("SELECT * FROM `$table`")->fetch_all());sort($l);
            $resultat[$table]=[$this->site->base->query("SHOW CREATE TABLE `$table`")->fetch_row()[1],$l];
        }
        ksort($resultat);return hash('sha256',serialize($resultat));
    }
    public function testUneInstallationNeuveEstAJourSansVersionExpoActes(): void
    {
        $avant=$this->empreinte();$r=$this->site->client('nouveau')->get('/update.php');
        self::assertSame(200,$r->statut);self::assertStringContainsString('à jour',$r->corps);self::assertStringNotContainsString('name="mot_de_passe"',$r->corps);
        self::assertSame('0',$this->site->base->query("SELECT COUNT(*) FROM gm_params WHERE param='EA_VERSION'")->fetch_row()[0]);
        self::assertSame($avant,$this->empreinte());
    }
    public static function versionsRefusees(): iterable { yield ['4.0.0'];yield ['4.0.3'];yield ['3.2.2']; }
    #[DataProvider('versionsRefusees')]
    public function testUnAncienSiteRefuseNeSeMetPasAJour(string $version): void
    {
        $this->site->base->query("DELETE FROM gm_params WHERE param IN ('ACTALIBRE_SCHEMA','ACTALIBRE_VERSION')");
        $q=$this->site->base->prepare("INSERT INTO gm_params (param,valeur,aide) VALUES ('EA_VERSION',?,'')");$q->execute([$version]);
        $avant=$this->empreinte();$c=$this->site->client('nouveau');
        $r=$c->get('/update.php',['force'=>1]);self::assertSame(200,$r->statut);self::assertStringNotContainsString('name="mot_de_passe"',$r->corps);
        [$login,$motDePasse]=SiteJetable::COMPTES['niveau9'];
        $c->post('/update.php',['action'=>'demarrer','force'=>1,'login'=>$login,'mot_de_passe'=>$motDePasse]);
        self::assertSame($avant,$this->empreinte());
    }
}
