<?php
declare(strict_types=1);
namespace ActaLibre\Install;
use ActaLibre\Compte\MotDePasse;
use ActaLibre\Compte\Limiteur;
use ActaLibre\Db\Requete;

/** Identité PHP technique ; limiteur SQL seulement sur un site déjà ActaLibre. */
final class SessionMiseAJour
{
    public static function ouvrir(string $cheminCookie,bool $https): void
    {
        session_name('actalibre_update_jeton');
        if (!@session_start(['use_strict_mode'=>1,'use_only_cookies'=>1,'cookie_httponly'=>true,
            'cookie_samesite'=>'Lax','cookie_secure'=>$https,'cookie_path'=>$cheminCookie])) {
            throw new \RuntimeException('Impossible de préparer le formulaire sécurisé.');
        }
        if (!isset($_SESSION['jeton']) || !is_string($_SESSION['jeton'])) $_SESSION['jeton']=bin2hex(random_bytes(32));
    }
    public static function jeton(): string
    { return is_string($_SESSION['jeton']??null) ? $_SESSION['jeton'] : ''; }
    public static function verifierJeton(string $jeton): bool
    { return self::jeton()!=='' && hash_equals(self::jeton(),$jeton); }

    /** @return array{id:int,login:string,perimetre:string,expiration:int} */
    public static function authentifier(\mysqli $lien,string $prefixe,string $login,#[\SensitiveParameter] string $motDePasse,string $perimetre,?Limiteur $limiteur=null,string $adresse=''): array
    {
        if (session_status()!==PHP_SESSION_ACTIVE) throw new \RuntimeException('Formulaire expiré.');
        unset($_SESSION['autorisation'],$_SESSION['procedure']);$reussi=false;
        try {
            if ($limiteur?->bloque($login,$adresse)) throw new \RuntimeException('Trop de tentatives. Réessayez plus tard.');
            $lignes=self::lignes($lien,$prefixe,'ID,login,hashpass,level,statut,dtexpiration','login=?',[$login]);
            $compte=$lignes[0]??null;
            $valide=$compte!==null && count($lignes)===1 && (string)$compte['login']===$login;
            if ($valide) $valide=MotDePasse::verifier($motDePasse,(string)$compte['hashpass']) && self::autorise($compte);
            else MotDePasse::verifierPourRien($motDePasse);
            if (!$valide) {
                $limiteur?->noterEchec($login,$adresse);
                throw new \RuntimeException('Identifiants administrateur invalides.');
            }
            $limiteur?->oublierCouple($login,$adresse);
            if (!@session_regenerate_id(true)) throw new \RuntimeException('Impossible de sécuriser la session.');
            $autorisation=['id'=>(int)$compte['ID'],'login'=>$login,'perimetre'=>$perimetre,'expiration'=>time()+1800];
            $_SESSION['autorisation']=$autorisation;$reussi=true;
            return $autorisation;
        } finally {
            // Ce coût reste imposé même avec un nouveau cookie et sans table moderne.
            if (!$reussi) usleep(1000000);
        }
    }

    /** @return array{id:int,login:string,perimetre:string,expiration:int}|null */
    public static function autorisation(string $perimetre): ?array
    {
        $a=$_SESSION['autorisation']??null;
        if (!is_array($a) || count($a)!==4 || !is_int($a['id']??null) || $a['id']<1 || !is_string($a['login']??null)
            || ($a['perimetre']??null)!==$perimetre || !is_int($a['expiration']??null) || $a['expiration']<=time()) return null;
        return ['id'=>$a['id'],'login'=>$a['login'],'perimetre'=>$perimetre,'expiration'=>$a['expiration']];
    }

    /** @param array{id:int,login:string,perimetre:string,expiration:int} $autorisation */
    public static function reverifier(\mysqli $lien,string $prefixe,array $autorisation,string $perimetre): void
    {
        if (self::autorisation($perimetre)!==$autorisation) throw new \RuntimeException('Authentification de mise à jour expirée.');
        $lignes=self::lignes($lien,$prefixe,'ID,login,level,statut,dtexpiration','ID=?',[$autorisation['id']]);
        if (count($lignes)!==1 || (int)$lignes[0]['ID']!==$autorisation['id'] || (string)$lignes[0]['login']!==$autorisation['login'] || !self::autorise($lignes[0])) {
            throw new \RuntimeException('Le compte ne permet plus de poursuivre la mise à jour.');
        }
    }

    public static function lierProcedure(EtatExploitation $etat): void
    {
        if (session_status()!==PHP_SESSION_ACTIVE) throw new \RuntimeException('Session technique fermée.');
        $_SESSION['procedure']=self::procedure($etat);
    }
    public static function verifierProcedure(EtatExploitation $etat): void
    {
        if (($_SESSION['procedure']??null)!==self::procedure($etat)) {
            throw new \RuntimeException('La procédure ou ses restes ont changé. Confirmez un nouveau formulaire.');
        }
    }
    /** @return array{action:string,produit:?string,schema:?string,restes:array<string,?string>} */
    private static function procedure(EtatExploitation $etat): array
    {
        return ['action'=>in_array($etat->action,['expoactes','reprendre'],true)?'migration':$etat->action,
            'produit'=>$etat->site->livraison?->version,'schema'=>$etat->site->livraison?->schema,
            'restes'=>array_intersect_key($etat->site->marqueurs,array_flip($etat->restes))];
    }
    public static function fermer(): void
    { if (session_status()===PHP_SESSION_ACTIVE) session_write_close(); }

    /** @param array<string,mixed> $compte */
    private static function autorise(array $compte): bool
    {
        $niveau=(int)$compte['level'];$statut=(string)$compte['statut'];
        $autorise=$niveau===9 || $statut==='N';
        $nonExpire=$statut!=='N' || (string)$compte['dtexpiration']>=date('Y-m-d') || $niveau===9;
        return $niveau===9 && $autorise && $nonExpire;
    }
    /**
     * @param list<string|int> $valeurs
     * @return list<array<string,mixed>>
     */
    private static function lignes(\mysqli $lien,string $prefixe,string $colonnes,string $condition,array $valeurs): array
    {
        if (preg_match('/\A[A-Za-z0-9_]{1,55}\z/',$prefixe)!==1) throw new \RuntimeException('Préfixe de comptes invalide.');
        try {
            $table=null;$inventaire=$lien->query('SHOW TABLES');
            if (!$inventaire instanceof \mysqli_result) throw new \RuntimeException('Lecture impossible.');
            foreach ($inventaire->fetch_all() as [$nom]) {
                if (strcasecmp((string)$nom,$prefixe.'_user3')!==0) continue;
                if ($table!==null) throw new \RuntimeException('Tables de comptes ambiguës.');
                $table=(string)$nom;
            }
            if ($table===null) throw new \RuntimeException('Table de comptes absente.');
            $resultat=Requete::executer($lien,'SELECT '.$colonnes.' FROM `'.str_replace('`','``',$table).'` WHERE '.$condition,$valeurs);
            if (!$resultat instanceof \mysqli_result) throw new \RuntimeException('Lecture impossible.');
            return $resultat->fetch_all(MYSQLI_ASSOC);
        } catch (\mysqli_sql_exception) {
            throw new \RuntimeException('Impossible de lire le compte administrateur.');
        }
    }
}
