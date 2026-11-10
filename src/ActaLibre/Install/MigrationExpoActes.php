<?php
declare(strict_types=1);
namespace ActaLibre\Install;
use ActaLibre\Compte\Migration;
use ActaLibre\Compte\MotDePasse;
use ActaLibre\Db\Requete;

/** Transformations 3.2.4 exclusivement ; aucun marqueur final écrit ici. */
final class MigrationExpoActes
{
    private SourcesLivrees $sources;
    private const TABLES_COMPTES=['session','essai','reinit','acte_vu','courriel'];
    /** @param null|\Closure(string):void $apresEffet */
    public function __construct(private string $racine,private ?\Closure $apresEffet=null)
    { $this->sources=new SourcesLivrees($racine); }
    /** @return list<string> */
    public function etapes(): array { return Avancement::etapes(); }

    public function controler(ConnexionsSite $c): void
    {
        $params=$this->table($c,'params');
        $versions=$this->lignes($c->actes,"SELECT valeur FROM `$params` WHERE param=?",[EtatSite::PARAMETRE_HISTORIQUE]);
        if (count($versions)!==1 || trim((string)$versions[0]['valeur'])!=='3.2.4') {
            throw new \RuntimeException('Cette migration exige ExpoActes 3.2.4.');
        }
        foreach (['mar3','dec3','div3','user3'] as $table) {
            $u=$table==='user3';$lien=$u?$c->comptes:$c->actes;
            $nom=$this->table($c,$table,$u);$colonnes=$this->colonnes($lien,$nom);
            $capacite=$u?'hashpass':'C_PRO';
            $this->capacite($colonnes[$capacite]??null);
            foreach ($u?['maj_solde','dtcreation','dtexpiration']:['LADATE','DTDEPOT','DTMODIF'] as $date) {
                if (($colonnes[$date]['Type']??null)!=='date') throw new \RuntimeException('Structure de dates non prise en charge.');
            }
            $statut=$this->lignes($lien,'SHOW TABLE STATUS WHERE Name=?',[$nom]);
            if (count($statut)!==1 || !in_array($statut[0]['Engine'],['MyISAM','InnoDB'],true)) throw new \RuntimeException('Moteur de table non pris en charge.');
        }
        foreach (['params','metadb','metalg','mgrplg'] as $table) $this->table($c,$table);
        foreach (self::TABLES_COMPTES as $table) {
            $nom=$this->table($c,$table,true,false);
            if ($nom!==null) $this->verifierTableComptes($c->comptes,$nom,$table);
        }
        $this->verifierHashes($c,false);
    }

    /** @return array{terminee:bool,position:?int,avis:list<string>} */
    public function executer(ConnexionsSite $c,string $etape,?int $position,string $url): array
    {
        if (!in_array($etape,$this->etapes(),true) || in_array($etape,['schema','produit','nettoyage'],true)
            || ($position!==null && ($position<0 || $etape!=='mots-de-passe'))) throw new \RuntimeException('Étape de migration invalide.');
        $terminee=true;$suivante=null;$avis=[];
        if ($etape==='preparation') $this->controler($c);
        elseif (str_starts_with($etape,'dates:')) {
            [, $perimetre,$table,$colonne]=explode(':',$etape);
            $u=$perimetre==='comptes';$lien=$u?$c->comptes:$c->actes;$nom=$this->table($c,$table,$u);
            $predicat="`$colonne` IS NOT NULL AND (YEAR(`$colonne`)=0 OR MONTH(`$colonne`)=0 OR DAYOFMONTH(`$colonne`)=0)";
            $this->requete($lien,"UPDATE `$nom` SET `$colonne`='1001-01-01' WHERE $predicat LIMIT 200");
            $terminee=$this->lignes($lien,"SELECT 1 FROM `$nom` WHERE $predicat LIMIT 1")===[];
        } elseif (str_starts_with($etape,'capacite:')) {
            $table=substr($etape,9);$u=$table==='user3';$lien=$u?$c->comptes:$c->actes;$nom=$this->table($c,$table,$u);
            $colonne=$u?'hashpass':'C_PRO';$definition=$this->colonnes($lien,$nom)[$colonne];
            $taille=$this->capacite($definition);$minimum=$u?255:35;
            $moteur=$this->lignes($lien,'SHOW TABLE STATUS WHERE Name=?',[$nom])[0]['Engine'];
            if ($taille<$minimum || ($u && $moteur!=='InnoDB')) {
                $clauses=[];
                if ($taille<$minimum) {
                    $collation=(string)$definition['Collation'];
                    if (!preg_match('/\A[a-zA-Z0-9_]+\z/',$collation)) throw new \RuntimeException('Collation non prise en charge.');
                    $default=$definition['Default']===null?'NULL':"'".$lien->real_escape_string((string)$definition['Default'])."'";
                    $clauseDefault=$definition['Null']==='NO' && $definition['Default']===null?'':' DEFAULT '.$default;
                    $clauses[]="MODIFY `$colonne` VARCHAR($minimum) COLLATE $collation ".($definition['Null']==='YES'?'NULL':'NOT NULL').$clauseDefault
                        ." COMMENT '".$lien->real_escape_string((string)$definition['Comment'])."'";
                }
                if ($u && $moteur!=='InnoDB') $clauses[]='ENGINE=InnoDB';
                $this->requete($lien,"ALTER TABLE `$nom` ".implode(', ',$clauses));
            }
            if ($this->capacite($this->colonnes($lien,$nom)[$colonne])<$minimum) throw new \RuntimeException('Capacité de colonne non vérifiée.');
        } elseif (str_starts_with($etape,'table:')) {
            $suffixe=substr($etape,6);$prefixe=($c->configuration->comptes??$c->configuration->actes)->prefixe;
            $nom=$this->table($c,$suffixe,true,false)??$prefixe.'_'.$suffixe;
            $sql=preg_replace('/\ACREATE TABLE IF NOT EXISTS EA_DB_[a-z0-9_]+/',"CREATE TABLE IF NOT EXISTS `$nom`",$this->sources->tables()[$suffixe]);
            if ($sql===null) throw new \RuntimeException('Définition de table illisible.');
            $this->requete($c->comptes,$sql);$this->verifierTableComptes($c->comptes,$nom,$suffixe);
        } elseif ($etape==='mots-de-passe') {
            $r=Migration::migrerLot($c->comptes,$this->table($c,'user3',true),$position??0);
            $terminee=$r['terminee'];$suivante=$terminee?null:$r['dernierId'];
            if ($terminee) $this->verifierHashes($c,true);
        } elseif ($etape==='parametres') $this->parametres($c,$url);
        elseif ($etape==='metadonnees') $this->metadonnees($c);
        elseif ($etape==='protections') $avis=$this->protections($c,$url);
        elseif ($etape==='verification') $this->verifier($c);
        $this->apresEffet?->__invoke($etape);
        return ['terminee'=>$terminee,'position'=>$suivante,'avis'=>$avis];
    }

    public function verifier(ConnexionsSite $c): void
    {
        $this->controler($c);
        foreach (['mar3','dec3','div3','user3'] as $table) {
            $u=$table==='user3';$lien=$u?$c->comptes:$c->actes;$nom=$this->table($c,$table,$u);
            if ($this->capacite($this->colonnes($lien,$nom)[$u?'hashpass':'C_PRO'])<($u?255:35)) throw new \RuntimeException('Capacité finale insuffisante.');
        }
        $nom=$this->table($c,'user3',true);
        if ($this->lignes($c->comptes,'SHOW TABLE STATUS WHERE Name=?',[$nom])[0]['Engine']!=='InnoDB') throw new \RuntimeException('Moteur des comptes non finalisé.');
        foreach (self::TABLES_COMPTES as $suffixe) $this->verifierTableComptes($c->comptes,$this->table($c,$suffixe,true),$suffixe);
        $this->verifierHashes($c,true);
        $params=$this->table($c,'params');
        foreach ($this->sources->parametres() as $p) {
            if (count($this->lignes($c->actes,"SELECT param FROM `$params` WHERE param=?",[$p['param']]))!==1) throw new \RuntimeException('Paramètre livré manquant ou ambigu.');
        }
        if ($this->lignes($c->actes,"SELECT 1 FROM `$params` WHERE param='GOOGLE_ANA_CODE'")!==[]) throw new \RuntimeException('Paramètre obsolète encore présent.');
        $meta=$this->table($c,'metadb');
        foreach ([2035,3030,4039] as $zid) {
            $l=$this->lignes($c->actes,"SELECT taille FROM `$meta` WHERE ZID=?",[$zid]);
            if (count($l)!==1 || (int)$l[0]['taille']<35) throw new \RuntimeException('Capacité des métadonnées non vérifiée.');
        }
    }

    /** @return list<string> */
    public function avis(ConnexionsSite $c): array
    {
        $avis=[];
        foreach (['_config','tmp','_backup','_logs','admin/_upload'] as $dossier) {
            $contenu=@file_get_contents($this->racine.'/'.$dossier.'/.htaccess');
            if ($contenu!==false && trim(str_replace("\r\n","\n",$contenu))!==trim(Protections::modele($dossier))) $avis[]="Protection personnalisée à vérifier : $dossier.";
        }
        $modeleRacine=@file_get_contents($this->racine.'/.htaccess_init');
        $protectionRacine=@file_get_contents($this->racine.'/.htaccess');
        if ($modeleRacine!==false && $protectionRacine!==false && trim(str_replace("\r\n","\n",$modeleRacine))!==trim(str_replace("\r\n","\n",$protectionRacine))) {
            $avis[]='Protection de la racine conservée ou complétée : vérifiez le contrôle HTTP de composer.*.';
        }
        $table=$this->table($c,'user3',true);
        if ((int)$this->lignes($c->comptes,"SELECT COUNT(*) AS total FROM `$table` WHERE hashpass='' OR hashpass IS NULL")[0]['total']>0) $avis[]='Des comptes sans empreinte restent inutilisables ; aucun mot de passe ne leur a été attribué.';
        if (is_file($this->racine.'/install/configuration.php')) $avis[]='Un ancien assistant est encore présent dans install/ ; retirez-le du serveur.';
        return $avis;
    }
    /** @return list<string> */
    private function protections(ConnexionsSite $c,string $url): array
    {
        $prive=EspacePrive::preparer($this->racine);
        $depot=DiagnosticInstallation::depot($c->actes,$c->configuration->actes->prefixe);
        $avis=[];
        try {
            $bilan=Protections::actualiser($this->racine,$depot);
            if ($bilan['echecs']!==[]) $avis[]='Certaines protections de dossiers restent à vérifier.';
        } catch (\Throwable) { $avis[]='Modèles de protection indisponibles.'; }
        if ($prive['erreurs']!==[]) $avis[]='Certains dossiers privés n’ont pas pu être préparés.';
        $archives=ArchivesHistoriques::deplacer($this->racine);
        foreach ($archives['archives'] as $p) $avis[]='Données historiques archivées : '.$p.'.';
        $avis=array_merge($avis,$archives['erreurs']);
        $rapport=DiagnosticInstallation::controler($this->racine,$url,true,$depot);
        if ($rapport->avertissement()!==null) $avis[]=$rapport->avertissement();
        return array_merge($avis,$rapport->avis,$this->avis($c));
    }

    private function parametres(ConnexionsSite $c,string $url): void
    {
        $u=parse_url($url);
        if (!is_array($u) || !in_array($u['scheme']??'', ['http','https'],true) || !isset($u['host']) || !InstallGuard::nomHoteValide($u['host'])
            || isset($u['user']) || isset($u['pass']) || isset($u['query']) || isset($u['fragment']) || filter_var($url,FILTER_VALIDATE_URL)===false) throw new \RuntimeException('Origine HTTP invalide.');
        $table=$this->table($c,'params');
        foreach ($this->sources->parametres() as $p) {
            if (Marqueurs::ressemble($p['param'])) continue;
            $l=$this->lignes($c->actes,"SELECT param,valeur FROM `$table` WHERE param=?",[$p['param']]);
            if (count($l)>1 || ($l!==[] && $l[0]['param']!==$p['param'])) throw new \RuntimeException('Paramètre ambigu.');
            if ($p['param']==='URL_PUBLIQUE') $p['valeur']=$l===[] || $l[0]['valeur']===''?$url:(string)$l[0]['valeur'];
            elseif ($l!==[]) $p['valeur']=(string)$l[0]['valeur'];
            if ($l===[]) $this->inserer($c->actes,$table,$p);
            else {
                $valeurs=[];$clauses=[];
                foreach ($p as $cle=>$valeur) { if ($cle==='param') continue;$clauses[]="`$cle`=?";$valeurs[]=$valeur; }
                $valeurs[]=$p['param'];$this->requete($c->actes,"UPDATE `$table` SET ".implode(',',$clauses).' WHERE param=?',$valeurs);
            }
        }
        $this->requete($c->actes,"DELETE FROM `$table` WHERE param='GOOGLE_ANA_CODE'");
    }
    private function metadonnees(ConnexionsSite $c): void
    {
        $cles=['metadb'=>['ZID'],'metalg'=>['ZID','lg'],'mgrplg'=>['grp','dtable','lg','sigle']];
        foreach ($this->sources->metadonnees() as [$suffixe,$colonnes,$tuples]) {
            $table=$this->table($c,$suffixe);
            foreach ($tuples as $tuple) {
                $resultat=$this->requete($c->actes,'SELECT '.substr($tuple,1,-1));
                if (!$resultat instanceof \mysqli_result || ($valeurs=$resultat->fetch_row())===null || count($valeurs)!==count($colonnes)) throw new \RuntimeException('Définition de métadonnée illisible.');
                $donnees=array_combine($colonnes,$valeurs);$conditions=[];$v=[];
                foreach ($cles[$suffixe] as $cle) { $conditions[]="`$cle` <=> ?";$v[]=$donnees[$cle]; }
                $existant=$this->lignes($c->actes,"SELECT 1 FROM `$table` WHERE ".implode(' AND ',$conditions),$v);
                if (count($existant)>1) throw new \RuntimeException('Métadonnée ambiguë.');
                if ($existant===[]) $this->inserer($c->actes,$table,$donnees);
            }
        }
        $table=$this->table($c,'metadb');
        $this->requete($c->actes,"UPDATE `$table` SET taille=35 WHERE ZID IN (2035,3030,4039) AND (taille IS NULL OR taille<35)");
    }
    /** @param array<string,string|int|float|null> $donnees */
    private function inserer(\mysqli $lien,string $table,array $donnees): void
    {
        $colonnes='`'.implode('`,`',array_keys($donnees)).'`';
        $this->requete($lien,"INSERT INTO `$table` ($colonnes) VALUES (".implode(',',array_fill(0,count($donnees),'?')).')',array_values($donnees));
    }
    private function verifierHashes(ConnexionsSite $c,bool $finaux): void
    {
        $table=$this->table($c,'user3',true);
        foreach ($this->lignes($c->comptes,"SELECT hashpass FROM `$table`") as $ligne) {
            $hash=(string)$ligne['hashpass'];
            if ($hash==='') continue;
            if (MotDePasse::estAncienFormat($hash)) { if (!$finaux) continue;throw new \RuntimeException('Des comptes restent à convertir.'); }
            if (password_get_info($hash)['algo']===null) throw new \RuntimeException('Empreinte de compte non reconnue.');
        }
    }
    private function verifierTableComptes(\mysqli $lien,string $table,string $suffixe): void
    {
        $ddl=$this->sources->tables()[$suffixe];$colonnes=$this->colonnes($lien,$table);$attendues=[];
        foreach (explode("\n",$ddl) as $ligne) {
            if (preg_match('/^\s+([a-z_]+)\s+(CHAR\([0-9]+\)|(?:BIG)?INT(?:\([0-9]+\))?|DATE)\s+NOT NULL,?\s*$/i',$ligne,$m)===1) {
                $attendues[$m[1]]=preg_replace('/\b((?:big|small|tiny|medium)?int)\([0-9]+\)/','$1',strtolower($m[2]));
            }
        }
        if ($attendues===[] || array_diff(array_keys($attendues),array_keys($colonnes))!==[]) throw new \RuntimeException('Table de comptes incomplète.');
        foreach ($attendues as $nom=>$type) {
            $actuel=preg_replace('/\b((?:big|small|tiny|medium)?int)\([0-9]+\)/','$1',strtolower((string)$colonnes[$nom]['Type']));
            if ($actuel!==$type || $colonnes[$nom]['Null']!=='NO') throw new \RuntimeException('Définition de table des comptes incompatible.');
        }
        $statut=$this->lignes($lien,'SHOW TABLE STATUS WHERE Name=?',[$table]);
        if (count($statut)!==1 || $statut[0]['Engine']!=='InnoDB' || !str_starts_with((string)$statut[0]['Collation'],'ascii_')) throw new \RuntimeException('Moteur ou encodage des tables de comptes incompatible.');
        $indexes=$this->lignes($lien,"SHOW INDEX FROM `$table`");
        $primaire=[];
        foreach ($indexes as $index) if ($index['Key_name']==='PRIMARY') $primaire[(int)$index['Seq_in_index']]=(string)$index['Column_name'];
        if (!preg_match('/PRIMARY KEY\s*\(([^)]+)\)/',$ddl,$m)) throw new \RuntimeException('Clé de comptes non définie.');
        ksort($primaire);
        if (array_values($primaire)!==array_map('trim',explode(',',$m[1]))) throw new \RuntimeException('Clé de table de comptes incompatible.');
    }
    /** @param array<string,mixed>|null $colonne */
    private function capacite(?array $colonne): int
    {
        if ($colonne===null || preg_match('/\Avarchar\(([0-9]+)\)\z/',(string)$colonne['Type'],$m)!==1 || $colonne['Extra']!=='') throw new \RuntimeException('Type de colonne non pris en charge.');
        return (int)$m[1];
    }
    /** @return array<string,array<string,mixed>> */
    private function colonnes(\mysqli $lien,string $table): array
    { $r=[];foreach ($this->lignes($lien,"SHOW FULL COLUMNS FROM `$table`") as $c) $r[(string)$c['Field']]=$c;return $r; }
    /** @return ($exiger is true ? string : string|null) */
    private function table(ConnexionsSite $c,string $suffixe,bool $comptes=false,bool $exiger=true): ?string
    {
        $lien=$comptes?$c->comptes:$c->actes;$a=$comptes?($c->configuration->comptes??$c->configuration->actes):$c->configuration->actes;
        $nom=null;
        foreach ($this->lignes($lien,'SHOW TABLES') as $ligne) {
            $valeur=(string)array_values($ligne)[0];
            if (strcasecmp($valeur,$a->prefixe.'_'.$suffixe)!==0) continue;
            if ($nom!==null) throw new \RuntimeException('Tables ambiguës à la casse près.');
            $nom=$valeur;
        }
        if ($exiger && $nom===null) throw new \RuntimeException('Table requise absente.');
        return $nom;
    }
    /**
     * @param list<string|int|float|null> $valeurs
     * @return list<array<string,mixed>>
     */
    private function lignes(\mysqli $lien,string $sql,array $valeurs=[]): array
    {
        $r=$this->requete($lien,$sql,$valeurs);
        if (!$r instanceof \mysqli_result) throw new \RuntimeException('Lecture de migration impossible.');
        return $r->fetch_all(MYSQLI_ASSOC);
    }
    /** @param list<string|int|float|null> $valeurs */
    private function requete(\mysqli $lien,string $sql,array $valeurs=[]): \mysqli_result|bool
    {
        try { $r=Requete::executer($lien,$sql,$valeurs); }
        catch (\mysqli_sql_exception) { throw new \RuntimeException('Opération de migration impossible.'); }
        if ($r===false) throw new \RuntimeException('Opération de migration impossible.');
        return $r;
    }
}
