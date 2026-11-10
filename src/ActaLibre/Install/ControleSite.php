<?php
declare(strict_types=1);
namespace ActaLibre\Install;
use ActaLibre\Livraison;
use ActaLibre\Securite\Filtre;

/** Barrière commune, avant personnalisation, session, quota ou trace. */
final class ControleSite
{
    /**
     * Résultat partagé seulement dans la requête PHP courante, jamais entre requêtes.
     * @var array<string,ConfigurationSite>
     */
    private static array $verifies=[];
    public static function verifier(string $racine,string $hote,string $cheminSite=''): ConfigurationSite
    {
        $cle=$racine.'|'.$hote;
        if (isset(self::$verifies[$cle])) return self::$verifies[$cle];
        $c=null;
        try {
            $livraison=Livraison::lire($racine.'/composer.json');Installateur::prerequis($livraison);
            $configuration=ConfigurationSite::charger($racine,$hote);
            if ($configuration===null) self::maintenance('Aucun fichier de connexion : installer ActaLibre.',$cheminSite.'install.php');
            $c=$configuration->ouvrir();
            $etat=EtatExploitation::lire($c,$racine.'/composer.json');
            if (!$etat->accessible) self::maintenance($etat->diagnostic,$cheminSite.'update.php');
            self::$verifies[$cle]=$configuration;
            return $configuration;
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            self::maintenance($e->getMessage(),$cheminSite.'update.php');
        } catch (\Throwable) {
            self::maintenance('Impossible de vérifier l’état du site.',$cheminSite.'update.php');
        } finally { $c?->fermer(); }
    }
    private static function maintenance(string $message,string $lien): never
    {
        if (!headers_sent()) {
            http_response_code(503);header('Content-Type: text/html; charset=UTF-8');header('Cache-Control: no-store');
        }
        echo '<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ActaLibre — maintenance</title><main><h1>Site en maintenance</h1><p>'.Filtre::html($message).'</p><p><a href="'.Filtre::html($lien).'">Installation ou mise à jour d’ActaLibre</a></p></main></html>';
        exit(1);
    }
}
