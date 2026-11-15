<?php
declare(strict_types=1);

namespace ActaLibre\Geoloc;

use ActaLibre\Securite\Filtre;
use ActaLibre\Securite\Jeton;

/** Balisage partagé par la liste et la fiche : fenêtre de progression et fichiers de l'interface, servis localement. */
final class Vue
{
    private const COMPTEURS = ['trouvee' => 'Trouvées', 'introuvable' => 'Introuvables', 'manuelle' => 'Manuelles conservées', 'a_verifier' => 'À vérifier'];

    public static function entete(?string $racine): string
    {
        $base = Filtre::html($racine . '/tools/');
        return '<link rel="stylesheet" href="' . $base . 'css/geoloc.css" type="text/css" />' . "\n"
            . '<script type="text/javascript" src="' . $base . 'js/geoloc.js" defer></script>' . "\n";
    }

    public static function dialogue(string $api): string
    {
        $compteurs = '';
        foreach (self::COMPTEURS as $cle => $libelle) {
            $compteurs .= '<dt>' . $libelle . '</dt><dd id="geoloc-n-' . $cle . '">0</dd>';
        }
        return '<dialog id="geoloc-dialogue" aria-labelledby="geoloc-titre" aria-describedby="geoloc-avertissement"'
            . ' data-api="' . Filtre::html($api) . '" data-jeton="' . Filtre::html(Jeton::valeur()) . '">' . "\n"
            . '<h2 id="geoloc-titre">Mise à jour des coordonnées géographiques</h2>' . "\n"
            . '<div class="geoloc-activite"><span class="geoloc-roue" aria-hidden="true"></span><p id="geoloc-etat" role="status"></p></div>' . "\n"
            . '<progress id="geoloc-barre" max="1" value="0" aria-labelledby="geoloc-avance"></progress>' . "\n"
            . '<p id="geoloc-avance">0 / 0 localité traitée</p>' . "\n"
            . '<p>En cours : <span id="geoloc-courant">—</span></p>' . "\n"
            . '<dl class="geoloc-compteurs">' . $compteurs . '</dl>' . "\n"
            . '<details><summary>Bilan détaillé</summary><ul id="geoloc-bilan"></ul></details>' . "\n"
            . '<p id="geoloc-avertissement">Fermer cette fenêtre suspend la mise à jour : les résultats déjà enregistrés sont conservés, et une recherche déjà lancée peut se terminer.</p>' . "\n"
            . '<p class="geoloc-attribution">Recherche par le service Nominatim. Données © les contributeurs <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>.</p>' . "\n"
            . '<p class="geoloc-boutons">'
            . '<button type="button" id="geoloc-pause">Mettre en pause</button> '
            . '<button type="button" id="geoloc-reprendre-dialogue" hidden>Reprendre</button> '
            . '<button type="button" id="geoloc-remplacer" hidden>Remplacer les coordonnées manuelles</button> '
            . '<button type="button" id="geoloc-fermer">Fermer</button></p>' . "\n"
            . '</dialog>' . "\n"
            . '<p id="geoloc-annonce" class="geoloc-masque" role="status"></p>' . "\n";
    }
}
