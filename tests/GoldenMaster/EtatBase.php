<?php
declare(strict_types=1);

namespace ActaLibre\Tests\GoldenMaster;

/** Résume les tables gm_* : nombre de lignes et empreinte du contenu trié. */
final class EtatBase
{
    private const NON_COMPAREES = ['gm_acte_vu', 'gm_courriel', 'gm_essai', 'gm_log', 'gm_reinit', 'gm_session', 'gm_traceip'];

    public static function resumer(\mysqli $base, Normaliseur $normaliseur): string
    {
        $resume = '';
        foreach ($base->query("SHOW TABLES LIKE 'gm\\_%'")->fetch_all() as [$table]) {
            if (in_array($table, self::NON_COMPAREES, true)) {
                continue;
            }
            $lignes = [];
            foreach ($base->query("SELECT * FROM `$table`")->fetch_all(MYSQLI_ASSOC) as $ligne) {
                // Le sel change à chaque exécution.
                unset($ligne['hashpass']);
                $lignes[] = $normaliseur->normaliser(json_encode(array_values($ligne), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
            }
            sort($lignes);
            $resume .= sprintf("%s %d %s\n", $table, count($lignes), hash('sha256', implode("\n", $lignes)));
        }
        return $resume;
    }
}
