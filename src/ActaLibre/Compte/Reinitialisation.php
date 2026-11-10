<?php
declare(strict_types=1);

namespace ActaLibre\Compte;

use ActaLibre\Db\Requete;

/** Liens à usage unique pour choisir un mot de passe. Le jeton n'existe que dans le courriel. */
final class Reinitialisation
{
    public const DUREE_OUBLI = 3600;
    public const DUREE_CREATION = 604800;

    public function __construct(private readonly \mysqli $lien, private readonly string $table)
    {
    }

    public function creer(int $compte, int $duree, ?int $maintenant = null): ?string
    {
        $maintenant ??= time();
        Requete::executer($this->lien, "DELETE FROM {$this->table} WHERE expiration <= ? OR compte = ?", [$maintenant, $compte]);
        $jeton = bin2hex(random_bytes(32));
        $ecrit = Requete::executer(
            $this->lien,
            "INSERT INTO {$this->table} (empreinte, compte, expiration) VALUES (?, ?, ?)",
            [hash('sha256', $jeton), $compte, $maintenant + $duree]
        );
        return $ecrit === false ? null : $jeton;
    }

    public function compte(string $jeton, ?int $maintenant = null): ?int
    {
        $resultat = Requete::executer(
            $this->lien,
            "SELECT compte FROM {$this->table} WHERE empreinte = ? AND expiration > ?",
            [hash('sha256', $jeton), $maintenant ?? time()]
        );
        if (!$resultat instanceof \mysqli_result) {
            return null;
        }
        $ligne = $resultat->fetch_row();
        return is_array($ligne) ? (int) $ligne[0] : null;
    }

    /** Deux envois simultanés : la suppression ne réussit qu'une fois. */
    public function consommer(string $jeton, ?int $maintenant = null): ?int
    {
        $compte = $this->compte($jeton, $maintenant);
        if ($compte === null) {
            return null;
        }
        Requete::executer($this->lien, "DELETE FROM {$this->table} WHERE empreinte = ?", [hash('sha256', $jeton)]);
        return $this->lien->affected_rows === 1 ? $compte : null;
    }

    public function fermerOrphelines(string $tableComptes): void
    {
        Requete::executer($this->lien, "DELETE FROM {$this->table} WHERE compte NOT IN (SELECT ID FROM {$tableComptes})");
    }

    public function supprimerCompte(int $compte): void
    {
        Requete::executer($this->lien, "DELETE FROM {$this->table} WHERE compte = ?", [$compte]);
    }
}
