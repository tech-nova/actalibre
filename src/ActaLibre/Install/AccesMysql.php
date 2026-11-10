<?php

declare (strict_types=1);
namespace ActaLibre\Install;

/** Accès saisi ; indépendant de la configuration et des connexions globales du site. */
final class AccesMysql
{
    public function __construct(public readonly string $serveur, public readonly string $base, public readonly string $utilisateur, #[\SensitiveParameter] public readonly string $motDePasse, public readonly string $prefixe)
    {
        if (preg_match('/\A[A-Za-z0-9_]{1,55}\z/', $prefixe) !== 1) {
            throw new \InvalidArgumentException('Préfixe de tables invalide.');
        }
        if ($serveur === '' || $base === '' || $utilisateur === '' || str_starts_with(strtolower($serveur), 'p:') || str_contains($serveur . $base . $utilisateur . $motDePasse, "\x00")) {
            throw new \InvalidArgumentException('Accès MySQL invalides.');
        }
    }
    public function ouvrir(): \mysqli
    {
        try {
            $lien = @new \mysqli($this->serveur, $this->utilisateur, $this->motDePasse, $this->base);
            if ($lien->connect_errno !== 0 || !$lien->set_charset('utf8mb4')) {
                if ($lien->connect_errno === 0) {
                    $lien->close();
                }
                throw new \RuntimeException('Connexion MySQL impossible.');
            }
            return $lien;
        } catch (\mysqli_sql_exception) {
            throw new \RuntimeException('Connexion MySQL impossible.');
        }
    }
}
