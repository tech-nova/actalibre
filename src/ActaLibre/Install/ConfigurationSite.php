<?php
declare(strict_types=1);
namespace ActaLibre\Install;

/** Charge seulement la configuration sélectionnée, avant toute routine applicative. */
final class ConfigurationSite
{
    /** @param array<string,mixed> $variables */
    public function __construct(
        public readonly string $fichier,
        public readonly AccesMysql $actes,
        public readonly ?AccesMysql $comptes = null,
        #[\SensitiveParameter] public readonly array $variables = [],
        public readonly string $hote = 'localhost',
    ) {}

    public static function charger(string $racine, string $hote): ?self
    {
        if (!InstallGuard::nomHoteValide($hote)) {
            throw new \RuntimeException('Nom d’hôte invalide.');
        }
        $reference = str_replace([':', '.'], '-', $hote);
        if (strncasecmp($reference, 'www-', 4) === 0) $reference = substr($reference, 4);
        $dossier = $racine . '/_config/';
        $fichier = $dossier . 'BD-' . $reference . '-connect.inc.php';
        if (!file_exists($fichier) && !is_link($fichier)) $fichier = $dossier . 'connect.inc.php';
        if (!file_exists($fichier) && !is_link($fichier)) {
            if ((glob($dossier . 'BD-*-connect.inc.php') ?: []) !== []) {
                throw new \RuntimeException('Aucune configuration utilisable pour cet hôte.');
            }
            return null;
        }
        if (!is_file($fichier) || !is_readable($fichier)) {
            throw new \RuntimeException('Fichier de connexion illisible.');
        }
        ob_start();
        try {
            $variables = (static function (string $chemin): array {
                include $chemin;
                $variables = get_defined_vars();
                unset($variables['chemin']);
                return $variables;
            })($fichier);
            $acces = self::acces($variables, 'db', 'EA_DB');
            $cles = ['udbaddr', 'udbname', 'udbuser', 'udbpass'];
            $distinct = defined('EA_UDB') || array_intersect($cles, array_keys($variables)) !== [];
            $comptes = $distinct ? self::acces($variables, 'udb', 'EA_UDB') : null;
            if ($comptes !== null && $comptes == $acces) $comptes = null;
            return new self($fichier, $acces, $comptes, $variables, $hote);
        } catch (\Throwable) {
            throw new \RuntimeException('Configuration de connexion invalide.');
        } finally {
            ob_end_clean();
        }
    }

    /** @param array<string,mixed> $variables */
    private static function acces(#[\SensitiveParameter] array $variables, string $debut, string $constante): AccesMysql
    {
        $valeurs = [];
        foreach (['addr', 'name', 'user', 'pass'] as $suffixe) {
            if (!isset($variables[$debut . $suffixe]) || !is_string($variables[$debut . $suffixe])) {
                throw new \RuntimeException('Configuration incomplète.');
            }
            $valeurs[] = $variables[$debut . $suffixe];
        }
        if (!defined($constante) || !is_string(constant($constante))) {
            throw new \RuntimeException('Préfixe absent.');
        }
        return new AccesMysql(...[...$valeurs, constant($constante)]);
    }

    /** Identité stable du site et de ses périmètres ; aucun secret n’y figure. */
    public function perimetre(): string
    {
        $perimetres=[];
        foreach ([$this->actes,$this->comptes??$this->actes] as $a) {
            $perimetres[]=mb_strtolower($a->serveur . '/' . $a->base . '/' . $a->prefixe,'UTF-8');
        }
        $racine=dirname(dirname($this->fichier));
        return hash('sha256',json_encode([realpath($racine)?:$racine,strtolower($this->hote),$perimetres],JSON_THROW_ON_ERROR));
    }
    public function ouvrir(): ConnexionsSite
    {
        $actes = $this->actes->ouvrir();
        try {
            return new ConnexionsSite($this, $actes, $this->comptes?->ouvrir() ?? $actes);
        } catch (\Throwable) {
            $actes->close();
            throw new \RuntimeException('Connexion MySQL impossible.');
        }
    }
}
