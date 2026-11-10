<?php
declare(strict_types=1);

namespace ActaLibre\Compte;

use ActaLibre\Db\Requete;

/** Passage des comptes au nouveau format. Chaque méthode peut être relancée sans effet. */
final class Migration
{
    private const TAILLE_DU_LOT = 25;
    private const ANCIEN_FORMAT = "hashpass REGEXP '^[0-9a-fA-F]{40}$'";

    public static function preparerSessions(\mysqli $lien, string $prefixe): bool
    {
        return (bool) $lien->query(
            "CREATE TABLE IF NOT EXISTS {$prefixe}_session ("
            . 'empreinte CHAR(64) NOT NULL, compte INT NOT NULL, creation BIGINT NOT NULL, '
            . 'expiration BIGINT NOT NULL, duree INT NOT NULL, '
            . 'PRIMARY KEY (empreinte), KEY compte (compte), KEY expiration (expiration)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=ascii'
        );
    }

    public static function preparerParcours(\mysqli $lien, string $prefixe): bool
    {
        return $lien->query(
            "CREATE TABLE IF NOT EXISTS {$prefixe}_essai ("
            . 'empreinte CHAR(64) NOT NULL, essais INT NOT NULL, debut BIGINT NOT NULL, '
            . 'PRIMARY KEY (empreinte), KEY debut (debut)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=ascii'
        ) && $lien->query(
            "CREATE TABLE IF NOT EXISTS {$prefixe}_reinit ("
            . 'empreinte CHAR(64) NOT NULL, compte INT NOT NULL, expiration BIGINT NOT NULL, '
            . 'PRIMARY KEY (empreinte), KEY compte (compte), KEY expiration (expiration)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=ascii'
        ) && $lien->query(
            "CREATE TABLE IF NOT EXISTS {$prefixe}_acte_vu ("
            . 'compte INT NOT NULL, type CHAR(1) NOT NULL, acte INT NOT NULL, jour DATE NOT NULL, '
            . 'PRIMARY KEY (compte, type, acte, jour), KEY jour (jour)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=ascii'
        ) && $lien->query(
            "CREATE TABLE IF NOT EXISTS {$prefixe}_courriel ("
            . 'empreinte CHAR(64) NOT NULL, envois INT NOT NULL, debut BIGINT NOT NULL, '
            . 'PRIMARY KEY (empreinte), KEY debut (debut)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=ascii'
        );
    }

    public static function preparerComptes(\mysqli $lien, string $table): bool
    {
        return self::remplacerDatesNulles($lien, $table) && (bool) $lien->query(
            "ALTER TABLE {$table} MODIFY hashpass VARCHAR(255) COLLATE latin1_general_ci NOT NULL DEFAULT '', ENGINE=InnoDB"
        );
    }

    /** En mode strict, MySQL refuse de reconstruire une table qui contient une date 0000-00-00. */
    private static function remplacerDatesNulles(\mysqli $lien, string $table): bool
    {
        $colonnes = $lien->query("SHOW COLUMNS FROM {$table}");
        if (!$colonnes instanceof \mysqli_result) {
            return false;
        }
        foreach ($colonnes->fetch_all(MYSQLI_ASSOC) as $colonne) {
            if (!in_array(strtolower((string) $colonne['Type']), ['date', 'datetime'], true)) {
                continue;
            }
            $nom = '`' . str_replace('`', '``', (string) $colonne['Field']) . '`';
            if (!$lien->query("UPDATE {$table} SET {$nom} = '1001-01-01' WHERE {$nom} < '1000-01-01'")) {
                return false;
            }
        }
        return true;
    }

    /** @return array{dernierId:int,terminee:bool} */
    public static function migrerLot(\mysqli $lien, string $table, int $apresId, int $limite = 25): array
    {
        if (preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $table) !== 1 || $apresId < 0 || $limite < 1 || $limite > 25) {
            throw new \RuntimeException('Lot de comptes invalide.');
        }
        $transaction = false;
        try {
            $r = Requete::executer($lien, "SELECT ID,hashpass FROM `$table` WHERE ID > ? ORDER BY ID LIMIT $limite", [$apresId]);
            if (!$r instanceof \mysqli_result) throw new \RuntimeException('Lecture des comptes impossible.');
            $lignes = $r->fetch_all(MYSQLI_ASSOC);
            if (!$lien->begin_transaction()) throw new \RuntimeException('Transaction de comptes impossible.');
            $transaction = true;
            $dernier = $apresId;
            foreach ($lignes as $ligne) {
                $id = (int) $ligne['ID'];
                $hash = (string) $ligne['hashpass'];
                if (MotDePasse::estAncienFormat($hash)) {
                    $nouveau = MotDePasse::migrer($hash);
                    if (Requete::executer($lien, "UPDATE `$table` SET hashpass=? WHERE ID=? AND BINARY hashpass=BINARY ?", [$nouveau, $id, $hash]) !== true) {
                        throw new \RuntimeException('Conversion d’un compte impossible.');
                    }
                    $verification = Requete::executer($lien, "SELECT hashpass FROM `$table` WHERE ID=?", [$id]);
                    if (!$verification instanceof \mysqli_result || ($verification->fetch_row()[0] ?? null) !== $nouveau) {
                        throw new \RuntimeException('Compte modifié pendant la conversion.');
                    }
                } elseif ($hash !== '' && password_get_info($hash)['algo'] === null) {
                    throw new \RuntimeException('Empreinte de compte non reconnue.');
                }
                $dernier = $id;
            }
            if (!$lien->commit()) throw new \RuntimeException('Validation du lot de comptes impossible.');
            $transaction = false;
            $suite = Requete::executer($lien, "SELECT ID FROM `$table` WHERE ID > ? LIMIT 1", [$dernier]);
            if (!$suite instanceof \mysqli_result) throw new \RuntimeException('Lecture de la suite des comptes impossible.');
            return ['dernierId' => $dernier, 'terminee' => $suite->num_rows === 0];
        } catch (\mysqli_sql_exception) {
            throw new \RuntimeException('Migration des comptes impossible.');
        } finally {
            if ($transaction) $lien->rollback();
        }
    }

    /** @return int comptes restants, -1 en cas d'erreur */
    public static function migrerMotsDePasse(\mysqli $lien, string $table, int $secondes): int
    {
        $limite = time() + $secondes;
        while (time() < $limite) {
            $lot = $lien->query("SELECT ID, hashpass FROM {$table} WHERE " . self::ANCIEN_FORMAT . ' LIMIT ' . self::TAILLE_DU_LOT);
            if (!$lot instanceof \mysqli_result) {
                return -1;
            }
            $lignes = $lot->fetch_all(MYSQLI_ASSOC);
            if ($lignes === []) {
                return 0;
            }
            $lien->begin_transaction();
            foreach ($lignes as $ligne) {
                $ecrit = Requete::executer(
                    $lien,
                    "UPDATE {$table} SET hashpass = ? WHERE ID = ? AND hashpass = ?",
                    [MotDePasse::migrer((string) $ligne['hashpass']), (int) $ligne['ID'], (string) $ligne['hashpass']]
                );
                if ($ecrit === false) {
                    $lien->rollback();
                    return -1;
                }
            }
            $lien->commit();
        }
        $reste = $lien->query("SELECT COUNT(*) FROM {$table} WHERE " . self::ANCIEN_FORMAT);
        $ligne = $reste instanceof \mysqli_result ? $reste->fetch_row() : null;
        return is_array($ligne) ? (int) $ligne[0] : -1;
    }
}
