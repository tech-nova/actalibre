<?php
declare(strict_types=1);

namespace ActaLibre\Compte;

use ActaLibre\Db\Requete;

/** Plafonne les courriels automatiques par destinataire, quelle que soit l'origine de la demande. */
final class PlafondCourriel
{
    public const MAX = 3;
    public const FENETRE = 3600;

    public function __construct(private readonly \mysqli $lien, private readonly string $table)
    {
    }

    /** Compte un envoi et dit s'il peut partir. Sans réponse fiable de la base, rien ne part. */
    public function accepter(string $destinataire, ?int $maintenant = null): bool
    {
        $maintenant ??= time();
        $cle = hash('sha256', mb_strtolower($destinataire));
        Requete::executer($this->lien, "DELETE FROM {$this->table} WHERE debut <= ?", [$maintenant - self::FENETRE]);
        $note = Requete::executer(
            $this->lien,
            "INSERT INTO {$this->table} (empreinte, envois, debut) VALUES (?, 1, ?) ON DUPLICATE KEY UPDATE envois = envois + 1",
            [$cle, $maintenant]
        );
        if ($note === false) {
            return false;
        }
        $resultat = Requete::executer($this->lien, "SELECT envois FROM {$this->table} WHERE empreinte = ?", [$cle]);
        if (!$resultat instanceof \mysqli_result) {
            return false;
        }
        $ligne = $resultat->fetch_row();
        return is_array($ligne) && (int) $ligne[0] <= self::MAX;
    }
}
