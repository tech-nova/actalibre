<?php
declare(strict_types=1);

namespace ActaLibre\Tests\GoldenMaster;

use ActaLibre\Compte\Session;
use ActaLibre\Securite\Jeton;

/** Client HTTP d'un profil : garde ses cookies, en respecte le chemin et ne suit pas les redirections. */
final class Client
{
    public const JETON = '{JETON}';

    private \CurlHandle $curl;

    public function __construct(private readonly string $base)
    {
        $this->curl = curl_init();
        curl_setopt_array($this->curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_COOKIEFILE => '',
            CURLOPT_HTTPHEADER => ['Host: localhost'],
            CURLOPT_USERAGENT => 'ActaLibre-GoldenMaster',
            CURLOPT_ACCEPT_ENCODING => 'gzip',
        ]);
    }

    /** Ajoute un cookie aux requêtes suivantes, en plus de ceux que le site a posés. */
    public function cookie(string $nom, string $valeur): void
    {
        curl_setopt($this->curl, CURLOPT_COOKIE, $nom . '=' . $valeur);
    }

    /** @param array<string,scalar> $parametres */
    public function get(string $url, array $parametres = []): Reponse
    {
        $parametres = $this->avecJeton($parametres);
        $requete = $parametres === [] ? '' : '?' . http_build_query($parametres);
        return $this->executer([CURLOPT_URL => $this->base . $url . $requete, CURLOPT_HTTPGET => true]);
    }

    /** Suit une adresse déjà complète (chemin et paramètres), telle qu'un lien ou une balise la donne. */
    public function suivre(string $adresse): Reponse
    {
        return $this->executer([CURLOPT_URL => $this->base . $adresse, CURLOPT_HTTPGET => true]);
    }

    /** @param array<string,scalar> $parametres @param array<string,string> $fichiers champ => chemin */
    public function post(string $url, array $parametres = [], array $fichiers = []): Reponse
    {
        $parametres += $this->sessionOuverte() ? [Jeton::CHAMP => self::JETON] : [];
        $parametres = $this->avecJeton($parametres);
        $corps = http_build_query($parametres);
        if ($fichiers !== []) {
            $corps = $parametres;
            foreach ($fichiers as $champ => $chemin) {
                $corps[$champ] = new \CURLFile($chemin, 'text/csv', basename($chemin));
            }
        }
        return $this->executer([CURLOPT_URL => $this->base . $url, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $corps]);
    }

    /** @param array<string,scalar> $parametres @return array<string,scalar> */
    private function avecJeton(array $parametres): array
    {
        if (($parametres[Jeton::CHAMP] ?? null) === self::JETON) {
            $parametres[Jeton::CHAMP] = Jeton::valeur($this->cookieSession());
        }
        return $parametres;
    }

    private function sessionOuverte(): bool
    {
        return $this->cookieSession() !== '';
    }

    /** Une ligne de la liste suit le format Netscape : le nom est en 6e position, la valeur en 7e. */
    private function cookieSession(): string
    {
        foreach (curl_getinfo($this->curl, CURLINFO_COOKIELIST) ?: [] as $ligne) {
            $colonnes = explode("\t", $ligne);
            if (($colonnes[5] ?? '') === Session::COOKIE) {
                return $colonnes[6] ?? '';
            }
        }
        return '';
    }

    /** @param array<int,mixed> $options */
    private function executer(array $options): Reponse
    {
        curl_setopt_array($this->curl, $options);
        $brut = curl_exec($this->curl);
        if (!is_string($brut)) {
            throw new \RuntimeException('Pas de réponse : ' . curl_error($this->curl));
        }
        $tailleEntetes = curl_getinfo($this->curl, CURLINFO_HEADER_SIZE);
        return new Reponse(
            curl_getinfo($this->curl, CURLINFO_RESPONSE_CODE),
            substr($brut, 0, $tailleEntetes),
            substr($brut, $tailleEntetes)
        );
    }
}
