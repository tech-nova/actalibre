<?php
declare(strict_types=1);

namespace ActaLibre\Tests\GoldenMaster;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NormaliseurTest extends TestCase
{
    private static function normaliseur(): Normaliseur
    {
        return new Normaliseur(new \DateTimeImmutable('2026-11-04 12:00:00'), '/tmp/actalibre-gm-42', 45079, '8.4.27', '8.0.46');
    }

    /** @return array<string,array{string,string}> */
    public static function remplacements(): array
    {
        return [
            'date SQL' => ['Mise à jour : 2026-11-04.', 'Mise à jour : {DATE}.'],
            'date française' => ['le 04/11/2026', 'le {DATE}'],
            'date à tirets' => ['crédit : 04-11-2026', 'crédit : {DATE}'],
            'date avec le mois en lettres' => ['Paramètres : 04 Nov 2026', 'Paramètres : {DATE}'],
            'date et heure' => ['2026-11-04 07:33:08', '{DATE} {HEURE}'],
            'date à heure' => ['04/11/2026 à 07:33', '{DATE} à {HEURE}'],
            'année du pied de page' => ['2005-2026, ADSoft', '2005-{ANNEE}, ADSoft'],
            'serveur' => ['http://127.0.0.1:45079/x', 'http://{SERVEUR}/x'],
            'racine' => ['/tmp/actalibre-gm-42/_logs', '{RACINE}/_logs'],
            'jeton dans un champ' => ['<input name="jeton" value="' . str_repeat('a', 64) . '" />', '<input name="jeton" value="{JETON}" />'],
            'jeton dans un lien' => ['?jeton=' . str_repeat('a', 64) . '&amp;a=1', '?jeton={JETON}&amp;a=1'],
            'version PHP' => ['PHP 8.4.27', 'PHP {VERSION_PHP}'],
            'version MySQL' => ['MySQL 8.0.46', 'MySQL {VERSION_MYSQL}'],
            'durée de traitement' => ['Durée du traitement  : 12 sec.', 'Durée du traitement  : {DUREE} sec.'],
            'durée totale' => ['Durée totale  : 0.009 sec.', 'Durée totale  : {DUREE} sec.'],
            'clé de reprise' => ['autokey=0123456789abcdef0123456789abcdef&x=1', 'autokey={AUTOKEY}&x=1'],
        ];
    }

    #[DataProvider('remplacements')]
    public function testRemplace(string $texte, string $attendu): void
    {
        self::assertSame($attendu, self::normaliseur()->normaliser($texte));
    }

    public function testOctetsInvalidesConserves(): void
    {
        self::assertSame("caf\xE9 le {DATE}", self::normaliseur()->normaliser("caf\xE9 le 04/11/2026"));
    }

    /** @return array<string,array{string}> */
    public static function valeursAConserver(): array
    {
        return [
            'date d\'acte' => ['11/06/1711'],
            'date de dépôt du jeu fictif' => ['2024-01-15'],
            'autre année' => ['2005-2019'],
            'heure sans date' => ['07:33:08'],
            'empreinte hors autokey' => ['0123456789abcdef0123456789abcdef'],
        ];
    }

    #[DataProvider('valeursAConserver')]
    public function testConserve(string $texte): void
    {
        self::assertSame($texte, self::normaliseur()->normaliser($texte));
    }
}
