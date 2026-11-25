<?php
declare(strict_types=1);
namespace ActaLibre\Install;
use ActaLibre\Securite\Filtre;

/** Création d'un administrateur sur preuve FTP : partie commune à l'installation et à la mise à jour. */
final class FormulaireAdministrateur
{
    private const CHAMPS = [
        'login' => ['Identifiant', 'text', 'username'],
        'courriel' => ['Courriel', 'email', 'email'],
        'mot_de_passe' => ['Mot de passe', 'password', 'new-password'],
        'confirmation' => ['Confirmation du mot de passe', 'password', 'new-password'],
    ];

    /**
     * Consigne de dépôt ; $suite complète la phrase sur la suppression du fichier.
     */
    public static function consigne(string $preuve, string $suite = ''): string
    {
        return '<p>Pour prouver que vous contrôlez ce serveur, déposez par FTP un fichier vide nommé <code>' . Filtre::html($preuve)
            . '</code> dans le dossier <code>_config/</code>, puis validez ce formulaire. Ce nom est valable une heure, dans ce navigateur ; le fichier est supprimé après la création du compte'
            . Filtre::html($suite) . '.</p>';
    }

    /**
     * Les mots de passe ne sont jamais réaffichés.
     * @param array<string,string> $saisie
     */
    public static function champs(array $saisie): string
    {
        $html = '';
        foreach (self::CHAMPS as $nom => [$libelle, $type, $auto]) {
            $valeur = $type === 'password' ? '' : ($saisie[$nom] ?? '');
            $html .= '<label for="creer_' . $nom . '">' . $libelle . '</label><input id="creer_' . $nom . '" name="' . $nom . '" type="' . $type
                . '" value="' . Filtre::html($valeur) . '" autocomplete="' . $auto . '" required>';
        }
        return $html;
    }

    /** À appeler avant toute écriture ; un refus laisse la preuve en place. */
    public static function controler(bool $preuveDeposee, string $login, string $courriel, #[\SensitiveParameter] string $motDePasse, #[\SensitiveParameter] string $confirmation): void
    {
        if (!$preuveDeposee) {
            throw new \RuntimeException('Fichier de preuve introuvable : déposez-le par FTP dans _config/ puis validez à nouveau.');
        }
        Administrateur::valider($login, $courriel, $motDePasse);
        if ($motDePasse !== $confirmation) {
            throw new \RuntimeException('Les mots de passe doivent être identiques.');
        }
    }
}
