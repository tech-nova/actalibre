<?php
declare(strict_types=1);

namespace ActaLibre\Tests\Carte;

use ActaLibre\Carte\Geocodeur;
use PHPUnit\Framework\TestCase;

final class GeocodeurTest extends TestCase
{
    public function testUnLieuTrouveDonneSesCoordonnees(): void
    {
        $adresse = '';
        $geocodeur = new Geocodeur('https://exemple.org/search', static function (string $url) use (&$adresse): string {
            $adresse = $url;
            return '[{"lat":"48.85","lon":"2.35"}]';
        });

        self::assertSame(['lon' => 2.35, 'lat' => 48.85], $geocodeur->chercher("L'Isle & Fils, Hautes-Brumes"));
        self::assertStringStartsWith('https://exemple.org/search?format=jsonv2&limit=1&q=', $adresse);
        self::assertStringContainsString('q=L%27Isle+%26+Fils', $adresse, 'Le nom est encodé');
    }

    public function testUnLieuIntrouvableDonneNull(): void
    {
        $geocodeur = new Geocodeur('https://exemple.org/search', static fn (string $url): string => '[]');
        self::assertNull($geocodeur->chercher('Vauclairmont'));
    }

    /** @return iterable<string,array{\Closure(string):string}> */
    public static function pannes(): iterable
    {
        yield 'réseau' => [static function (string $url): string {
            throw new \RuntimeException('coupure');
        }];
        yield 'réponse illisible' => [static fn (string $url): string => '<html>'];
        yield 'réponse incomplète' => [static fn (string $url): string => '[{"lat":"48.85"}]'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pannes')]
    public function testUnePanneNestPasConfondueAvecUnLieuIntrouvable(\Closure $transport): void
    {
        $this->expectException(\RuntimeException::class);
        (new Geocodeur('https://exemple.org/search', $transport))->chercher('Vauclairmont');
    }

    public function testDeuxRequetesSontEspaceesDUneSeconde(): void
    {
        $geocodeur = new Geocodeur('https://exemple.org/search', static fn (string $url): string => '[]');
        $debut = microtime(true);
        $geocodeur->chercher('A');
        $geocodeur->chercher('B');
        self::assertGreaterThanOrEqual(0.95, microtime(true) - $debut);
    }

    public function testLeServiceParDefautEstEnHttps(): void
    {
        self::assertStringStartsWith('https://', Geocodeur::ADRESSE);
    }

    public function testApresUnePanneLeServiceNestPlusAppele(): void
    {
        $appels = 0;
        $geocodeur = new Geocodeur('https://exemple.org/search', static function (string $url) use (&$appels): string {
            $appels++;
            throw new \RuntimeException('coupure');
        });

        foreach (['A', 'B', 'C'] as $lieu) {
            try {
                $geocodeur->chercher($lieu);
                self::fail('Une panne est signalée');
            } catch (\RuntimeException $panne) {
                self::assertNotSame('', $panne->getMessage());
            }
        }

        self::assertSame(1, $appels);
    }

    public function testUnLieuIntrouvableNArretePasLesRecherches(): void
    {
        $appels = 0;
        $geocodeur = new Geocodeur('https://exemple.org/search', static function (string $url) use (&$appels): string {
            $appels++;
            return '[]';
        });

        $geocodeur->chercher('A');
        $geocodeur->chercher('B');

        self::assertSame(2, $appels);
    }

    public function testLIdentifiantNommeLeLogicielEtLeSite(): void
    {
        self::assertSame('ActaLibre/4.0.2 (https://exemple.org/actes)', Geocodeur::identifiant('4.0.2', 'https://exemple.org/actes'));
    }

    public function testLIdentifiantTientSurUneLigne(): void
    {
        self::assertSame('ActaLibre/4.0.2 (https://exemple.org)', Geocodeur::identifiant('4.0.2', "https://exemple.org\r\nX-Autre: valeur"));
    }

    public function testUnRefusDeDebitDonneLaPauseDemandee(): void
    {
        try {
            Geocodeur::controler(['HTTP/1.1 429 Too Many Requests', 'Retry-After: 120', 'Content-Type: application/json']);
            self::fail('Un refus de débit est signalé');
        } catch (\ActaLibre\Carte\RefusDebit $refus) {
            self::assertSame(120, $refus->attente);
        }
    }

    public function testUnServiceIndisponibleSansDelaiEstUnRefusSansAttente(): void
    {
        try {
            Geocodeur::controler(['HTTP/1.1 503 Service Unavailable']);
            self::fail('Un refus est signalé');
        } catch (\ActaLibre\Carte\RefusDebit $refus) {
            self::assertNull($refus->attente);
        }
    }

    public function testUneAutreErreurHttpEstUnePanne(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 500');
        Geocodeur::controler(['HTTP/1.1 500 Internal Server Error']);
    }

    public function testApresUneRedirectionSeulLeDernierStatutCompte(): void
    {
        Geocodeur::controler(['HTTP/1.1 301 Moved Permanently', 'Location: https://exemple.org/', 'HTTP/1.1 200 OK']);
        $this->addToAssertionCount(1);
    }

    public function testUnRefusTransmisParLeTransportGardeSonType(): void
    {
        $geocodeur = new Geocodeur('https://exemple.org/search', static function (string $url): string {
            throw new \ActaLibre\Carte\RefusDebit('trop vite', 30);
        });
        $this->expectException(\ActaLibre\Carte\RefusDebit::class);
        $geocodeur->chercher('Vauclairmont');
    }
}
