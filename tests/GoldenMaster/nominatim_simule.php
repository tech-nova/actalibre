<?php
// Nominatim simulé pour les parcours : journalise chaque appel (horodatage et requête) et répond selon le nom.
declare(strict_types=1);

$requete = is_string($_GET['q'] ?? null) ? $_GET['q'] : '';
file_put_contents((string) getenv('NOMINATIM_JOURNAL'), sprintf("%.3f\t%s\n", microtime(true), $requete), FILE_APPEND | LOCK_EX);
header('Content-Type: application/json');
if (str_contains($requete, 'Saturee')) {
    http_response_code(429);
    header('Retry-After: 120');
    echo '[]';
    return;
}
if (str_contains($requete, 'Introuvable')) {
    echo '[]';
    return;
}
echo '[{"lat":"48.8566","lon":"2.3522"}]';
