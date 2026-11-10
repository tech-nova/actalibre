<?php
declare(strict_types=1);

// Autoloader PSR-4 d'ActaLibre pour la production (sans Composer).
// ActaLibre\Foo\Bar -> src/ActaLibre/Foo/Bar.php
spl_autoload_register(static function (string $classe): void {
    $prefixe = 'ActaLibre\\';
    if (strncmp($classe, $prefixe, strlen($prefixe)) !== 0) {
        return;
    }
    $relatif = str_replace('\\', '/', substr($classe, strlen($prefixe)));
    $fichier = __DIR__ . '/ActaLibre/' . $relatif . '.php';
    if (is_file($fichier)) {
        require $fichier;
    }
});
