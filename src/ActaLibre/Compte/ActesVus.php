<?php
declare(strict_types=1);

namespace ActaLibre\Compte;

use ActaLibre\Db\Requete;

/** Actes déjà consultés par un compte, jour par jour : un acte n'est décompté qu'une fois par jour. */
final class ActesVus
{
    public function __construct(private readonly \mysqli $lien, private readonly string $table)
    {
    }

    public function dejaVu(int $compte, string $type, int $acte, string $jour): bool
    {
        $resultat = Requete::executer(
            $this->lien,
            "SELECT 1 FROM {$this->table} WHERE compte = ? AND type = ? AND acte = ? AND jour = ?",
            [$compte, $type, $acte, $jour]
        );
        return $resultat instanceof \mysqli_result && $resultat->num_rows > 0;
    }

    /** @return bool|null true si la ligne est nouvelle, false si elle existait déjà, null en cas d'erreur */
    public function noter(int $compte, string $type, int $acte, string $jour): ?bool
    {
        $ecrit = Requete::executer(
            $this->lien,
            "INSERT IGNORE INTO {$this->table} (compte, type, acte, jour) VALUES (?, ?, ?, ?)",
            [$compte, $type, $acte, $jour]
        );
        return $ecrit === false ? null : $this->lien->affected_rows === 1;
    }

    /** Retire les jours antérieurs à celui-ci. */
    public function purger(string $jour): bool
    {
        return Requete::executer($this->lien, "DELETE FROM {$this->table} WHERE jour < ?", [$jour]) !== false;
    }
}
