<?php
declare(strict_types=1);

namespace ActaLibre\Tests\GoldenMaster;

/** Remplace les valeurs propres à une exécution par des jetons, sans masquer de bloc entier. */
final class Normaliseur
{
    public function __construct(
        private readonly \DateTimeImmutable $maintenant,
        private readonly string $racine,
        private readonly int $port,
        private readonly string $versionPhp,
        private readonly string $versionMysql
    ) {
    }

    public function normaliser(string $texte): string
    {
        $dates = [];
        // Le site peut dater en UTC : autour de minuit, ce n'est pas le même jour que pour le test.
        foreach ([$this->maintenant, $this->maintenant->setTimezone(new \DateTimeZone('UTC'))] as $instant) {
            foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd M Y'] as $format) {
                $dates[$instant->format($format)] = '{DATE}';
            }
        }
        $texte = strtr($texte, $dates + [
            $this->racine => '{RACINE}',
            '127.0.0.1:' . $this->port => '{SERVEUR}',
            $this->versionPhp => '{VERSION_PHP}',
            $this->versionMysql => '{VERSION_MYSQL}',
            '2005-' . $this->maintenant->format('Y') => '2005-{ANNEE}',
        ]);
        return (string) preg_replace(
            // Sans le drapeau u : une réponse peut contenir des octets hors UTF-8, qui feraient échouer l'expression.
            ['/\{DATE\}( à |\s)\d{2}:\d{2}(:\d{2})?/', '/(Durée (?:du traitement|totale)\s*:\s*)[\d.]+/', '/autokey=[0-9a-f]{32}/', '/\bjeton(=|" value="|=")[0-9a-f]{64}/'],
            ['{DATE}$1{HEURE}', '$1{DUREE}', 'autokey={AUTOKEY}', 'jeton$1{JETON}'],
            $texte
        );
    }
}
