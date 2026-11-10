<?php
declare(strict_types=1);
namespace ActaLibre\Install;
use ActaLibre\Livraison;

final class RapportProtection
{
    public readonly string $date;
    /**
     * @param array<string,array{etat:string,http:?int,cause?:?string}> $sondes
     * @param list<string> $avis
     */
    public function __construct(public readonly string $etat, public readonly string $version, public readonly string $origine, public readonly array $sondes = [], public readonly array $avis = [], ?string $date = null)
    {
        $this->date = $date ?? gmdate('Y-m-d\TH:i:s\Z');
    }
    public function avertissement(): ?string
    {
        return $this->etat === 'verifie' ? null : 'Protection des fichiers privés non vérifiée. Consultez le contrôle des protections.';
    }
    public static function lire(string $racine): self
    {
        try {
            $version = Livraison::lire($racine.'/composer.json')->version;
            $p = $racine.'/tmp/actalibre/protection.json';
            foreach (['tmp','tmp/actalibre','tmp/actalibre/protection.json'] as $rel) { if (is_link($racine.'/'.$rel)) { throw new \RuntimeException(); } }
            $taille = @filesize($p);
            if ($taille === false || $taille > 32768 || !is_file($p)) { throw new \RuntimeException(); }
            $json = @file_get_contents($p);
            $d = json_decode($json === false ? '' : $json, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($d) || count($d) !== 7 || ($d['format'] ?? null) !== 1 || ($d['version'] ?? null) !== $version || !in_array($d['etat'] ?? null,['verifie','refuse','indetermine'],true) || !is_string($d['origine'] ?? null) || !is_string($d['date'] ?? null) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D',$d['date']) !== 1 || !is_array($d['sondes'] ?? null) || !is_array($d['avis'] ?? null) || !array_is_list($d['avis'])) { throw new \RuntimeException(); }
            if ($d['origine'] !== ControleProtection::origine($d['origine'])) { throw new \RuntimeException(); }
            $attendues=['public','tmp','tmp_php','configuration','css','installation','depot','composer','inventaire'];
            $reelles=array_keys($d['sondes']); sort($reelles); sort($attendues);
            if ($reelles !== $attendues) { throw new \RuntimeException(); }
            $sondes=[];
            foreach ($d['sondes'] as $nom=>$s) {
                $cause=$s['cause'] ?? null;
                if (!is_string($nom) || !is_array($s) || array_diff(array_keys($s),['etat','http','cause']) !== [] || !in_array($s['etat'] ?? null,['verifie','refuse','indetermine'],true) || !array_key_exists('http',$s) || ($s['http'] !== null && (!is_int($s['http']) || $s['http'] < 100 || $s['http'] > 599)) || ($cause !== null && !in_array($cause,ControleProtection::CAUSES,true))) { throw new \RuntimeException(); }
                $sondes[$nom]=['etat'=>$s['etat'],'http'=>$s['http'],'cause'=>$cause];
            }
            $avis=[];
            foreach ($d['avis'] as $a) { if (!is_string($a) || strlen($a)>1000) { throw new \RuntimeException(); } $avis[]=$a; }
            if ($d['etat'] === 'verifie' && ($sondes === [] || array_filter($sondes, static fn(array $s):bool=>$s['etat'] !== 'verifie') !== [])) { throw new \RuntimeException(); }
            return new self($d['etat'],$version,$d['origine'],$sondes,$avis,$d['date']);
        } catch (\Throwable) { return new self('indetermine','',''); }
    }
    public function enregistrer(string $racine): bool
    {
        if (!EspacePrive::dossier($racine,'tmp/actalibre')) { return false; }
        $final = EspacePrive::chemin($racine,'etat').'/protection.json';
        if (is_link($final) || (file_exists($final) && !is_file($final))) { return false; }
        $temp = dirname($final).'/.protection-'.bin2hex(random_bytes(12)).'.tmp';
        $f = @fopen($temp,'x');
        if ($f === false) { return false; }
        try {
            @chmod($temp,0600);
            $json = json_encode(['format'=>1,'version'=>$this->version,'date'=>$this->date,'origine'=>$this->origine,'etat'=>$this->etat,'sondes'=>$this->sondes,'avis'=>$this->avis], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n";
            $ok = fwrite($f,$json) === strlen($json) && fflush($f);
            fclose($f);
            return $ok && @rename($temp,$final);
        } finally { if (is_resource($f)) { fclose($f); } if (is_file($temp)) { @unlink($temp); } }
    }
}
