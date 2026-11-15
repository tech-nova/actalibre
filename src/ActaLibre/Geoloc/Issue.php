<?php
declare(strict_types=1);

namespace ActaLibre\Geoloc;

/** Issue du traitement d'un lieu ; compteur() la range dans l'un des quatre compteurs affichés. */
enum Issue: string
{
    case Trouvee = 'trouvee';
    case Introuvable = 'introuvable';
    case SansNouveauResultat = 'sans_nouveau';
    case ManuelleConservee = 'manuelle';
    case Modifiee = 'modifiee';
    case Supprimee = 'supprimee';

    public function compteur(): string
    {
        return match ($this) {
            self::Trouvee => 'trouvee',
            self::Introuvable, self::SansNouveauResultat => 'introuvable',
            self::ManuelleConservee => 'manuelle',
            self::Modifiee, self::Supprimee => 'a_verifier',
        };
    }
}
