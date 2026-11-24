<?php
declare(strict_types=1);
namespace ActaLibre\Install;
use ActaLibre\Db\Requete;

/** Un lot autorisé sous verrous ; le checkpoint SQL décide toujours de la position. */
final class MiseAJour
{
    /** @param null|\Closure(string):void $apresEffet */
    public function __construct(private string $racine,private ?\Closure $apresEffet=null) {}

    /**
     * @param array{id:int,login:string,perimetre:string,expiration:int} $autorisation
     * @return array{terminee:bool,diagnostic:string,avis:list<string>}
     */
    public function executer(ConnexionsSite $c,array $autorisation,string $action,string $url): array
    {
        $e=EtatExploitation::lire($c,$this->racine.'/composer.json');
        $this->admettre($e,$action);$this->autoriser($c,$autorisation,$e);
        $verrous=[];
        try {
            $a=$c->configuration->actes;
            $verrous[]=Verrou::prendre($c->actes,$a->base,$a->prefixe,'migration');
            if ($c->configuration->comptes!==null && !$this->memePerimetre($a,$c->configuration->comptes)) {
                $u=$c->configuration->comptes;
                $verrous[]=Verrou::prendre($c->comptes,$u->base,$u->prefixe,'migration');
            }
            $e=EtatExploitation::lire($c,$this->racine.'/composer.json');
            $this->admettre($e,$action);$this->autoriser($c,$autorisation,$e);
            $livraison=$e->site->livraison;
            if ($livraison===null) throw new \RuntimeException('Livraison illisible.');
            Installateur::prerequis($livraison);
            if ($action==='produit') {
                $this->marquer($c,'ACTALIBRE_VERSION',$livraison->version);
                $this->apresEffet?->__invoke('produit');
                return $this->resultatFinal($c,ModelesCsv::completerSite($this->racine,$c));
            }
            if ($action==='nettoyer') {
                foreach ($e->restes as $nom) {
                    $this->effacer($c,$nom,$e->site->marqueurs[$nom]);
                    $this->apresEffet?->__invoke('nettoyage:'.$nom);
                }
                $nouveau=EtatExploitation::lire($c,$this->racine.'/composer.json');
                return ['terminee'=>true,'diagnostic'=>$nouveau->diagnostic,'avis'=>[]];
            }
            $m=new MigrationExpoActes($this->racine,$this->apresEffet);
            $a=$e->avancement;
            if ($a===null) {
                $m->controler($c);
                $a=Avancement::commencer($livraison);
                $this->marquer($c,'ACTALIBRE_MIGRATION',$a->encoder());
            }
            if (!$a->terminePour($livraison->version,$livraison->schema)) throw new \RuntimeException('La cible de migration a changé.');
            if ($a->etape==='schema') {
                $m->verifier($c);
                $this->marquer($c,'ACTALIBRE_SCHEMA',$livraison->schema);
                $this->apresEffet?->__invoke('schema');
                $this->marquer($c,'ACTALIBRE_MIGRATION',$a->suivant('produit',null)->encoder());
                return ['terminee'=>false,'diagnostic'=>'Schéma vérifié ; finalisation du produit.','avis'=>[]];
            }
            if ($a->etape==='produit') {
                $m->verifier($c);
                if (($e->site->marqueurs['ACTALIBRE_SCHEMA']??null)!==$livraison->schema) throw new \RuntimeException('Schéma final non vérifié.');
                $this->marquer($c,'ACTALIBRE_VERSION',$livraison->version);
                $this->apresEffet?->__invoke('produit');
                $this->effacer($c,'ACTALIBRE_MIGRATION',$a->encoder());
                $this->apresEffet?->__invoke('nettoyage');
                return $this->resultatFinal($c,$m->avis($c));
            }
            if ($a->etape==='nettoyage') throw new \RuntimeException('Les marqueurs finaux doivent être présents avant le nettoyage.');
            $lot=$m->executer($c,$a->etape,$a->position,$url);
            if ($lot['terminee']) {
                $etapes=$m->etapes();$rang=array_search($a->etape,$etapes,true);
                if ($rang===false || !isset($etapes[$rang+1])) throw new \RuntimeException('Suite de migration inconnue.');
                $suivant=$a->suivant($etapes[$rang+1],null);
            } else $suivant=$a->suivant($a->etape,$lot['position']);
            $this->marquer($c,'ACTALIBRE_MIGRATION',$suivant->encoder());
            return ['terminee'=>false,'diagnostic'=>'Lot vérifié. Poursuivre la mise à jour.','avis'=>$lot['avis']];
        } finally {
            foreach (array_reverse($verrous) as $verrou) $verrou->liberer();
        }
    }
    /** Deux accès peuvent employer des identifiants différents pour le même verrou. */
    private function memePerimetre(AccesMysql $a,AccesMysql $b): bool
    {
        return mb_strtolower($a->serveur,'UTF-8')===mb_strtolower($b->serveur,'UTF-8')
            && mb_strtolower($a->base,'UTF-8')===mb_strtolower($b->base,'UTF-8')
            && mb_strtolower($a->prefixe,'UTF-8')===mb_strtolower($b->prefixe,'UTF-8');
    }
    private function admettre(EtatExploitation $e,string $action): void
    {
        $attendu=match($action) {
            'demarrer'=>['expoactes','reprendre'], 'continuer'=>['reprendre'], 'produit'=>['produit'], 'nettoyer'=>['nettoyer'],default=>[],
        };
        if (!in_array($e->action,$attendu,true)) throw new \RuntimeException($e->diagnostic);
    }
    /** @param array{id:int,login:string,perimetre:string,expiration:int} $autorisation */
    private function autoriser(ConnexionsSite $c,array $autorisation,EtatExploitation $e): void
    {
        $prefixe=($c->configuration->comptes??$c->configuration->actes)->prefixe;
        SessionMiseAJour::reverifier($c->comptes,$prefixe,$autorisation,$c->configuration->perimetre());
        SessionMiseAJour::verifierProcedure($e);
    }
    /**
     * @param list<string> $avis
     * @return array{terminee:bool,diagnostic:string,avis:list<string>}
     */
    private function resultatFinal(ConnexionsSite $c,array $avis): array
    {
        $e=EtatExploitation::lire($c,$this->racine.'/composer.json');
        if (!$e->accessible || $e->restes!==[]) throw new \RuntimeException('Finalisation à vérifier ; un état technique subsiste.');
        return ['terminee'=>true,'diagnostic'=>$e->diagnostic,'avis'=>$avis];
    }
    private function marquer(ConnexionsSite $c,string $nom,string $valeur): void
    {
        if (!Marqueurs::est($nom)) throw new \RuntimeException('Nom de marqueur inconnu.');
        $table=$this->tableParametres($c);$l=$this->lignes($c,"SELECT param,valeur FROM `$table` WHERE param=?",[$nom]);
        if (count($l)>1 || ($l!==[] && $l[0]['param']!==$nom)) throw new \RuntimeException('Marqueur ambigu.');
        if ($l===[]) $this->requete($c,"INSERT INTO `$table` (param,groupe,ordre,type,valeur,listval,libelle,aide) VALUES (?,'Hidden',100,'C',?,'','','')",[$nom,$valeur]);
        else $this->requete($c,"UPDATE `$table` SET valeur=? WHERE param=?",[$valeur,$nom]);
        $l=$this->lignes($c,"SELECT param,valeur FROM `$table` WHERE param=?",[$nom]);
        if (count($l)!==1 || $l[0]['param']!==$nom || $l[0]['valeur']!==$valeur) throw new \RuntimeException('Écriture du marqueur non vérifiée.');
    }
    private function effacer(ConnexionsSite $c,string $nom,?string $valeur): void
    {
        if (!in_array($nom,['ACTALIBRE_MIGRATION','ACTALIBRE_INSTALLATION'],true)) throw new \RuntimeException('Nettoyage interdit.');
        $table=$this->tableParametres($c);
        $this->requete($c,"DELETE FROM `$table` WHERE BINARY param=BINARY ? AND BINARY valeur <=> BINARY ?",[$nom,$valeur]);
        if ($c->actes->affected_rows!==1 || $this->lignes($c,"SELECT 1 FROM `$table` WHERE param=?",[$nom])!==[]) throw new \RuntimeException('Le marqueur à nettoyer a changé.');
    }
    private function tableParametres(ConnexionsSite $c): string
    {
        $table=null;
        foreach ($this->lignes($c,'SHOW TABLES') as $l) {
            $nom=(string)array_values($l)[0];
            if (strcasecmp($nom,$c->configuration->actes->prefixe.'_params')!==0) continue;
            if ($table!==null) throw new \RuntimeException('Tables de paramètres ambiguës.');
            $table=$nom;
        }
        if ($table===null) throw new \RuntimeException('Table des paramètres absente.');
        return $table;
    }
    /**
     * @param list<string|int|float|null> $valeurs
     * @return list<array<string,mixed>>
     */
    private function lignes(ConnexionsSite $c,string $sql,array $valeurs=[]): array
    {
        $r=$this->requete($c,$sql,$valeurs);
        if (!$r instanceof \mysqli_result) throw new \RuntimeException('Lecture de mise à jour impossible.');
        return $r->fetch_all(MYSQLI_ASSOC);
    }
    /** @param list<string|int|float|null> $valeurs */
    private function requete(ConnexionsSite $c,string $sql,array $valeurs=[]): \mysqli_result|bool
    {
        try { $r=Requete::executer($c->actes,$sql,$valeurs); }
        catch (\mysqli_sql_exception) { throw new \RuntimeException('Opération de mise à jour impossible.'); }
        if ($r===false) throw new \RuntimeException('Opération de mise à jour impossible.');
        return $r;
    }
}
