<?php
declare(strict_types=1);
namespace ActaLibre\Tests\Install;
/** Les lectures restent réelles ; seule l’écriture du lot contrôlé échoue. */
final class ConnexionEchecMiseAJour extends \mysqli
{
    public bool $refuser=false;
    public bool $exception=false;
    public function prepare(string $query): \mysqli_stmt|false
    {
        if ($this->refuser && preg_match('/\A(?:INSERT|UPDATE|DELETE|CREATE|ALTER)\b/i',$query)) {
            if ($this->exception) throw new \mysqli_sql_exception('sql-secret-temoin');
            return false;
        }
        return parent::prepare($query);
    }
}
