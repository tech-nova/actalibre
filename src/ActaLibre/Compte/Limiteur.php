<?php
declare(strict_types=1);

namespace ActaLibre\Compte;

use ActaLibre\Db\Requete;

/** Compte les échecs par couple identifiant et adresse, et par adresse. Rien n'est stocké en clair. */
final class Limiteur
{
    public const FENETRE = 900;
    public const MAX_COUPLE = 10;
    public const MAX_ADRESSE = 50;

    public function __construct(private readonly \mysqli $lien, private readonly string $table)
    {
    }

    public function bloque(string $identifiant, string $adresse, ?int $maintenant = null): bool
    {
        $maintenant ??= time();
        return $this->essais(self::cleCouple($identifiant, $adresse), $maintenant) >= self::MAX_COUPLE
            || $this->essais(self::cleAdresse($adresse), $maintenant) >= self::MAX_ADRESSE;
    }

    public function noterEchec(string $identifiant, string $adresse, ?int $maintenant = null): void
    {
        $maintenant ??= time();
        Requete::executer($this->lien, "DELETE FROM {$this->table} WHERE debut <= ?", [$maintenant - self::FENETRE]);
        foreach ([self::cleCouple($identifiant, $adresse), self::cleAdresse($adresse)] as $cle) {
            Requete::executer(
                $this->lien,
                "INSERT INTO {$this->table} (empreinte, essais, debut) VALUES (?, 1, ?) ON DUPLICATE KEY UPDATE essais = essais + 1",
                [$cle, $maintenant]
            );
        }
    }

    public function oublierCouple(string $identifiant, string $adresse): void
    {
        Requete::executer($this->lien, "DELETE FROM {$this->table} WHERE empreinte = ?", [self::cleCouple($identifiant, $adresse)]);
    }

    /** Un abonné dispose d'un préfixe IPv6 entier : c'est ce préfixe qui compte. */
    public static function reseau(string $adresse): string
    {
        $octets = @inet_pton($adresse);
        if ($octets === false) {
            return $adresse;
        }
        if (strlen($octets) === 4) {
            return (string) inet_ntop($octets);
        }
        if (str_starts_with($octets, str_repeat("\0", 10) . "\xff\xff")) {
            return (string) inet_ntop(substr($octets, 12));
        }
        return bin2hex(substr($octets, 0, 8)) . '/64';
    }

    private function essais(string $cle, int $maintenant): int
    {
        $resultat = Requete::executer(
            $this->lien,
            "SELECT essais FROM {$this->table} WHERE empreinte = ? AND debut > ?",
            [$cle, $maintenant - self::FENETRE]
        );
        if (!$resultat instanceof \mysqli_result) {
            return 0;
        }
        $ligne = $resultat->fetch_row();
        return is_array($ligne) ? (int) $ligne[0] : 0;
    }

    private static function cleCouple(string $identifiant, string $adresse): string
    {
        return hash('sha256', "couple\0" . mb_strtolower($identifiant) . "\0" . $adresse);
    }

    private static function cleAdresse(string $adresse): string
    {
        return hash('sha256', "adresse\0" . $adresse);
    }
}
