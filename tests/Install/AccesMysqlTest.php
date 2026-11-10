<?php

declare (strict_types=1);
namespace ActaLibre\Tests\Install;

use ActaLibre\Install\AccesMysql;
final class AccesMysqlTest extends BaseInstallation
{
    public function testRefuseLePrefixeAvantDeContacterUnServeur(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AccesMysql('injoignable.invalid', 'base', 'user', 'secret', 'act`; DROP');
    }
    public function testOuvreLaBaseAvecUnEncodageExplicite(): void
    {
        $acces = new AccesMysql((string) getenv('GM_DB_HOST'), (string) getenv('GM_DB_NAME'), (string) getenv('GM_DB_USER'), (string) getenv('GM_DB_PASS'), $this->prefixe);
        $lien = $acces->ouvrir();
        self::assertSame('utf8mb4', $lien->character_set_name());
        self::assertSame(getenv('GM_DB_NAME'), $lien->query('SELECT DATABASE()')->fetch_row()[0]);
        $lien->close();
    }
    public function testRefuseLesConnexionsPersistantes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AccesMysql('p:localhost', 'base', 'user', 'secret', 'act');
    }
}
