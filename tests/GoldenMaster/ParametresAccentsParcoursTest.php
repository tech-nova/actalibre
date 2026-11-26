<?php
declare(strict_types=1);

namespace ActaLibre\Tests\GoldenMaster;

use ActaLibre\Parametres\Sauvegarde;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Vrais formulaires et courriels, avec le stockage latin1 historique. */
final class ParametresAccentsParcoursTest extends TestCase
{
    private SiteJetable $site;
    private const TEXTE = 'Très bel été à Noël, ça vient du cœur';
    private const STOCKAGE = 'Tr&egrave;s bel &eacute;t&eacute; &agrave; No&euml;l, &ccedil;a vient du c&oelig;ur';

    protected function setUp(): void
    {
        $hote = getenv('GM_DB_HOST');
        $nom = getenv('GM_DB_NAME');
        $utilisateur = getenv('GM_DB_USER');
        $passe = getenv('GM_DB_PASS');
        if ($hote === false || $nom === false || $utilisateur === false || $passe === false) {
            self::markTestSkipped('Configurer GM_DB_HOST, GM_DB_NAME, GM_DB_USER et GM_DB_PASS sur une base dédiée.');
        }
        $this->site = SiteJetable::partage(['hote' => $hote, 'nom' => $nom, 'utilisateur' => $utilisateur, 'passe' => $passe]);
        $this->site->base->set_charset('utf8mb4');
        // La distribution utilise une autre collation latin1 ; reproduire celle du site signalé.
        $this->site->base->query('ALTER TABLE gm_params CONVERT TO CHARACTER SET latin1 COLLATE latin1_swedish_ci');
    }

    protected function tearDown(): void
    {
        if (isset($this->site)) {
            $this->site->reinitialiser();
        }
    }

    private function stocker(string $parametre, string $valeur): void
    {
        $q = $this->site->base->prepare('UPDATE gm_params SET valeur = ? WHERE param = ?');
        $q->execute([$valeur, $parametre]);
    }

    private function octets(string $parametre): string
    {
        $q = $this->site->base->prepare('SELECT HEX(valeur) FROM gm_params WHERE param = ?');
        $q->execute([$parametre]);
        return $q->get_result()->fetch_row()[0];
    }

    /** @return array{\DOMXPath,\DOMElement,array<string,string>} */
    private function formulaire(Reponse $page): array
    {
        self::assertSame(200, $page->statut);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $page->corps, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);
        $formulaires = $xpath->query('//form[.//input[@name="parnbr"]]');
        self::assertNotFalse($formulaires);
        self::assertCount(1, $formulaires);
        $formulaire = $formulaires->item(0);
        self::assertInstanceOf(\DOMElement::class, $formulaire);
        $champs = [];
        $controles = $xpath->query('.//input[@name] | .//textarea[@name] | .//select[@name]', $formulaire);
        self::assertNotFalse($controles);
        foreach ($controles as $controle) {
            self::assertInstanceOf(\DOMElement::class, $controle);
            $type = $controle->getAttribute('type');
            if ($controle->hasAttribute('disabled') || in_array($type, ['submit', 'reset', 'button'], true)
                || (in_array($type, ['checkbox', 'radio'], true) && !$controle->hasAttribute('checked'))) {
                continue;
            }
            $valeur = $controle->getAttribute('value');
            if ($controle->tagName === 'textarea') {
                $valeur = $controle->textContent;
            } elseif ($controle->tagName === 'select') {
                $options = $xpath->query('.//option[@selected]', $controle);
                self::assertNotFalse($options);
                $option = $options->item(0) ?? $controle->getElementsByTagName('option')->item(0);
                self::assertInstanceOf(\DOMElement::class, $option);
                $valeur = $option->hasAttribute('value') ? $option->getAttribute('value') : $option->textContent;
            }
            $champs[$controle->getAttribute('name')] = $valeur;
        }
        return [$xpath, $formulaire, $champs];
    }

    /** @param array<string,string> $champs */
    private function champ(array $champs, string $parametre): string
    {
        foreach ($champs as $nom => $valeur) {
            if (str_starts_with($nom, 'parname') && $valeur === $parametre) {
                return 'parvalue' . substr($nom, strlen('parname'));
            }
        }
        self::fail('Paramètre absent du formulaire : ' . $parametre);
    }

    /** @return iterable<string,array{string,string}> */
    public static function zonesDeTexte(): iterable
    {
        yield 'Mail, même avec une saisie courte' => ['Mail', 'MAIL_NEWUSER'];
        yield 'Affichage' => ['Affichage', 'PUB_ZONE_MENU'];
    }

    private function verifierBanc(): void
    {
        $table = $this->site->base->query("SHOW TABLE STATUS LIKE 'gm_params'")->fetch_assoc();
        self::assertSame('MyISAM', $table['Engine']);
        self::assertSame('latin1_swedish_ci', $table['Collation']);
        $colonne = $this->site->base->query("SHOW FULL COLUMNS FROM gm_params LIKE 'valeur'")->fetch_assoc();
        self::assertSame('latin1_swedish_ci', $colonne['Collation']);
        self::assertSame('utf8mb4', $this->site->base->character_set_name());
    }

    #[DataProvider('zonesDeTexte')]
    public function testLesAccentsDesTextareasRestentStables(string $groupe, string $parametre): void
    {
        $this->verifierBanc();
        $this->verifierCycles($groupe, $parametre, self::STOCKAGE, self::TEXTE);
    }

    public function testLeChampCourtSitenameResteStable(): void
    {
        $this->verifierCycles('Affichage', 'SITENAME', self::STOCKAGE, self::TEXTE);
    }

    private function verifierCycles(string $groupe, string $parametre, string $stockage, string $texte): void
    {
        $this->stocker($parametre, $stockage);
        $avant = $this->octets($parametre);
        $client = $this->site->client('niveau9');
        $page = $client->get('/admin/gest_params.php', ['grp' => $groupe]);
        $textes = $octets = [];
        for ($tour = 0; $tour <= 5; $tour++) {
            [, , $champs] = $this->formulaire($page);
            $textes[] = $champs[$this->champ($champs, $parametre)];
            $octets[] = $this->octets($parametre);
            if ($tour < 5) {
                $page = $client->post('/admin/gest_params.php', $champs);
            }
        }
        self::assertSame(array_fill(0, 6, $avant), $octets, 'Aucune couche supplémentaire après cinq enregistrements');
        self::assertSame(array_fill(0, 6, $texte), $textes, 'Texte réel du contrôle, dès son ouverture');
    }

    #[DataProvider('zonesDeTexte')]
    public function testLeTexteLitteralEtLesBalisesRestentDansLaZone(string $groupe, string $parametre): void
    {
        $texte = '&eacute; "citation" <b>texte</b> </textarea><input name="intrus">';
        $stockage = '&amp;eacute; &quot;citation&quot; &lt;b&gt;texte&lt;/b&gt; &lt;/textarea&gt;&lt;input name=&quot;intrus&quot;&gt;';
        $this->verifierCycles($groupe, $parametre, $stockage, $texte);
        [$xpath, $formulaire] = $this->formulaire($this->site->client('niveau9')->get('/admin/gest_params.php', ['grp' => $groupe]));
        self::assertCount(0, $xpath->query('.//input[@name="intrus"]', $formulaire));
    }

    public function testLeModeleMailAccentueArriveSansEntites(): void
    {
        foreach (['SITENAME' => 'Soci&eacute;t&eacute; de l&rsquo;&eacute;t&eacute;', 'USER_AUTO_DEF' => '2', 'AUTO_CAPTCHA' => '0',
            'URL_PUBLIQUE' => 'https://site.example', 'USER_ZONE_LIBRE' => '', 'TXT_CONDIT_USAGE' => ''] as $nom => $valeur) {
            $this->stocker($nom, $valeur);
        }
        $admin = $this->site->client('niveau9');
        [, , $champs] = $this->formulaire($admin->get('/admin/gest_params.php', ['grp' => 'Mail']));
        $champs[$this->champ($champs, 'MAIL_AUTOUSER')] = 'Bonjour #PRENOM#, ' . self::TEXTE . '. Littéral : &eacute;. #NOMSITE#';
        $champs[$this->champ($champs, 'LOC_MAIL')] = 'administration@example.org';
        unset($champs[$this->champ($champs, 'EXTERN_MAIL')]);
        self::assertSame(200, $admin->post('/admin/gest_params.php', $champs)->statut);
        $avant = strlen($this->site->courriels());
        $page = $this->site->client('nouveau')->post('/cree_compte.php', [
            'action' => 'submitted', 'nom' => 'FICTIF', 'prenom' => 'Élodie', 'lelogin' => 'fictif_accents',
            'email' => 'accents@example.org', 'emailverif' => 'accents@example.org',
            'lepassw' => 'Fictif-Accents-2026', 'passwverif' => 'Fictif-Accents-2026',
        ]);
        self::assertSame(200, $page->statut);
        self::assertStringContainsString('un mail vous a été envoyé', $page->corps);
        $mail = substr($this->site->courriels(), $avant);
        self::assertSame(1, preg_match('/^Subject: (.+)$/m', $mail, $sujet));
        self::assertSame('Votre compte Société de l’été', iconv_mime_decode(trim($sujet[1]), ICONV_MIME_DECODE_STRICT, 'UTF-8'));
        $parties = preg_split('/\r?\n\r?\n/', $mail, 2);
        self::assertCount(2, $parties);
        self::assertStringContainsString('Content-Transfer-Encoding: 8bit', $parties[0]);
        self::assertStringContainsString('Bonjour Élodie, ' . self::TEXTE . '. Littéral : &eacute;. Société de l’été', $parties[1]);
    }

    /** Exporte et restaure une ligne existante sur le site jetable, sans l'exception d'insertion par constante. */
    private function restaurerEntitesNommees(): void
    {
        $this->stocker('PUB_ZONE_MENU', '&eacute; &ge; &rarr;');
        $avant = $this->octets('PUB_ZONE_MENU');
        $admin = $this->site->client('niveau9');
        $export = $admin->get('/admin/expparams.php', ['jeton' => Client::JETON]);
        self::assertSame(200, $export->statut);
        self::assertStringContainsString('<valeur>&amp;eacute; &amp;ge; &amp;rarr;</valeur>', $export->corps);
        $fichier = $this->site->racine . '/tmp/accents.xml';
        file_put_contents($fichier, $export->corps);
        $lignes = array_column(Sauvegarde::lire($fichier)->lignes('params'), null, 'param');
        self::assertSame('&eacute; &ge; &rarr;', $lignes['PUB_ZONE_MENU']['valeur']);
        $this->stocker('PUB_ZONE_MENU', 'Après export');
        try {
            $restauration = $admin->post('/admin/update_params.php', ['action' => 'submitted'], ['params' => $fichier]);
        } finally {
            unlink($fichier);
        }
        self::assertSame(200, $restauration->statut);
        self::assertStringNotContainsString('Restauration refusée', $restauration->corps);
        self::assertSame($avant, $this->octets('PUB_ZONE_MENU'));
    }

    public function testLaSauvegardeConserveLesOctetsDesEntitesNommees(): void
    {
        $this->restaurerEntitesNommees();
    }

    public function testLaValeurRestaureeEstDecodeeDansLeFormulaire(): void
    {
        $this->restaurerEntitesNommees();
        [, , $champs] = $this->formulaire($this->site->client('niveau9')->get('/admin/gest_params.php', ['grp' => 'Affichage']));
        self::assertSame('é ≥ →', $champs[$this->champ($champs, 'PUB_ZONE_MENU')]);
    }
}
