<?php
declare(strict_types=1);
namespace ActaLibre\Install;
use ActaLibre\Db\Requete;
use ActaLibre\Securite\Filtre;

/** Diagnostics informatifs, appelés seulement après une opération autorisée. */
final class DiagnosticInstallation
{
    public static function depot(\mysqli $lien, string $prefixe): string
    {
        if (preg_match('/\A[A-Za-z0-9_]+\z/D',$prefixe)!==1) { throw new \RuntimeException('Préfixe invalide.'); }
        $r=Requete::executer($lien,'SELECT valeur FROM `'.$prefixe.'_params` WHERE param=?',['UPLOAD_DIR']);
        if (!$r instanceof \mysqli_result) { throw new \RuntimeException('Lecture du dépôt impossible.'); }
        $ligne=$r->fetch_row();
        return $ligne===null ? '_upload' : html_entity_decode((string)$ligne[0],ENT_COMPAT|ENT_XHTML,'UTF-8');
    }
    public static function controler(string $racine,string $url,bool $migration=false,string $depot='_upload'): RapportProtection
    {
        try {
            $rapport=(new ControleProtection($racine,null,$depot))->executer($url,$migration);
            if (!$rapport->enregistrer($racine)) {
                return new RapportProtection('indetermine',$rapport->version,$rapport->origine,$rapport->sondes,['Rapport de protection non enregistré.']);
            }
            return $rapport;
        } catch (\Throwable) { return new RapportProtection('indetermine','','',[],['Contrôle de protection indisponible.']); }
    }
    public const ETATS = ['verifie'=>'vérifiée','refuse'=>'refusée','indetermine'=>'indéterminée'];
    private const SONDES = [
        'public'=>'Fichier public témoin (doit être servi)',
        'tmp'=>'Dossier tmp/',
        'tmp_php'=>'Script PHP dans tmp/',
        'configuration'=>'Dossier _config/',
        'css'=>'Feuille de style dans _config/ (doit être servie)',
        'installation'=>'Données de install/',
        'depot'=>'Dossier de dépôt de admin/',
        'composer'=>'composer.json',
        'inventaire'=>'inventaire-actalibre.json',
    ];
    private const CAUSES = [
        'non_prepare'=>'témoin non déposé (dossier absent ou non inscriptible)',
        'budget'=>'durée totale du contrôle dépassée',
        'curl'=>'extension PHP cURL absente',
        'delai'=>'délai de réponse dépassé',
        'connexion'=>'serveur injoignable depuis lui-même (DNS ou connexion)',
        'tls'=>'certificat HTTPS refusé',
        'transport'=>'requête interrompue',
        'statut'=>'code HTTP inattendu',
        'contenu'=>'réponse 200 sans le contenu du témoin',
        'public'=>'non testé : le fichier public témoin n’a pas été vérifié',
    ];
    private static function sondes(RapportProtection $r): string
    {
        if ($r->sondes === []) { return ''; }
        $quand=self::dateLocale($r->date,'\l\e d/m/Y \à H\hi');
        $html='<p>Dernier contrôle : '.Filtre::html($quand ?? $r->date).', adresse '.Filtre::html($r->origine).'</p><table><tr><th>Essai</th><th>Résultat</th><th>HTTP</th><th>Cause</th></tr>';
        foreach ($r->sondes as $nom=>$s) {
            $cause=$s['cause'] ?? null;
            $html.='<tr><td>'.Filtre::html(self::SONDES[$nom] ?? $nom).'</td><td>'.Filtre::html(self::ETATS[$s['etat']] ?? $s['etat']).'</td><td>'.($s['http'] === null ? '—' : (int)$s['http']).'</td><td>'.Filtre::html($cause === null ? '' : (self::CAUSES[$cause] ?? $cause)).'</td></tr>';
        }
        return $html.'</table>';
    }
    public static function html(string $racine,string $base,string $depot): string
    {
        $r=RapportProtection::lire($racine);
        $html='<h2>Protection et fichiers du site</h2><p>Contrôle : '.Filtre::html(self::ETATS[$r->etat] ?? $r->etat).'.</p>';
        if ($r->avertissement()!==null) { $html.='<p class="erreur">'.Filtre::html($r->avertissement()).'</p>'; }
        foreach ($r->avis as $avis) { $html.='<p>'.Filtre::html($avis).'</p>'; }
        $html.=self::sondes($r);
        $analyse=self::analyserFichiers($racine,$base,$depot);
        if ($analyse['etat']==='depot') {
            return $html.'<p>Analyse des fichiers non réalisée : réglage UPLOAD_DIR invalide. Indiquez un chemin relatif interne au dossier admin, sans « .. ».</p>';
        }
        if ($analyse['etat']==='inventaire') {
            return $html.'<p>Analyse des fichiers non réalisée : inventaire absent, invalide ou copie incomplète. Vérifiez la livraison avant de recommencer.</p>';
        }
        $liste='';
        foreach ($analyse['chemins'] as $f) { $liste.='<li><code>'.Filtre::html($f['chemin']).'</code> ('.Filtre::html($f['type']).')</li>'; }
        $html.='<h3>Fichiers non reconnus</h3><p>Aucun fichier inconnu n’est déplacé ou supprimé. Vérifiez-les puis retirez-les par FTP ; consultez INSTALL.md.</p>';
        $html.=$analyse['chemins']===[] ? '<p>Aucun chemin inconnu détecté.</p>' : '<ul>'.$liste.'</ul>';
        return $html;
    }
    /**
     * Noms et types seulement, comme la page de contrôle ; aucun fichier inconnu n'est ouvert.
     * @return array{etat:'ok'|'depot'|'inventaire',chemins:list<array{chemin:string,type:string}>}
     */
    private static function analyserFichiers(string $racine,string $base,string $depot): array
    {
        try {
            $i=Inventaire::lire($racine);
            try { $regles=new Conservation($i,$base,$depot); }
            catch (\RuntimeException) { return ['etat'=>'depot','chemins'=>[]]; }
            $chemins=[];
            foreach ((new SignalementFichiers($racine,$i,$regles))->lister() as $f) { $chemins[]=$f; }
            return ['etat'=>'ok','chemins'=>$chemins];
        } catch (\Throwable) { return ['etat'=>'inventaire','chemins'=>[]]; }
    }
    /** Date UTC du rapport, affichée dans le fuseau du serveur ; null si illisible. */
    private static function dateLocale(string $iso,string $format): ?string
    {
        try { return (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format($format); }
        catch (\Exception) { return null; }
    }
    /**
     * Résumé en texte brut pour le groupe de paramètres « Protection ».
     * @return array{protection:string,fichiers:string}
     */
    public static function resume(string $racine,string $base,string $depot,?\DateTimeImmutable $maintenant=null): array
    {
        $zone=new \DateTimeZone(date_default_timezone_get());
        $maintenant=($maintenant ?? new \DateTimeImmutable())->setTimezone($zone);
        $r=RapportProtection::lire($racine);
        if ($r->version==='') { $protection='État : non contrôlé'; }
        else {
            $jourControle=self::dateLocale($r->date,'d/m/Y');
            $date=$jourControle===null ? '' : ' le '.$jourControle;
            $protection='État : '.(self::ETATS[$r->etat] ?? $r->etat).$date;
        }
        $analyse=self::analyserFichiers($racine,$base,$depot);
        $jour=' le '.$maintenant->format('d/m/Y');
        $n=count($analyse['chemins']);
        $fichiers=match (true) {
            $analyse['etat']==='depot' => 'Analyse non réalisée : réglage UPLOAD_DIR invalide',
            $analyse['etat']==='inventaire' => 'Analyse non réalisée : inventaire absent ou invalide',
            $n===0 => 'Aucun chemin inconnu détecté'.$jour,
            $n===1 => '1 chemin inconnu détecté'.$jour,
            default => $n.' chemins inconnus détectés'.$jour,
        };
        return ['protection'=>$protection,'fichiers'=>$fichiers];
    }
}
