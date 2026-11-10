<?php
declare(strict_types=1);

namespace ActaLibre\Tests\GoldenMaster;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;

/** Compare les réponses du site aux captures ; GM_MAJ=1 réécrit les captures. */
final class GoldenMasterTest extends TestCase
{
    private const CAPTURES = __DIR__ . '/captures';
    private const SORTIE = __DIR__ . '/../../.phpunit.cache/golden-master';
    private const LIGNES_DIFF = 40;

    private static ?SiteJetable $site = null;
    private static ?Normaliseur $normaliseur = null;
    /** @var array<int,int> */
    private static array $statuts = [];

    public static function setUpBeforeClass(): void
    {
        $hote = getenv('GM_DB_HOST');
        $nom = getenv('GM_DB_NAME');
        $utilisateur = getenv('GM_DB_USER');
        $passe = getenv('GM_DB_PASS');
        if ($hote === false || $nom === false || $utilisateur === false || $passe === false) {
            self::markTestSkipped('Golden master ignoré : définir GM_DB_HOST, GM_DB_NAME, GM_DB_USER et GM_DB_PASS.');
        }
        if (is_dir(self::SORTIE . '/recu')) {
            array_map('unlink', glob(self::SORTIE . '/recu/*'));
        } else {
            mkdir(self::SORTIE . '/recu', 0777, true);
        }
        // Site neuf, pas SiteJetable::partage() : admin/gestindex.php affiche les statistiques des index,
        // qu'une table recopiée depuis l'instantané ne reproduit pas, même après ANALYZE TABLE.
        self::$site = new SiteJetable(dirname(__DIR__, 2), ['hote' => $hote, 'nom' => $nom, 'utilisateur' => $utilisateur, 'passe' => $passe]);
        self::$site->demarrer();
        self::$normaliseur = new Normaliseur(
            new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')), // Fuseau du site : voir tools/actutils.php.
            self::$site->racine,
            self::$site->port(),
            PHP_VERSION,
            self::$site->versionMysql()
        );
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$site === null) {
            return;
        }
        $journal = is_file(self::$site->journal) ? (string) file_get_contents(self::$site->journal) : '';
        file_put_contents(self::SORTIE . '/php.log', $journal);
        self::$site->arreter();
        self::$site = null;
        ksort(self::$statuts);
        fwrite(STDERR, "\nGolden master : statuts " . json_encode(self::$statuts) . "\n");
        fwrite(STDERR, 'Journal PHP (' . self::SORTIE . '/php.log) : ' . self::resumerJournal($journal) . "\n");
    }

    /** @return array<string,array{array<string,mixed>}> */
    public static function scenarios(): array
    {
        $parNom = [];
        foreach (require __DIR__ . '/scenarios.php' as $scenario) {
            $parNom[$scenario['nom']] = [$scenario];
        }
        return $parNom;
    }

    /** @param array<string,mixed> $scenario */
    #[DataProvider('scenarios')]
    public function testScenario(array $scenario): void
    {
        self::$site->figerJournal();
        $client = self::$site->client($scenario['profil']);
        $reponse = $scenario['methode'] === 'POST'
            ? $client->post($scenario['url'], $scenario['parametres'])
            : $client->get($scenario['url'], $scenario['parametres']);
        self::$statuts[$reponse->statut] = (self::$statuts[$reponse->statut] ?? 0) + 1;
        self::assertStringNotContainsString('géocodé', $reponse->corps, 'Géocodage tenté vers Google');
        $this->comparer(self::fichierCapture($scenario['nom']), self::$normaliseur->normaliser($reponse->capture()));
    }

    public function testEtatDeLaBase(): void
    {
        $this->comparer('etat_base.txt', EtatBase::resumer(self::$site->base, self::$normaliseur));
    }

    public function testLeJournalEstPropre(): void
    {
        preg_match_all('/PHP (?:Deprecated|Warning|Notice|Fatal error):[^\n]*/', is_file(self::$site->journal) ? (string) file_get_contents(self::$site->journal) : '', $messages);
        self::assertSame([], array_values(array_unique($messages[0])));
    }

    public function testAucuneCaptureOrpheline(): void
    {
        $attendues = array_map(self::fichierCapture(...), array_keys(self::scenarios()));
        $attendues[] = 'etat_base.txt';
        $orphelines = array_diff(array_map('basename', glob(self::CAPTURES . '/*') ?: []), $attendues);
        if (getenv('GM_MAJ') === '1') {
            array_map(static fn (string $nom) => unlink(self::CAPTURES . '/' . $nom), $orphelines);
            $orphelines = [];
        }
        self::assertSame([], array_values($orphelines), 'Captures sans scénario');
    }

    public static function fichierCapture(string $nom): string
    {
        return str_replace('/', '__', $nom) . '.html';
    }

    private function comparer(string $fichier, string $recu): void
    {
        file_put_contents(self::SORTIE . '/recu/' . $fichier, $recu);
        $chemin = self::CAPTURES . '/' . $fichier;
        if (getenv('GM_MAJ') === '1') {
            is_dir(self::CAPTURES) || mkdir(self::CAPTURES);
            file_put_contents($chemin, $recu);
            $this->addToAssertionCount(1);
            return;
        }
        self::assertFileExists($chemin, 'Capture absente : relancer avec GM_MAJ=1 après relecture');
        $attendu = (string) file_get_contents($chemin);
        if ($attendu !== $recu) {
            $diff = (new Differ(new UnifiedDiffOutputBuilder('')))->diff($attendu, $recu);
            self::fail($fichier . " diffère :\n" . implode("\n", array_slice(explode("\n", $diff), 0, self::LIGNES_DIFF)));
        }
        $this->addToAssertionCount(1);
    }

    private static function resumerJournal(string $journal): string
    {
        preg_match_all('/PHP (Fatal error|Parse error|Warning|Notice|Deprecated)/', $journal, $niveaux);
        $compte = array_count_values($niveaux[1]);
        return $compte === [] ? 'vide' : json_encode($compte);
    }
}
