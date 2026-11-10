<?php
declare(strict_types=1);
namespace ActaLibre\Tests\Infrastructure;

/** Apache privé au test, en loopback, sans modifier aucun service de la machine. */
final class ServeurApacheJetable
{
    /** @var resource|null */
    private $processus = null;
    private function __construct(private readonly string $infra, private readonly string $adresse) {}
    /** @param list<string> $reglagesPhp */
    public static function demarrer(string $racine, bool $sousDossier, array $reglagesPhp = [], bool $proteger = true): self
    {
        if (!is_file('/usr/lib/apache2/modules/libphp8.3.so')) { throw new \RuntimeException('Module Apache PHP 8.3 indispensable au contrôle.'); }
        $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$message);
        if ($socket === false) { throw new \RuntimeException('Port de test indisponible.'); }
        $nom=stream_socket_get_name($socket,false); fclose($socket);
        $port=(int)substr($nom,strrpos($nom,':')+1);
        $infra=sys_get_temp_dir().'/al5-apache-'.bin2hex(random_bytes(6));
        mkdir($infra,0755); mkdir($infra.'/www'); mkdir($infra.'/sessions',0700);
        $s=new self($infra,'http://127.0.0.1:'.$port.($sousDossier ? '/site/' : '/'));
        try {
            $uid=function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
            $utilisateur=posix_getpwuid($uid === 0 ? 33 : $uid);
            $groupe=posix_getgrgid($uid === 0 ? 33 : posix_getegid());
            if ($utilisateur === false || $groupe === false) { throw new \RuntimeException('Identité Apache de test indisponible.'); }
            if ($uid === 0) { chown($infra.'/sessions',$utilisateur['uid']); }
            $php='';
            foreach ($reglagesPhp as $reglage) {
                $parties=explode('=',$reglage,2);
                if (count($parties)!==2 || preg_match('/\A[a-zA-Z][a-zA-Z0-9_.]*\z/D',$parties[0])!==1 || strpbrk($parties[1],"\r\n\0")!==false) { throw new \RuntimeException('Réglage PHP de test invalide.'); }
                $php.='php_admin_value '.$parties[0].' "'.addcslashes($parties[1],"\\\"").'"'."\n";
            }
            $racine=realpath($racine);
            if ($racine === false || strpbrk($racine,"\r\n\"")!==false) { throw new \RuntimeException('Racine Apache invalide.'); }
            $modele=file_get_contents(dirname(__DIR__).'/fixtures/apache/httpd.conf');
            $conf=strtr($modele,['@INFRA@'=>$infra,'@PORT@'=>(string)$port,'@USER@'=>$utilisateur['name'],'@GROUP@'=>$groupe['name'],'@ROOT@'=>$racine,'@DOCUMENT@'=>$sousDossier ? $infra.'/www' : $racine,'@ALIAS@'=>$sousDossier ? 'Alias /site/ "'.$racine.'/"' : '', '@OVERRIDE@'=>$proteger ? 'All' : 'None','@PHP@'=>$php]);
            file_put_contents($infra.'/httpd.conf',$conf);
            $c=proc_open(['/usr/sbin/apache2','-t','-f',$infra.'/httpd.conf'],[1=>['file',$infra.'/verification.log','a'],2=>['file',$infra.'/verification.log','a']],$pipes);
            if ($c === false || proc_close($c)!==0) { throw new \RuntimeException('Configuration Apache invalide : '.file_get_contents($infra.'/verification.log')); }
            $s->processus=proc_open(['/usr/bin/setsid','/usr/sbin/apache2','-f',$infra.'/httpd.conf','-DFOREGROUND'],[0=>['file','/dev/null','r'],1=>['file',$infra.'/demarrage.log','a'],2=>['file',$infra.'/demarrage.log','a']],$pipes);
            if ($s->processus === false) { throw new \RuntimeException('Apache ne démarre pas.'); }
            $pret=false;
            for ($i=0;$i<100;$i++) {
                $connexion=@fsockopen('127.0.0.1',$port,$errno,$message,0.05);
                if ($connexion!==false) { fclose($connexion); $pret=true; break; }
                if (!proc_get_status($s->processus)['running']) { break; }
                usleep(50000);
            }
            if (!$pret) { throw new \RuntimeException('Apache indisponible : '.file_get_contents($infra.'/demarrage.log')); }
            $temoin='verification-php-'.bin2hex(random_bytes(6)).'.php';
            file_put_contents($racine.'/'.$temoin,'<?php echo json_encode([PHP_VERSION_ID, extension_loaded("mysqli"), extension_loaded("mysqlnd"), extension_loaded("xml"), extension_loaded("mbstring")]);');
            try {
                $curl=curl_init($s->url().$temoin); curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5,CURLOPT_PROXY=>'']);
                $reponse=curl_exec($curl); $statut=curl_getinfo($curl,CURLINFO_RESPONSE_CODE); curl_close($curl);
                $infos=is_string($reponse) ? json_decode($reponse,true) : null;
                if ($statut!==200 || !is_array($infos) || $infos[0]<80300 || array_slice($infos,1)!==[true,true,true,true]) { throw new \RuntimeException('Apache ne fournit pas PHP >= 8.3 et les extensions requises : '.json_encode($infos)); }
            } finally { unlink($racine.'/'.$temoin); }
            return $s;
        } catch (\Throwable $e) { $s->arreter(); throw $e; }
    }
    public function journalPhp(): string
    {
        $p=$this->infra.'/php.log';
        return is_file($p) ? (string)file_get_contents($p) : '';
    }
    public function url(): string { return $this->adresse; }
    public function arreter(): void
    {
        if (is_resource($this->processus)) {
            proc_terminate($this->processus);
            for ($i=0;$i<50 && proc_get_status($this->processus)['running'];$i++) { usleep(20000); }
            if (proc_get_status($this->processus)['running']) { proc_terminate($this->processus,9); }
            proc_close($this->processus); $this->processus=null;
        }
        if (is_dir($this->infra)) {
            $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->infra,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) { $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
            rmdir($this->infra);
        }
    }
    public function __destruct() { $this->arreter(); }
}
