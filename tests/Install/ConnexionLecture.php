<?php
declare(strict_types=1);

namespace ActaLibre\Tests\Install;

/** Les préparations SQL passent par une autre connexion, jamais par cet observateur. */
final class ConnexionLecture extends \mysqli
{
    /** @var list<string> */
    public array $requetes = [];
    public ?int $echecAuRang = null;

    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): \mysqli_result|bool
    {
        if (preg_match('/\A(?:SHOW|SELECT)\b/i', $query) !== 1) {
            throw new \LogicException('Le lecteur a tenté une écriture.');
        }
        $this->requetes[] = $query;
        if (count($this->requetes) === $this->echecAuRang) {
            return false;
        }
        return parent::query($query, $result_mode);
    }
}
