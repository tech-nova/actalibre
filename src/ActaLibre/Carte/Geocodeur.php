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
        ]]);
        $corps = @file_get_contents($adresse, false, $contexte);
        if ($corps === false) {
            throw new \RuntimeException('Le service de géocodage ne répond pas');
        }
        return $corps;
    }
}
