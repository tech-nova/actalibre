<?php
declare(strict_types=1);
namespace ActaLibre\Parametres;

use ActaLibre\Db\Requete;
use ActaLibre\Install\Marqueurs;

final class EditionGroupe
{
    /** @param list<array{nom:string,valeur:string}> $propositions */
    public static function enregistrer(\mysqli $lien, string $prefixe, string $groupe, array $propositions): int
    {
        if (preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixe) !== 1 || in_array($groupe, ['Hidden', 'Deleted'], true)) { return 0; }
        $table = '`' . $prefixe . '_params`';
        $groupes = $lien->query('SELECT DISTINCT groupe FROM ' . $table);
        if (!$groupes instanceof \mysqli_result || !in_array($groupe, array_column($groupes->fetch_all(MYSQLI_ASSOC), 'groupe'), true)) { return 0; }
        $r = Requete::executer($lien, 'SELECT COUNT(*) FROM ' . $table . ' WHERE BINARY groupe = BINARY ?', [$groupe]);
        if (!$r instanceof \mysqli_result) { throw new \RuntimeException('Lecture des paramètres impossible.'); }
        $compte = $r->fetch_row();
        if ($compte === null) { throw new \RuntimeException('Comptage des paramètres impossible.'); }
        $limite = (int) $compte[0];
        $modifies = 0;
        foreach (array_slice($propositions, 0, $limite) as $p) {
            if (Marqueurs::ressemble($p['nom'])) { continue; }
            $r = Requete::executer($lien, 'SELECT param,groupe,type FROM ' . $table . ' WHERE param = ?', [$p['nom']]);
            if (!$r instanceof \mysqli_result) { throw new \RuntimeException('Lecture des paramètres impossible.'); }
            $ligne = $r->fetch_assoc();
            if ($ligne === null || $ligne === false || $ligne['groupe'] !== $groupe || Marqueurs::ressemble((string) $ligne['param'])) { continue; }
            $valeur = htmlentities($p['valeur'], ENT_COMPAT | ENT_XHTML, 'UTF-8');
            if ($valeur === '' && $ligne['type'] === 'B') { $valeur = '0'; }
            $ok = Requete::executer($lien, 'UPDATE ' . $table . ' SET valeur = ? WHERE BINARY param = BINARY ? AND BINARY groupe = BINARY ?', [trim($valeur), (string) $ligne['param'], $groupe]);
            if ($ok !== true) { throw new \RuntimeException('Enregistrement des paramètres impossible.'); }
            $nombre = $lien->affected_rows;
            if (!is_int($nombre) || $nombre < 0) { throw new \RuntimeException('Comptage des modifications impossible.'); }
            $modifies += $nombre;
        }
        return $modifies;
    }
}
