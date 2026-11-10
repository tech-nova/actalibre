<?php
declare(strict_types=1);
namespace ActaLibre\Install;
use ActaLibre\Livraison;

/** Sondes bornées, avec témoins aléatoires possédés par ce seul appel. */
final class ControleProtection
{
    public const BLOC = "# BEGIN ACTALIBRE PROTECTION\n<FilesMatch \"^(composer\\..*|inventaire-actalibre\\.json)$\">\n    <IfModule mod_authz_core.c>\n        Require all denied\n    </IfModule>\n    <IfModule !mod_authz_core.c>\n        Order allow,deny\n        Deny from all\n    </IfModule>\n</FilesMatch>\n# END ACTALIBRE PROTECTION\n";
    /** Budget d'un passage complet, délai d'une sonde et de son établissement de connexion (ms). */
    public const BUDGET_MS = 10000;
    public const SONDE_MS = 2000;
    public const CONNEXION_MS = 1000;
    /** Causes enregistrées pour une sonde non vérifiée ; aucune ne contient de corps de réponse. */
    public const CAUSES = ['non_prepare','budget','curl','delai','connexion','tls','transport','statut','contenu','public'];
    private readonly string $depot;
    /** @var non-empty-string */
    private string $agent = 'ActaLibre';
    /** @param null|\Closure(string,int):array{http:?int,corps:string,erreur:?string} $transport */
    public function __construct(private readonly string $racine, private readonly ?\Closure $transport = null, string $uploadDir = '_upload')
    {
        if ($uploadDir === '' || str_starts_with($uploadDir,'/') || str_contains($uploadDir,'\\') || str_contains($uploadDir,"\0") || array_intersect(explode('/',$uploadDir),['','.','..']) !== []) { throw new \InvalidArgumentException('Répertoire de dépôt invalide.'); }
        $this->depot = 'admin/'.$uploadDir;
    }
    public static function origine(string $url): string
    {
        $p = parse_url($url);
        if ($p === false || !in_array($p['scheme'] ?? null,['http','https'],true) || !isset($p['host']) || !InstallGuard::nomHoteValide($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || isset($p['port']) && $p['port'] < 1 || preg_match('/[\x00-\x20\x7f\\\\]/',$url) === 1) { throw new \InvalidArgumentException('Adresse canonique HTTP(S) invalide.'); }
        $path = $p['path'] ?? '/'; $decode=rawurldecode($path);
        if (!str_starts_with($path,'/') || preg_match('/[\x00-\x20\x7f\\\\?#]/',$decode) === 1 || array_intersect(explode('/',$decode),['.','..']) !== []) { throw new \InvalidArgumentException('Chemin canonique invalide.'); }
        return $p['scheme'].'://'.strtolower($p['host']).(isset($p['port']) ? ':'.$p['port'] : '').rtrim($path,'/').'/';
    }
    public function executer(string $urlSite, bool $appendMigration): RapportProtection
    {
        try { $version=Livraison::lire($this->racine.'/composer.json')->version; $url=self::origine($urlSite); }
        catch (\Throwable) { return new RapportProtection('indetermine','','',[],['Adresse canonique ou livraison indisponible.']); }
        // Certains hébergeurs ralentissent volontairement les requêtes sans User-Agent.
        $this->agent='ActaLibre/'.$version.' (controle des protections)';
        $avis=[];
        $sondes=$this->sonder($url,microtime(true));
        $etat=self::etat($sondes);
        if ($appendMigration && $etat !== 'verifie' && str_starts_with((string)($_SERVER['SERVER_SOFTWARE'] ?? ''),'Apache')) {
            $ajoute=$this->ajouterBloc();
            if ($ajoute === 'ajoute') { $sondes=$this->sonder($url,microtime(true)); $etat=self::etat($sondes); }
            elseif ($ajoute !== 'present') { $avis[]='Protection racine non complétée : vérification manuelle nécessaire.'; }
        }
        return new RapportProtection($etat,$version,$url,$sondes,$avis);
    }
    /** @param array<string,array{etat:string,http:?int,cause?:?string}> $sondes */
    private static function etat(array $sondes): string
    {
        $etats=array_column($sondes,'etat');
        return in_array('refuse',$etats,true) ? 'refuse' : (in_array('indetermine',$etats,true) || $sondes === [] ? 'indetermine' : 'verifie');
    }
    /** @return array<string,array{etat:string,http:?int,cause:?string}> */
    private function sonder(string $url, float $debut): array
    {
        $nonce=bin2hex(random_bytes(16)); $nom='actalibre-controle-'.$nonce; $temoins=[]; $r=[];
        $cibles=[
            'public'=>[$nom.'.txt',false,$nonce],
            'tmp'=>['tmp/'.$nom.'.txt',true,$nonce],
            'tmp_php'=>['tmp/'.$nom.'.php',true,"<?php echo '".$nonce."';"],
            'configuration'=>['_config/'.$nom.'.txt',true,$nonce],
            'css'=>['_config/'.$nom.'.css',false,$nonce],
            'installation'=>['install/'.$nom.'.txt',true,$nonce],
            'depot'=>[$this->depot.'/'.$nom.'.txt',true,$nonce],
            'composer'=>['composer.json',true,null],
            'inventaire'=>[Inventaire::NOM,true,null],
        ];
        try {
            foreach ($cibles as $cle=>[$chemin,$prive,$contenu]) {
                // Sans témoin public servi, les autres sondes ne prouveraient rien : inutile d'attendre.
                if ($cle !== 'public' && ($r['public']['etat'] ?? '') !== 'verifie') { $r[$cle]=['etat'=>'indetermine','http'=>null,'cause'=>'public']; continue; }
                $p=$this->racine.'/'.$chemin; $pret=true;
                if ($contenu !== null) {
                    // Aucun parent inconnu/symlink n'est traversé pour déposer un témoin.
                    $parent=dirname($chemin);
                    while ($parent !== '.') { if (is_link($this->racine.'/'.$parent) || !is_dir($this->racine.'/'.$parent)) { $pret=false; break; } $parent=dirname($parent); }
                    $f=$pret ? @fopen($p,'x') : false;
                    if ($f === false) { $pret=false; }
                    else { $temoins[]=$p; $pret=fwrite($f,$contenu) === strlen($contenu); fclose($f); }
                } elseif (is_link($p) || !is_file($p)) { $pret=false; }
                $reste=self::BUDGET_MS-(int)((microtime(true)-$debut)*1000);
                if (!$pret || $reste <= 0) { $r[$cle]=['etat'=>'indetermine','http'=>null,'cause'=>$pret ? 'budget' : 'non_prepare']; continue; }
                $adresse=$url.implode('/',array_map('rawurlencode',explode('/',$chemin)));
                try { $reponse=$this->transport !== null ? ($this->transport)($adresse,min(self::SONDE_MS,$reste)) : $this->requete($adresse,min(self::SONDE_MS,$reste)); }
                catch (\Throwable) { $reponse=['http'=>null,'corps'=>'','erreur'=>'transport']; }
                $http=$reponse['http'];
                $etat='indetermine'; $cause=null;
                if ($reponse['erreur'] !== null) { $cause=in_array($reponse['erreur'],self::CAUSES,true) ? $reponse['erreur'] : 'transport'; }
                elseif ($prive && in_array($http,[403,404],true)) { $etat='verifie'; }
                elseif ($http === 200 && ($contenu === null || hash_equals($nonce,$reponse['corps']))) { $etat=$prive ? 'refuse' : 'verifie'; }
                else { $cause=$http === 200 ? 'contenu' : 'statut'; }
                $r[$cle]=['etat'=>$etat,'http'=>$http,'cause'=>$cause];
            }
        } finally { foreach ($temoins as $p) { @unlink($p); } }
        // Sans le témoin public exact, les refus peuvent provenir d'un autre site.
        if (($r['public']['etat'] ?? '') !== 'verifie') { foreach ($r as $cle=>&$sonde) { if ($cle !== 'public') { $sonde=['etat'=>'indetermine','http'=>null,'cause'=>'public']; } } unset($sonde); }
        return $r;
    }
    /** @return array{http:?int,corps:string,erreur:?string} */
    private function requete(string $url, int $delai): array
    {
        if (!function_exists('curl_init')) { return ['http'=>null,'corps'=>'','erreur'=>'curl']; }
        $c=curl_init($url); $corps=''; $excede=false;
        curl_setopt_array($c,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT_MS=>min(self::CONNEXION_MS,$delai),CURLOPT_USERAGENT=>$this->agent,CURLOPT_TIMEOUT_MS=>$delai,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_NOSIGNAL=>true,CURLOPT_PROXY=>'',CURLOPT_WRITEFUNCTION=>static function (\CurlHandle $c,string $texte) use (&$corps,&$excede):int { if (strlen($corps)+strlen($texte)>4096) { $excede=true; return 0; } $corps.=$texte; return strlen($texte); }]);
        $ok=curl_exec($c); $http=(int)curl_getinfo($c,CURLINFO_RESPONSE_CODE); $errno=curl_errno($c); curl_close($c);
        // Un corps trop long est interrompu volontairement : le statut reste exploitable, le corps ne peut être le témoin.
        if ($excede && $http > 0) { return ['http'=>$http,'corps'=>$corps,'erreur'=>null]; }
        if ($ok === false) { return ['http'=>$http > 0 ? $http : null,'corps'=>'','erreur'=>self::cause($errno)]; }
        return ['http'=>$http > 0 ? $http : null,'corps'=>$corps,'erreur'=>null];
    }
    private static function cause(int $errno): string
    {
        return match ($errno) {
            CURLE_OPERATION_TIMEDOUT => 'delai',
            CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT => 'connexion',
            CURLE_SSL_CONNECT_ERROR, CURLE_SSL_PEER_CERTIFICATE, CURLE_SSL_CACERT_BADFILE, CURLE_SSL_CERTPROBLEM => 'tls',
            default => 'transport',
        };
    }
    private function ajouterBloc(): string
    {
        $p=$this->racine.'/.htaccess';
        if (is_link($p) || (file_exists($p) && !is_file($p)) || is_file($p) && filesize($p)>1024*1024) { return 'refuse'; }
        $f=@fopen($p,'c+');
        if ($f === false) { return 'refuse'; }
        try {
            if (!flock($f,LOCK_EX|LOCK_NB)) { return 'refuse'; }
            $contenu=stream_get_contents($f);
            if ($contenu === false) { return 'refuse'; }
            $debut=substr_count($contenu,'# BEGIN ACTALIBRE PROTECTION'); $fin=substr_count($contenu,'# END ACTALIBRE PROTECTION');
            if ($debut !== 0 || $fin !== 0) { return $debut === 1 && $fin === 1 && str_contains($contenu,self::BLOC) ? 'present' : 'refuse'; }
            fseek($f,0,SEEK_END); $ajout="\n".self::BLOC;
            if (fwrite($f,$ajout) !== strlen($ajout) || !fflush($f)) {
                // Retirer seulement notre ajout incomplet, sous le même verrou.
                @ftruncate($f,strlen($contenu));
                @fflush($f);
                return 'refuse';
            }
            return 'ajoute';
        } finally { flock($f,LOCK_UN); fclose($f); }
    }
}
