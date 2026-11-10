<?php
declare(strict_types=1);

// composer rector-simulation doit rester sans proposition.

use Rector\Config\RectorConfig;
use Rector\Php71\Rector\FuncCall\RemoveExtraParametersRector;
use Rector\Php80\Rector\Switch_\ChangeSwitchToMatchRector;

$racine = dirname(__DIR__, 2);

return RectorConfig::configure()
    ->withPaths([...glob($racine . '/*.php'), $racine . '/admin', $racine . '/install', $racine . '/tools'])
    ->withSkip([
        $racine . '/tools/MakeRss',
        $racine . '/tools/adodb-time.inc.php',
        // Plusieurs pages définissent leur propre init_page($head) : la règle prend l'une pour l'autre et retire l'argument.
        RemoveExtraParametersRector::class,
        // Gain faible : match compare strictement des valeurs venues de la requête ou de la base.
        ChangeSwitchToMatchRector::class,
    ])
    ->withPhpSets(php83: true);
