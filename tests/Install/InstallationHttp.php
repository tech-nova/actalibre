<?php
declare(strict_types=1);
namespace ActaLibre\Tests\Install;

use ActaLibre\Tests\GoldenMaster\{Client, Reponse};

/** Parcours complet d'install.php : connexion, puis administrateur avec preuve FTP. */
final class InstallationHttp
{
    /** @param array<string,string> $formulaire champs des deux écrans */
    public static function installer(Client $client, string $racine, array $formulaire): Reponse
    {
        $connexion = $client->post('/install.php', $formulaire + ['jeton' => self::jeton($client)]);
        if ($connexion->statut !== 303 || !str_contains($connexion->entetes, 'Location: install.php')) {
            return $connexion;
        }
        $ecran = $client->get('/install.php');
        if (preg_match('/admin-[0-9a-f]{32}\.txt/', $ecran->corps, $m) !== 1) {
            return $ecran;
        }
        touch($racine . '/_config/' . $m[0]);
        return $client->post('/install.php', $formulaire + ['jeton' => self::jeton($client)]);
    }
    private static function jeton(Client $client): string
    {
        $r = $client->get('/install.php');
        if (preg_match('/name="jeton" value="([0-9a-f]{64})"/', $r->corps, $m) !== 1) {
            throw new \RuntimeException('Jeton d’installation absent : HTTP ' . $r->statut);
        }
        return $m[1];
    }
}
