<?php
declare(strict_types=1);

namespace ActaLibre;

/** Valeurs du code livré, indépendantes des marqueurs installés en base. */
final class Livraison
{
    private const ENTIER = '(?:0|[1-9][0-9]*)';

    /** @param list<string> $extensions Noms sans le préfixe ext-. */
    private function __construct(
        public readonly string $version,
        public readonly string $schema,
        public readonly string $phpMinimum,
        public readonly array $extensions
    ) {
    }

    /** Relit toujours le fichier ; aucune constante ni aucun cache n'est créé. */
    public static function lire(string $chemin): self
    {
        if (!is_file($chemin) || !is_readable($chemin)) {
            throw new \RuntimeException('Impossible de lire composer.json.');
        }
        $json = @file_get_contents($chemin);
        if ($json === false) {
            throw new \RuntimeException('Impossible de lire composer.json.');
        }
        try {
            $donnees = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $erreur) {
            throw new \RuntimeException('composer.json : JSON invalide.', 0, $erreur);
        }
        $donnees = self::objet($donnees, 'racine');
        $version = self::version($donnees['version'] ?? null, 'version');
        $extra = self::objet($donnees['extra'] ?? null, 'extra');
        $actalibre = self::objet($extra['actalibre'] ?? null, 'extra.actalibre');
        $schema = self::version($actalibre['schema'] ?? null, 'extra.actalibre.schema');
        $requis = self::objet($donnees['require'] ?? null, 'require');
        $php = $requis['php'] ?? null;
        $entier = self::ENTIER;
        if (!is_string($php) || preg_match('/\A>=(' . $entier . '\.' . $entier . ')(?:\.(' . $entier . '))?\z/', $php, $parties) !== 1) {
            throw new \RuntimeException('composer.json : require.php doit être de la forme >=X.Y ou >=X.Y.Z.');
        }
        $minimum = $parties[1] . '.' . ($parties[2] ?? '0');
        $extensions = [];
        foreach ($requis as $nom => $contrainte) {
            if (!is_string($nom) || !str_starts_with($nom, 'ext-')) {
                continue;
            }
            if ($nom === 'ext-' || !is_string($contrainte) || trim($contrainte) === '') {
                throw new \RuntimeException('composer.json : require.' . $nom . ' invalide.');
            }
            $extensions[] = substr($nom, 4);
        }
        sort($extensions, SORT_STRING);
        return new self($version, $schema, $minimum, $extensions);
    }

    /** @return array<array-key,mixed> */
    private static function objet(mixed $valeur, string $cle): array
    {
        if (!is_array($valeur) || ($valeur !== [] && array_is_list($valeur))) {
            throw new \RuntimeException('composer.json : ' . $cle . ' doit être un objet.');
        }
        return $valeur;
    }

    private static function version(mixed $valeur, string $cle): string
    {
        $entier = self::ENTIER;
        if (!is_string($valeur) || preg_match('/\A' . $entier . '\.' . $entier . '\.' . $entier . '\z/', $valeur) !== 1) {
            throw new \RuntimeException('composer.json : ' . $cle . ' doit être de la forme X.Y.Z.');
        }
        return $valeur;
    }
}
