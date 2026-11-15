<?php
declare(strict_types=1);

namespace ActaLibre\Geoloc;

/**
 * Fichiers privés de la géolocalisation (tmp/actalibre/geocodage) : noms fixés par la politique de Conservation,
 * écritures atomiques, aucun lien symbolique suivi. Une impossibilité d'écrire est une erreur, jamais ignorée.
 */
final class Stockage
{
    private const NOMS = '~\A(?:travail-[a-f0-9]{32}\.json|cache-[a-f0-9]{64}\.json|debit\.json|debit\.lock|travaux\.lock)\z~D';

    public function __construct(private readonly string $dossier)
    {
    }

    /** @return array<string,mixed>|null */
    public function lire(string $nom): ?array
    {
        $chemin = $this->chemin($nom);
        if (!is_file($chemin)) {
            return null;
        }
        $contenu = @file_get_contents($chemin);
        if ($contenu === false) {
            throw new \RuntimeException('Lecture impossible dans le stockage de géolocalisation : ' . $nom);
        }
        $donnees = json_decode($contenu, true);
        return is_array($donnees) ? $donnees : null;
    }

    /** @param array<string,mixed> $donnees */
    public function ecrire(string $nom, array $donnees): void
    {
        $chemin = $this->chemin($nom);
        $json = json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $temporaire = $this->dossier . '/.ecriture-' . bin2hex(random_bytes(12)) . '.tmp';
        $poignee = @fopen($temporaire, 'x');
        $ecrit = $poignee !== false && fwrite($poignee, $json) === strlen($json);
        if ($poignee !== false) {
            fclose($poignee);
        }
        if (!$ecrit || !@rename($temporaire, $chemin)) {
            @unlink($temporaire);
            throw new \RuntimeException('Écriture impossible dans le stockage de géolocalisation : ' . $nom);
        }
    }

    /**
     * @template T
     * @param callable(): T $action
     * @return T
     * @throws Occupe si $attendre est faux et le verrou déjà pris
     */
    public function verrouiller(string $nom, callable $action, bool $attendre): mixed
    {
        $poignee = @fopen($this->chemin($nom), 'c');
        if ($poignee === false) {
            throw new \RuntimeException('Verrou impossible dans le stockage de géolocalisation : ' . $nom);
        }
        try {
            if (!flock($poignee, $attendre ? LOCK_EX : LOCK_EX | LOCK_NB)) {
                throw new Occupe('Une autre opération de géolocalisation est en cours.');
            }
            return $action();
        } finally {
            flock($poignee, LOCK_UN);
            fclose($poignee);
        }
    }

    /** @return list<string> */
    public function lister(string $prefixe): array
    {
        $this->preparer();
        $noms = [];
        foreach (scandir($this->dossier) ?: [] as $nom) {
            if (str_starts_with($nom, $prefixe) && preg_match(self::NOMS, $nom) === 1) {
                $noms[] = $nom;
            }
        }
        return $noms;
    }

    /** Supprime les travaux et les entrées de cache non modifiés depuis $limite (horodatage Unix). */
    public function purger(float $limite): void
    {
        foreach ([...$this->lister('travail-'), ...$this->lister('cache-')] as $nom) {
            $chemin = $this->dossier . '/' . $nom;
            if (!is_link($chemin) && (int) @filemtime($chemin) < $limite) {
                @unlink($chemin);
            }
        }
    }

    private function chemin(string $nom): string
    {
        if (preg_match(self::NOMS, $nom) !== 1) {
            throw new \InvalidArgumentException('Nom de fichier refusé : ' . $nom);
        }
        $this->preparer();
        $chemin = $this->dossier . '/' . $nom;
        if (is_link($chemin)) {
            throw new \RuntimeException('Lien symbolique refusé dans le stockage de géolocalisation : ' . $nom);
        }
        return $chemin;
    }

    private function preparer(): void
    {
        if (is_link($this->dossier)) {
            throw new \RuntimeException('Le dossier de géolocalisation ne doit pas être un lien symbolique.');
        }
        if (!is_dir($this->dossier) && !@mkdir($this->dossier, 0700, true) && !is_dir($this->dossier)) {
            throw new \RuntimeException('Impossible de créer le dossier ' . $this->dossier . ' : vérifiez les droits d’écriture de tmp/.');
        }
        if (!is_writable($this->dossier)) {
            throw new \RuntimeException('Le dossier ' . $this->dossier . ' n’est pas accessible en écriture.');
        }
    }
}
