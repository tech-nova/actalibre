<?php
declare(strict_types=1);

namespace ActaLibre\Carte;

use ActaLibre\Securite\Filtre;

/** Décrit une carte Leaflet : la page écrit ces données, `tools/js/carte.js` les lit après le consentement. */
final class Carte
{
    /** @var list<array<string,mixed>> */
    private array $marqueurs = [];
    /** @var array{adresse:string,x:int,y:int}|null */
    private ?array $icone = null;
    private ?int $zoom = null;
    /** @var array{lon:float,lat:float}|null */
    private ?array $centre = null;
    private ?int $degroupage = null;
    private bool $deplacable = false;

    public function __construct(private readonly string $largeur, private readonly int $hauteur)
    {
    }

    /** Point d'ancrage de la punaise, en pixels depuis son coin haut gauche. */
    public function icone(string $adresse, int $x, int $y): void
    {
        $this->icone = ['adresse' => $adresse, 'x' => $x, 'y' => $y];
    }

    /** @param string $bulle HTML déjà échappé par l'appelant */
    public function marqueur(float $lon, float $lat, string $titre = '', string $bulle = '', ?string $icone = null): void
    {
        $this->marqueurs[] = ['lon' => $lon, 'lat' => $lat, 'titre' => $titre, 'bulle' => $bulle, 'icone' => $icone];
    }

    public function zoom(int $niveau): void
    {
        $this->zoom = $niveau;
    }

    public function centre(float $lon, float $lat): void
    {
        $this->centre = ['lon' => $lon, 'lat' => $lat];
    }

    /** Regroupe les marqueurs proches jusqu'à ce niveau de zoom. */
    public function regrouper(int $zoomDegroupage): void
    {
        $this->degroupage = $zoomDegroupage;
    }

    /** Le marqueur déplacé remplit les champs `lon` et `lat` du formulaire. */
    public function marqueurDeplacable(): void
    {
        $this->deplacable = true;
    }

    /** @param string|null $racine préfixe des adresses du site ; `pathroot()` le laisse à null à la racine du domaine */
    public function enTete(?string $racine): string
    {
        $base = Filtre::html($racine . '/tools/');
        $sortie = '<link rel="stylesheet" href="' . $base . 'leaflet/leaflet.css" type="text/css" />' . "\n";
        $scripts = ['leaflet/leaflet.js'];
        if ($this->degroupage !== null) {
            $sortie .= '<link rel="stylesheet" href="' . $base . 'leaflet/MarkerCluster.css" type="text/css" />' . "\n";
            $sortie .= '<link rel="stylesheet" href="' . $base . 'leaflet/MarkerCluster.Default.css" type="text/css" />' . "\n";
            $scripts[] = 'leaflet/leaflet.markercluster.js';
        }
        $scripts[] = 'js/carte.js';
        foreach ($scripts as $script) {
            $sortie .= '<script type="text/javascript" src="' . $base . $script . '"></script>' . "\n";
        }
        return $sortie;
    }

    public function afficher(): string
    {
        $style = 'width:' . (preg_match('/^\d+%$/', $this->largeur) === 1 ? $this->largeur : (int) $this->largeur . 'px')
            . ';height:' . $this->hauteur . 'px';
        return '<div id="carte" style="' . $style . '">'
            . '<p>Cette carte est fournie par OpenStreetMap et ne s\'affiche qu\'avec votre accord.</p>'
            . '<p><button type="button" id="carte-autoriser">Autoriser la carte</button></p>'
            . '</div>' . "\n"
            . '<script type="application/json" id="carte-donnees">' . $this->donnees() . '</script>' . "\n";
    }

    private function donnees(): string
    {
        return json_encode(
            [
                'marqueurs' => $this->marqueurs,
                'icone' => $this->icone,
                'zoom' => $this->zoom,
                'centre' => $this->centre,
                'degroupage' => $this->degroupage,
                'deplacable' => $this->deplacable,
            ],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
    }
}
