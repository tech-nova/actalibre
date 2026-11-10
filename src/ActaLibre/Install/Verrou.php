<?php

declare (strict_types=1);
namespace ActaLibre\Install;

use ActaLibre\Db\Requete;
/** Verrou de serveur MySQL, détenu uniquement par la connexion de cette requête. */
final class Verrou
{
    private bool $pris = true;
    private function __construct(private readonly \mysqli $lien, private readonly string $nom)
    {
    }
    public static function prendre(\mysqli $lien, string $base, string $prefixe, string $operation): self
    {
        if (!in_array($operation, ['installation', 'migration'], true)) {
            throw new \InvalidArgumentException('Opération inconnue.');
        }
        // La forme condensée garde la même égalité de casse que le nom lisible.
        $nom = mb_strtolower("{$base}.{$prefixe}_actalibre_{$operation}", 'UTF-8');
        if (strlen($nom) > 64) {
            $nom = "actalibre_{$operation}_" . substr(hash('sha256', $nom), 0, 40);
        }
        try {
            $resultat = Requete::executer($lien, 'SELECT GET_LOCK(?, 0)', [$nom]);
            $valeur = $resultat instanceof \mysqli_result ? $resultat->fetch_row()[0] ?? null : null;
        } catch (\mysqli_sql_exception) {
            throw new \RuntimeException('Impossible de prendre le verrou.');
        }
        if ((string) $valeur === '0') {
            throw new \RuntimeException($operation . ' en cours');
        }
        if ((string) $valeur !== '1') {
            throw new \RuntimeException('Impossible de prendre le verrou.');
        }
        return new self($lien, $nom);
    }
    public function liberer(): void
    {
        if (!$this->pris) {
            return;
        }
        $this->pris = false;
        try {
            Requete::executer($this->lien, 'SELECT RELEASE_LOCK(?)', [$this->nom]);
        } catch (\mysqli_sql_exception|\Error) {
            /* La fermeture de la connexion libère aussi le verrou. */
        }
    }
}
