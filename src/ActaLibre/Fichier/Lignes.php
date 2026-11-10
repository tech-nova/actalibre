<?php
declare(strict_types=1);

namespace ActaLibre\Fichier;

/**
 * Les lignes d'un fichier, lues à la demande comme le tableau que rendrait file().
 * Le fichier n'est jamais chargé en entier : un import peut dépasser la mémoire allouée à PHP.
 *
 * @implements \IteratorAggregate<int,string>
 * @implements \ArrayAccess<int|string,string>
 */
final class Lignes implements \IteratorAggregate, \Countable, \ArrayAccess
{
    /** @var int<0,max>|null */
    private ?int $nombre = null;
    /** @var resource|null */
    private $lecture = null;
    private int $rangSuivant = 0;

    public function __construct(private readonly string $chemin)
    {
    }

    public function __destruct()
    {
        if ($this->lecture !== null) {
            fclose($this->lecture);
        }
    }

    /** @return \Generator<int,string> */
    public function getIterator(): \Generator
    {
        $entree = $this->ouvrir();
        if ($entree === null) {
            return;
        }
        try {
            for ($rang = 0; ($ligne = fgets($entree)) !== false; $rang++) {
                yield $rang => $ligne;
            }
        } finally {
            fclose($entree);
        }
    }

    public function count(): int
    {
        if ($this->nombre === null) {
            $nombre = 0;
            foreach ($this as $ligne) {
                $nombre++;
            }
            $this->nombre = $nombre;
        }
        return $this->nombre;
    }

    public function offsetExists(mixed $rang): bool
    {
        return $this->offsetGet($rang) !== null;
    }

    /** Une lecture à la suite de la précédente reprend où elle s'était arrêtée. */
    public function offsetGet(mixed $rang): ?string
    {
        // Comme dans un tableau PHP, « 1 » désigne la ligne de rang 1.
        if (is_string($rang) && preg_match('/^(0|[1-9][0-9]*)$/D', $rang) === 1) {
            $rang = (int) $rang;
        }
        if (!is_int($rang) || $rang < 0) {
            return null;
        }
        if ($this->lecture === null || $rang < $this->rangSuivant) {
            if ($this->lecture !== null) {
                fclose($this->lecture);
            }
            $this->lecture = $this->ouvrir();
            $this->rangSuivant = 0;
            if ($this->lecture === null) {
                return null;
            }
        }
        while (($ligne = fgets($this->lecture)) !== false) {
            $this->rangSuivant++;
            if ($this->rangSuivant - 1 === $rang) {
                return $ligne;
            }
        }
        // Fin du fichier : la prochaine lecture repart du début.
        fclose($this->lecture);
        $this->lecture = null;
        return null;
    }

    public function offsetSet(mixed $rang, mixed $valeur): void
    {
        throw new \LogicException('Les lignes d\'un fichier ne se modifient pas.');
    }

    public function offsetUnset(mixed $rang): void
    {
        throw new \LogicException('Les lignes d\'un fichier ne se modifient pas.');
    }

    /** @return resource|null */
    private function ouvrir()
    {
        if (!is_file($this->chemin)) {
            return null;
        }
        $entree = @fopen($this->chemin, 'rb');
        return $entree === false ? null : $entree;
    }
}
