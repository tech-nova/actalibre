<?php
declare(strict_types=1);
namespace ActaLibre\Tests\Parametres;
use ActaLibre\Parametres\Sauvegarde;
use PHPUnit\Framework\TestCase;
final class SauvegardeTest extends TestCase
{
    public function testLitLesPrefixesEtLeBomSansPerdreLeTexte(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'al5-xml');
        try {
            foreach (['act', 'gm', 'autre_site'] as $prefixe) {
                file_put_contents($f, "\xEF\xBB\xBF" . '<expoactes><'.$prefixe.'_params><param>SITENAME</param><valeur>  A &amp; B  </valeur></'.$prefixe.'_params></expoactes>');
                self::assertSame([['param' => 'SITENAME', 'valeur' => '  A & B  ']], Sauvegarde::lire($f)->lignes('params'));
            }
        } finally { unlink($f); }
    }
    public function testRefuseToutLeDocumentEnCasDeStructureForgee(): void
    {
        $bon = '<gm_params><param>SITENAME</param><valeur>Valide</valeur></gm_params>';
        foreach ([
            '', '<expoactes/>', '<expoactes>', '<autre/>',
            '<!DOCTYPE expoactes [<!ENTITY x SYSTEM "file:///inaccessible">]><expoactes/>',
            '<expoactes>'.$bon.'<gm_params><param>X</param><intrus>Y</intrus></gm_params></expoactes>',
            '<expoactes>'.$bon.'<gm_params><param>X</param><param>Y</param></gm_params></expoactes>',
            '<expoactes>'.$bon.'<gm_metalg><lg>fr</lg></gm_metalg></expoactes>',
            '<expoactes>'.$bon.'<act_params><param>X</param></act_params></expoactes>',
            '<expoactes><gm_params><param><x/></param></gm_params></expoactes>',
            '<expoactes><gm_params attr="x"><param>X</param></gm_params></expoactes>',
            "\xFF\xFE<expoactes/>", "<expoactes>\0</expoactes>",
        ] as $xml) {
            $f = tempnam(sys_get_temp_dir(), 'al5-xml');
            try {
                file_put_contents($f, $xml);
                try { Sauvegarde::lire($f); self::fail('Document accepté'); } catch (\RuntimeException) { self::assertTrue(true); }
            } finally { unlink($f); }
        }
    }
}
