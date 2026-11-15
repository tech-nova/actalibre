<?php
declare(strict_types=1);

namespace ActaLibre\Carte;

/** Cherche les coordonnées d'un lieu auprès de Nominatim, une requête par seconde au plus, et s'arrête à la première panne. */
final class Geocodeur
{
    public const ADRESSE = 'https://nominatim.openstreetmap.org/search';
    private const PAUSE = 1.0;
    private const DELAI = 10;

    /** @var callable(string):string */
    private $transport;
    private float $derniere = 0.0;
    private bool $enPanne = false;

    /**
     * @param (callable(string):string)|null $transport rend le corps de la réponse, ou lève une exception
     * @param string $identifiant voir identifiant()
     */
    public function __construct(private readonly string $adresse = self::ADRESSE, ?callable $transport = null, string $identifiant = 'ActaLibre')
    {
        $this->transport = $transport ?? static fn (string $url): string => self::telecharger($url, $identifiant);
    }

    /** Les règles de Nominatim demandent d'identifier l'application. */
    public static function identifiant(string $version, string $site): string
    {
        return 'ActaLibre/' . $version . ' (' . (preg_split('/[\r\n]/', $site)[0] ?? '') . ')';
    }

    /**
     * @return array{lon:float,lat:float}|null null si le lieu est introuvable
     * @throws \RuntimeException si le service ne répond pas correctement
     */
    public function chercher(string $lieu): ?array
    {
        if ($this->enPanne) {
            throw new \RuntimeException('Le service de géocodage ne répond pas');
        }
        try {
            return $this->interroger($lieu);
        } catch (\RuntimeException $panne) {
            $this->enPanne = true;
            throw $panne;
        }
    }

    /** @return array{lon:float,lat:float}|null */
    private function interroger(string $lieu): ?array
    {
        $attente = $this->derniere + self::PAUSE - microtime(true);
        if ($attente > 0) {
            usleep((int) ($attente * 1_000_000));
        }
        $this->derniere = microtime(true);
        $corps = ($this->transport)($this->adresse . '?' . http_build_query(['format' => 'jsonv2', 'limit' => 1, 'q' => $lieu]));
        $resultats = json_decode($corps, true);
        if (!is_array($resultats)) {
            throw new \RuntimeException('Réponse illisible du service de géocodage');
        }
        if ($resultats === []) {
            return null;
        }
        if (!is_numeric($resultats[0]['lon'] ?? null) || !is_numeric($resultats[0]['lat'] ?? null)) {
            throw new \RuntimeException('Réponse incomplète du service de géocodage');
        }
        return ['lon' => (float) $resultats[0]['lon'], 'lat' => (float) $resultats[0]['lat']];
    }

    private static function telecharger(string $adresse, string $identifiant): string
    {
        $contexte = stream_context_create(['http' => [
            'user_agent' => $identifiant,
            'timeout' => self::DELAI,
            'header' => "Accept-Language: fr\r\n",
            'ignore_errors' => true,
        ]]);
        $corps = @file_get_contents($adresse, false, $contexte);
        if ($corps === false) {
            throw new \RuntimeException('Le service de géocodage ne répond pas');
        }
        $entetes = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : $http_response_header;
        self::controler($entetes, time());
        return $corps;
    }

    /**
     * @param list<string> $entetes en-têtes de la réponse, lignes d'état comprises (la dernière compte après une redirection)
     * @param int|null $maintenant horodatage Unix servant à convertir un Retry-After donné en date
     * @throws RefusDebit si le service demande de patienter
     * @throws \RuntimeException pour toute autre réponse que 200
     */
    public static function controler(array $entetes, ?int $maintenant = null): void
    {
        $maintenant ??= time();
        $statut = 0;
        $attente = null;
        foreach ($entetes as $ligne) {
            if (preg_match('~\AHTTP/\S+\s+(\d{3})~', $ligne, $m) === 1) {
                $statut = (int) $m[1];
                $attente = null;
            } elseif (preg_match('~\ARetry-After:\s*(.*?)\s*\z~i', $ligne, $m) === 1) {
                $attente = self::attente($m[1], $maintenant);
            }
        }
        if ($statut === 429 || $statut === 503) {
            throw new RefusDebit('Le service de géocodage demande de patienter (HTTP ' . $statut . ').', $attente);
        }
        if ($statut !== 200) {
            throw new \RuntimeException('Réponse inattendue du service de géocodage (HTTP ' . $statut . ').');
        }
    }

    /** Retry-After : un nombre de secondes ou une date HTTP (RFC 9110, trois formats, toujours en UTC). */
    private static function attente(string $valeur, int $maintenant): ?int
    {
        if (preg_match('~\A\d+\z~', $valeur) === 1) {
            return (int) $valeur;
        }
        $valeur = (string) preg_replace('~\s+~', ' ', $valeur);
        foreach (['!D, d M Y H:i:s \\G\\M\\T', '!l, d-M-y H:i:s \\G\\M\\T', '!D M j H:i:s Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $valeur, new \DateTimeZone('UTC'));
            if ($date !== false) {
                return max(0, $date->getTimestamp() - $maintenant);
            }
        }
        return null;
    }
}
