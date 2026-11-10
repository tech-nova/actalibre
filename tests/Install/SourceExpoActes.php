<?php
declare(strict_types=1);
namespace ActaLibre\Tests\Install;

/** Sources GPL inchangées de 1a9d499 ; tables modernes volontairement absentes. */
final class SourceExpoActes
{
    public const MOT_DE_PASSE = 'ancien-secret-1234';
    public static function creer(\mysqli $actes, string $prefixeActes, \mysqli $comptes, string $prefixeComptes): void
    {
        foreach ([$prefixeActes, $prefixeComptes] as $prefixe) {
            if (!preg_match('/\Aal[34]_[a-f0-9]{12}(?:u)?\z/', $prefixe)) throw new \LogicException('Préfixe de fixture non jetable');
        }
        $sql = (string) file_get_contents(dirname(__DIR__) . '/fixtures/expoactes-3.2.4-schema.sql');
        foreach (preg_split('/;\s*(?=CREATE TABLE|INSERT INTO|$)/', trim($sql)) as $requete) {
            if (trim($requete) === '') continue;
            $comptesSeuls = str_contains($requete, 'EA_DB_user3');
            $requete = str_replace('EA_DB_', ($comptesSeuls ? $prefixeComptes : $prefixeActes) . '_', $requete);
            ($comptesSeuls ? $comptes : $actes)->query($requete);
        }
        $parseur = xml_parser_create();
        xml_parse_into_struct($parseur, (string) file_get_contents(dirname(__DIR__) . '/fixtures/expoactes-3.2.4-params.xml'), $noeuds);
        xml_parser_free($parseur);
        $ligne = [];
        $inserer = $actes->prepare("INSERT INTO `{$prefixeActes}_params` (param,groupe,ordre,type,valeur,listval,libelle,aide) VALUES (?,?,?,?,?,?,?,?)");
        foreach ($noeuds as $n) {
            if ($n['tag'] === 'ACT_PARAMS' && $n['type'] === 'open') $ligne = [];
            elseif ($n['tag'] === 'ACT_PARAMS' && $n['type'] === 'close') {
                $inserer->execute(array_map(static fn($k) => trim(str_replace('\\n', "\n", $ligne[$k] ?? '')), ['PARAM','GROUPE','ORDRE','TYPE','VALEUR','LISTVAL','LIBELLE','AIDE']));
            } elseif ($n['type'] === 'complete') $ligne[$n['tag']] = $n['value'] ?? '';
        }
        $actes->query("UPDATE `{$prefixeActes}_params` SET valeur=' 3.2.4 ' WHERE param='EA_VERSION'");
        $actes->query("UPDATE `{$prefixeActes}_params` SET valeur='mail-personnalise@example.org' WHERE param='LOC_MAIL'");
        $q = $comptes->prepare("INSERT INTO `{$prefixeComptes}_user3` (ID,login,hashpass,level,statut) VALUES (1,'admin',?,9,'N'),(2,'lecteur',?,1,'N')");
        $q->execute([sha1(self::MOT_DE_PASSE), sha1('mot-de-passe-lecteur')]);
        $actes->query("INSERT INTO `{$prefixeActes}_nai3` (ID,DEPOSANT,NOM) VALUES (1,2,'CONSERVE')");
    }
    public static function supprimer(\mysqli $lien, string $prefixe): void
    {
        foreach ($lien->query('SHOW TABLES')->fetch_all() as [$table]) {
            if (str_starts_with($table, $prefixe . '_')) $lien->query("DROP TABLE `$table`");
        }
    }
}
