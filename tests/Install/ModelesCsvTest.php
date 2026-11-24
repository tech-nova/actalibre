<?php
declare(strict_types=1);
namespace ActaLibre\Tests\Install;
use ActaLibre\Install\{FichierConnexion,ModelesCsv};
use PHPUnit\Framework\TestCase;

final class ModelesCsvTest extends TestCase
{
    private const NOMS=['D-NimegueV3-photos.m_csv','D-NimegueV3.m_csv','M-NimegueV3-photos.m_csv','M-NimegueV3.m_csv',
        'N-NimegueV3-photos.m_csv','N-NimegueV3.m_csv','V-NimegueV3-photos.m_csv','V-NimegueV3.m_csv'];
    private string $racine;
    protected function setUp(): void
    {
        $this->racine=sys_get_temp_dir().'/al-modeles-'.bin2hex(random_bytes(6));
        foreach (['.htaccess_init','_config_init','tmp_init','admin/_upload_init'] as $s) BaseInstallation::copier(dirname(__DIR__,2).'/'.$s,$this->racine.'/'.$s);
    }
    protected function tearDown(): void { BaseInstallation::supprimer($this->racine); }
    /** @return list<string> */
    private function presents(string $depot): array
    { return array_map('basename',glob($this->racine.'/admin/'.$depot.'/*.m_csv')); }

    public function testLesModelesLivresDesignentDesZonesConnues(): void
    {
        self::assertSame(self::NOMS,$this->presents('_upload_init'));
        $sql=(string)file_get_contents(dirname(__DIR__,2).'/install/creetables.sql');
        foreach (self::NOMS as $nom) {
            $lignes=file($this->racine.'/admin/_upload_init/'.$nom,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
            self::assertSame('TypeActes -||- '.$nom[0],$lignes[0]);
            $zones=0;
            foreach ($lignes as $ligne) {
                self::assertStringContainsString(' -||- ',$ligne,$nom);
                if (!str_starts_with($ligne,'ZID')) continue;
                $zones++;
                $zid=explode(' -||- ',$ligne)[1];
                self::assertMatchesRegularExpression("/\(\s*'?".preg_quote($zid,'/')."'?\s*,/",$sql,$nom.' : '.$zid);
            }
            self::assertGreaterThan(0,$zones);
        }
    }
    public function testUneInstallationNeuveRecoitLesModeles(): void
    {
        self::assertSame(FichierConnexion::PUBLIE,FichierConnexion::publier($this->racine,"<?php\n"));
        self::assertSame(self::NOMS,$this->presents('_upload'));
    }
    public function testSeulsLesModelesAbsentsSontCopies(): void
    {
        mkdir($this->racine.'/admin/depot/csv',0755,true);
        file_put_contents($this->racine.'/admin/depot/csv/N-NimegueV3.m_csv','personnel');
        self::assertSame([],ModelesCsv::completer($this->racine,'depot/csv'));
        self::assertSame(self::NOMS,$this->presents('depot/csv'));
        self::assertSame('personnel',file_get_contents($this->racine.'/admin/depot/csv/N-NimegueV3.m_csv'));
        self::assertFileEquals($this->racine.'/admin/_upload_init/D-NimegueV3.m_csv',$this->racine.'/admin/depot/csv/D-NimegueV3.m_csv');
        self::assertFileDoesNotExist($this->racine.'/admin/depot/csv/.htaccess');
    }
    public function testUnLienCibleNEstPasSuivi(): void
    {
        mkdir($this->racine.'/admin/_upload');
        symlink($this->racine.'/ailleurs',$this->racine.'/admin/_upload/N-NimegueV3.m_csv');
        self::assertSame([],ModelesCsv::completer($this->racine));
        self::assertFileDoesNotExist($this->racine.'/ailleurs');
    }
    public function testUnDepotAbsentOuInvalideDonneUnAvisSansEcriture(): void
    {
        foreach (['_upload','','../ailleurs','/tmp'] as $depot) self::assertCount(1,ModelesCsv::completer($this->racine,$depot),$depot);
        self::assertDirectoryDoesNotExist($this->racine.'/admin/_upload');
        self::assertDirectoryDoesNotExist($this->racine.'/ailleurs');
    }
}
