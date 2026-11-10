<?php
declare(strict_types=1);

namespace ActaLibre\Tests\GoldenMaster;

use PHPUnit\Framework\TestCase;

/** Un acte n'est décompté qu'une fois par jour, d'après la liste tenue par le serveur. */
final class PointsParcoursTest extends TestCase
{
    private SiteJetable $site;
    /** @var array{xid:int,xct:int} */
    private array $acte;

    protected function setUp(): void
    {
        $hote = getenv('GM_DB_HOST');
        $nom = getenv('GM_DB_NAME');
        $utilisateur = getenv('GM_DB_USER');
        $passe = getenv('GM_DB_PASS');
        if ($hote === false || $nom === false || $utilisateur === false || $passe === false) {
            self::markTestSkipped('PointsParcoursTest ignoré : définir GM_DB_HOST, GM_DB_NAME, GM_DB_USER et GM_DB_PASS.');
        }
        $this->site = SiteJetable::partage(['hote' => $hote, 'nom' => $nom, 'utilisateur' => $utilisateur, 'passe' => $passe]);
        $this->site->base->query("UPDATE gm_params SET valeur = '1' WHERE param = 'GEST_POINTS'");
        $this->site->base->query("UPDATE gm_params SET valeur = '1' WHERE param = 'PUBLIC_LEVEL'");
        $this->site->base->query("UPDATE gm_user3 SET level = 4, regime = 1, solde = 5 WHERE login = 'fictif_public'");
        [$id, $nomActe, $prenom] = $this->site->base->query('SELECT ID, NOM, PRE FROM gm_nai3 ORDER BY ID LIMIT 1')->fetch_row();
        $this->acte = ['xid' => (int) $id, 'xct' => (ord($nomActe[0]) + 3) * (ord($prenom[0]) + 7)];
    }

    private function solde(): int
    {
        return (int) $this->site->base->query("SELECT solde FROM gm_user3 WHERE login = 'fictif_public'")->fetch_row()[0];
    }

    private function nouveauNavigateur(): Client
    {
        $client = $this->site->client('nouveau');
        $client->post('/acces.php', ['login' => SiteJetable::COMPTES['niveau1'][0], 'passwd' => SiteJetable::COMPTES['niveau1'][1]]);
        return $client;
    }

    public function testUnActeNEstDecompteQuUneFoisMemeDepuisUnAutreNavigateur(): void
    {
        $premier = $this->nouveauNavigateur();
        self::assertStringContainsString('Il vous reste à présent 4 points', $premier->get('/acte_naiss.php', $this->acte)->corps);
        self::assertSame(4, $this->solde());
        self::assertStringContainsString('Déjà examiné ce jour', $premier->get('/acte_naiss.php', $this->acte)->corps);
        self::assertSame(4, $this->solde());
        self::assertStringContainsString('Déjà examiné ce jour', $this->nouveauNavigateur()->get('/acte_naiss.php', $this->acte)->corps);
        self::assertSame(4, $this->solde());
    }

    public function testLeLendemainLActeEstDecompteDeNouveauEtLaVeilleEstPurgee(): void
    {
        $client = $this->nouveauNavigateur();
        $client->get('/acte_naiss.php', $this->acte);
        $this->site->base->query('UPDATE gm_acte_vu SET jour = jour - INTERVAL 1 DAY');
        $client->get('/acte_naiss.php', $this->acte);
        self::assertSame(3, $this->solde());
        self::assertSame('1', $this->site->base->query('SELECT COUNT(*) FROM gm_acte_vu')->fetch_row()[0]);
    }

    public function testUnSoldeEpuiseNEnregistreRien(): void
    {
        $this->site->base->query("UPDATE gm_user3 SET solde = 0 WHERE login = 'fictif_public'");
        $page = $this->nouveauNavigateur()->get('/acte_naiss.php', $this->acte);
        self::assertStringContainsString('Votre solde de points est épuisé', $page->corps);
        self::assertSame('0', $this->site->base->query('SELECT COUNT(*) FROM gm_acte_vu')->fetch_row()[0]);
    }

    public function testUnCompteImmuniseNEcritAucuneLigne(): void
    {
        $this->site->base->query("UPDATE gm_user3 SET regime = 0 WHERE login = 'fictif_public'");
        $this->nouveauNavigateur()->get('/acte_naiss.php', $this->acte);
        self::assertSame('0', $this->site->base->query('SELECT COUNT(*) FROM gm_acte_vu')->fetch_row()[0]);
    }

    public function testUneErreurDeBaseNeDonneNiActeNiDecompte(): void
    {
        $this->site->base->query('DROP TABLE gm_acte_vu');
        $page = $this->nouveauNavigateur()->get('/acte_naiss.php', $this->acte);
        self::assertStringContainsString('Erreur dans la gestion des points', $page->corps);
        self::assertSame(5, $this->solde());
    }

    public function testLAncienCookieEstSansEffetEtEfface(): void
    {
        $client = $this->nouveauNavigateur();
        $client->cookie('viewlst', 'ancienne-liste');
        $page = $client->get('/acte_naiss.php', $this->acte);
        self::assertSame(4, $this->solde());
        self::assertMatchesRegularExpression('/^Set-Cookie: viewlst=(?:deleted|);/mi', $page->entetes);
    }
}
