<?php
declare(strict_types=1);

namespace ActaLibre\Compte;

/** Attributs communs des cookies de l'application. */
final class Cookie
{
    /** @return array{expires:int,httponly:bool,samesite:string,secure:bool,path?:string} */
    public static function options(int $expiration = 0, ?string $chemin = null): array
    {
        $options = [
            'expires' => $expiration,
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => self::enHttps(),
        ];
        if ($chemin !== null) {
            $options['path'] = $chemin;
        }
        return $options;
    }

    public static function enHttps(): bool
    {
        $https = (string) ($_SERVER['HTTPS'] ?? '');
        return $https !== '' && strtolower($https) !== 'off';
    }

    public static function cheminSite(string $root): string
    {
        if ($root === '') {
            return '/';
        }
        // Le navigateur n'encode pas ces caractères dans l'adresse : encodés ici, le cookie ne lui reviendrait pas.
        return strtr($root, ['%27' => "'", '%21' => '!', '%28' => '(', '%29' => ')', '%2A' => '*', '%24' => '$', '%26' => '&', '%2B' => '+', '%3D' => '=', '%3A' => ':', '%40' => '@']);
    }
}
