<?php
declare(strict_types=1);

// Régénère le jeu de données fictif : php tests/Donnees/generer.php
// Le contenu est entièrement calculé avant la première écriture : une erreur ne laisse aucun fichier partiel.

use ActaLibre\Tests\Donnees\GenerateurDonnees;

require __DIR__ . '/GenerateurDonnees.php';

try {
    $fichiers = (new GenerateurDonnees(__DIR__ . '/../../install/creetables.sql'))->fichiers();
} catch (\LengthException | \DomainException $erreur) {
    fwrite(STDERR, 'Génération interrompue : ' . $erreur->getMessage() . "\n");
    exit(1);
}
foreach ($fichiers as $nom => $contenu) {
    file_put_contents(__DIR__ . '/' . $nom, $contenu);
}
echo count($fichiers) . " fichiers écrits\n";
