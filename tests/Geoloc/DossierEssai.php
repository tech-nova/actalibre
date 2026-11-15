<?php
declare(strict_types=1);

namespace ActaLibre\Tests\Geoloc;

/** Dossier temporaire et horloge simulée pour les tests de géolocalisation. */
final class DossierEssai
{
    /** Part de l'heure réelle : la purge compare l'horloge simulée aux dates des fichiers. */
    public float $temps;
    /** @var list<float> */
    public array $pauses = [];
    public readonly string $chemin;

    public function __construct()
    {
        $this->temps = (float) time();
        $this->chemin = sys_get_temp_dir() . '/actalibre-geoloc-' . bin2hex(random_bytes(4));
        mkdir($this->chemin);
    }

    public function stockage(): \ActaLibre\Geoloc\Stockage
    {
        return new \ActaLibre\Geoloc\Stockage($this->chemin . '/geocodage');
    }

    /** @return \Closure(): float */
    public function horloge(): \Closure
    {
        return fn (): float => $this->temps;
    }

    /** @return \Closure(float): void */
    public function sommeil(): \Closure
    {
        return function (float $secondes): void {
            $this->pauses[] = $secondes;
            $this->temps += $secondes;
        };
    }

    public function supprimer(): void
    {
        $iterateur = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->chemin, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterateur as $element) {
            $element->isDir() && !$element->isLink() ? rmdir($element->getPathname()) : unlink($element->getPathname());
        }
        rmdir($this->chemin);
    }
}
