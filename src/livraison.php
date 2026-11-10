<?php
declare(strict_types=1);

require_once __DIR__ . '/autoload.php';

// La livraison est validée avant toute connexion ou écriture applicative.
(static function (): void {
    try {
        $livraison = \ActaLibre\Livraison::lire(dirname(__DIR__) . '/composer.json');
    } catch (\RuntimeException $erreur) {
        if (PHP_SAPI !== 'cli') {
            http_response_code(503);
            header('Content-Type: text/html; charset=UTF-8');
        }
        echo '<p class="erreur">' . htmlspecialchars($erreur->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        exit(1);
    }
    define('ACTALIBRE_VERSION', $livraison->version);
    define('ACTALIBRE_SCHEMA', $livraison->schema);
})();
