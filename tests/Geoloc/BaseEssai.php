<?php
declare(strict_types=1);

namespace ActaLibre\Tests\Geoloc;

use PHPUnit\Framework\TestCase;

final class BaseEssai
{
    public static function connecter(TestCase $test): \mysqli
    {
        $hote = getenv('GM_DB_HOST');
        $nom = getenv('GM_DB_NAME');
        $utilisateur = getenv('GM_DB_USER');
        $passe = getenv('GM_DB_PASS');
        if ($hote === false || $nom === false || $utilisateur === false || $passe === false) {
            $test->markTestSkipped($test::class . ' ignoré : définir GM_DB_HOST, GM_DB_NAME, GM_DB_USER et GM_DB_PASS.');
        }
        mysqli_report(MYSQLI_REPORT_OFF);
        $lien = new \mysqli($hote, $utilisateur, $passe, $nom);
        $lien->set_charset('utf8mb4');
        return $lien;
    }

    public static function creerGeoloc(\mysqli $lien, string $prefixe): void
    {
        $lien->query("CREATE TEMPORARY TABLE {$prefixe}_geoloc (
            ID int(11) NOT NULL AUTO_INCREMENT,
            COMMUNE varchar(40) COLLATE latin1_general_ci NOT NULL DEFAULT '',
            DEPART varchar(40) COLLATE latin1_general_ci NOT NULL DEFAULT '',
            LON float DEFAULT NULL,
            LAT float DEFAULT NULL,
            STATUT varchar(1) COLLATE latin1_general_ci NOT NULL DEFAULT 'N',
            NOTE_N text COLLATE latin1_general_ci,
            NOTE_M text COLLATE latin1_general_ci,
            NOTE_D text COLLATE latin1_general_ci,
            NOTE_V text COLLATE latin1_general_ci,
            PRIMARY KEY (ID),
            UNIQUE KEY COMMUNE (COMMUNE, DEPART)
        ) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_general_ci") or throw new \RuntimeException($lien->error);
    }

    /** Table d'actes réduite aux colonnes utiles au recensement. */
    public static function creerActes(\mysqli $lien, string $table): void
    {
        $lien->query("CREATE TEMPORARY TABLE $table (
            ID int NOT NULL AUTO_INCREMENT PRIMARY KEY,
            COMMUNE varchar(40) COLLATE latin1_general_ci DEFAULT NULL,
            DEPART varchar(40) COLLATE latin1_general_ci DEFAULT NULL,
            KEY COM_DEP (COMMUNE(10), DEPART(4))
        ) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_general_ci") or throw new \RuntimeException($lien->error);
    }
}
