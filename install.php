<?php

declare (strict_types=1);
require_once __DIR__ . '/src/autoload.php';
\ActaLibre\Install\PageInstallation::afficher(__DIR__);
if (PHP_SAPI === 'cli' && http_response_code() >= 400) {
    exit(1);
}
