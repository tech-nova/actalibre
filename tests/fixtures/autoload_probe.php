<?php
// Sonde exécutée dans un processus PHP séparé, sans l'autoloader de Composer.
require __DIR__ . '/../../src/autoload.php';
echo class_exists(\ActaLibre\Import\Comparateur::class) ? 'ok' : 'ko';
echo class_exists('ActaLibre\\Inexistant\\Classe') ? 'ko' : 'ok';
echo class_exists('Autre\\Espace\\Classe') ? 'ko' : 'ok';
