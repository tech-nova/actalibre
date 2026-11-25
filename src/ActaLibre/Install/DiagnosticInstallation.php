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
        'fichier_absent'=>'fichier absent',
        'chemin_interdit'=>'chemin non autorisé (dossier ou lien symbolique)',
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
    /**
     * L'état seul ne dit pas si l'essai a été exécuté : la cause précise pourquoi il reste sans conclusion.
     * @param array{etat:string,http:?int,cause?:?string} $sonde
     */
    private static function resultatSonde(array $sonde): string
    {
        if ($sonde['etat']!=='indetermine') { return ucfirst(self::ETATS[$sonde['etat']] ?? $sonde['etat']); }
        return match ($sonde['cause'] ?? null) {
            'public','budget','curl' => 'Non testé',
            'non_prepare','fichier_absent','chemin_interdit','delai','connexion','tls','transport' => 'Non vérifiable',
            default => 'Indéterminé',
        };
    }
    /** Bilan d'exécution distinct de la protection : une exposition reste un résultat concluant. */
    private static function bilanControle(RapportProtection $r): string
    {
        $total=count($r->sondes);
        if ($total===0) { return 'Aucun résultat de contrôle disponible'; }
        $concluants=0;$nonTestes=0;
        foreach ($r->sondes as $s) {
            if (in_array($s['etat'],['verifie','refuse'],true)) { $concluants++; }
            elseif (self::resultatSonde($s)==='Non testé') { $nonTestes++; }
        }
        if ($nonTestes===$total) { return 'Aucun essai HTTP réalisé'; }
        if ($concluants===0) { return 'Contrôle sans résultat concluant'; }
        return 'Contrôle '.($concluants===$total ? 'complet' : 'partiel').' : '.$concluants.' '.($concluants===1 ? 'essai concluant' : 'essais concluants').' sur '.$total;
    }
    private static function sondes(RapportProtection $r): string
    {
        if ($r->sondes === []) { return ''; }
        $quand=self::dateLocale($r->date,'\l\e d/m/Y \à H\hi');
        $html='<p>Dernier contrôle : '.Filtre::html($quand ?? $r->date).', adresse '.Filtre::html($r->origine).'</p><table><tr><th>Essai</th><th>Résultat</th><th>HTTP</th><th>Cause</th></tr>';
        foreach ($r->sondes as $nom=>$s) {
            $cause=$s['cause'] ?? null;
            $explication=$cause === null ? '' : (self::CAUSES[$cause] ?? $cause);
            // Ces deux fichiers existent dans la livraison : aucun témoin n'est créé pour eux.
            if ($cause==='non_prepare' && in_array($nom,['composer','inventaire'],true)) { $explication='fichier absent ou chemin non autorisé'; }
            $html.='<tr><td>'.Filtre::html(self::SONDES[$nom] ?? $nom).'</td><td>'.Filtre::html(self::resultatSonde($s)).'</td><td>'.($s['http'] === null ? 'x' : (int)$s['http']).'</td><td>'.Filtre::html($explication).'</td></tr>';
        }
        return $html.'</table>';
    }
    /** $page : adresse de la page de contrôle, proposée hors de l'administration. */
    public static function html(string $racine,string $base,string $depot,string $page=''): string
    {
        $r=RapportProtection::lire($racine);
        $html='<h2>Protection et fichiers du site</h2>';
        $alerte=match (true) {
            $r->sondes===[] => 'Aucun résultat de contrôle disponible. La protection des fichiers privés reste à vérifier.',
            $r->etat==='refuse' => 'Des fichiers privés sont accessibles publiquement.',
            $r->etat==='indetermine' => 'Certaines protections n’ont pas pu être vérifiées.',
            default => null,
        };
        if ($alerte!==null) { $html.='<p class="erreur" role="alert">'.Filtre::html($alerte).'</p>'; }
        if ($r->sondes!==[]) { $html.='<p>'.Filtre::html(self::bilanControle($r)).'.</p>'; }
        foreach ($r->avis as $avis) { $html.='<p>'.Filtre::html($avis).'</p>'; }
        $html.=self::sondes($r);
        if ($page!=='') {
            $libelle=$r->sondes===[] ? 'Contrôler les protections' : 'Relancer le contrôle';
            $html.='<p><a href="'.Filtre::html($page).'">'.$libelle.'</a></p>';
        }
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
        if ($r->version==='') { $protection='Aucun résultat de contrôle disponible'; }
        else {
            $jourControle=self::dateLocale($r->date,'d/m/Y');
            $date=$jourControle===null ? '' : ' le '.$jourControle;
            $protection=$r->etat==='indetermine' ? self::bilanControle($r).$date : 'État : '.(self::ETATS[$r->etat] ?? $r->etat).$date;
            if ($r->etat==='refuse') { $protection.=' ; '.self::bilanControle($r); }
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
