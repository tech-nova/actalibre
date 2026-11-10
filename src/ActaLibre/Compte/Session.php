<?php
declare(strict_types=1);

namespace ActaLibre\Compte;

use ActaLibre\Db\Requete;

/** Sessions enregistrées côté serveur : le jeton n'existe que dans le cookie. */
final class Session
{
    public const COOKIE = 'actalibre_session';
    private const DUREE_COURTE = 86400;
    private const DUREE_LONGUE = 432000;
    /** Évite une écriture à chaque page. */
    private const PAS_DE_PROLONGATION = 3600;

    public function __construct(private readonly \mysqli $lien, private readonly string $table)
    {
    }

    public function ouvrir(int $compte, bool $resterConnecte, ?int $maintenant = null): ?string
    {
        $maintenant ??= time();
        Requete::executer($this->lien, "DELETE FROM {$this->table} WHERE expiration <= ?", [$maintenant]);
        $jeton = bin2hex(random_bytes(32));
        $duree = $resterConnecte ? self::DUREE_LONGUE : self::DUREE_COURTE;
        $ecrit = Requete::executer(
            $this->lien,
            "INSERT INTO {$this->table} (empreinte, compte, creation, expiration, duree) VALUES (?, ?, ?, ?, ?)",
            [hash('sha256', $jeton), $compte, $maintenant, $maintenant + $duree, $duree]
        );
        return $ecrit === false ? null : $jeton;
    }

    /** @return array{compte:int,rester:bool,prolongee:bool}|null */
    public function lire(string $jeton, ?int $maintenant = null): ?array
    {
        $maintenant ??= time();
        $empreinte = hash('sha256', $jeton);
        $resultat = Requete::executer(
            $this->lien,
            "SELECT compte, expiration, duree FROM {$this->table} WHERE empreinte = ? AND expiration > ?",
            [$empreinte, $maintenant]
        );
        if (!$resultat instanceof \mysqli_result) {
            return null;
        }
        $ligne = $resultat->fetch_assoc();
        if ($ligne === null || $ligne === false) {
            return null;
        }
        $duree = (int) $ligne['duree'];
        $prolongee = (int) $ligne['expiration'] < $maintenant + $duree - self::PAS_DE_PROLONGATION;
        if ($prolongee) {
            Requete::executer(
                $this->lien,
                "UPDATE {$this->table} SET expiration = ? WHERE empreinte = ?",
                [$maintenant + $duree, $empreinte]
            );
        }
        return ['compte' => (int) $ligne['compte'], 'rester' => $duree === self::DUREE_LONGUE, 'prolongee' => $prolongee];
    }

    public function fermer(string $jeton): void
    {
        Requete::executer($this->lien, "DELETE FROM {$this->table} WHERE empreinte = ?", [hash('sha256', $jeton)]);
    }

    public function fermerToutes(int $compte): void
    {
        Requete::executer($this->lien, "DELETE FROM {$this->table} WHERE compte = ?", [$compte]);
    }

    public function fermerOrphelines(string $tableComptes): void
    {
        Requete::executer($this->lien, "DELETE FROM {$this->table} WHERE compte NOT IN (SELECT ID FROM {$tableComptes})");
    }

    /** Sans « rester connecté », le cookie disparaît avec le navigateur. */
    public function expirationCookie(bool $rester, ?int $maintenant = null): int
    {
        return $rester ? ($maintenant ?? time()) + self::DUREE_LONGUE : 0;
    }
}
