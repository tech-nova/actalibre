<?php
declare(strict_types=1);
namespace ActaLibre\Install;
use ActaLibre\Livraison;

/** Format fermé et sans secret du checkpoint de migration. */
final class Avancement
{
    private function __construct(
        public readonly string $nature,
        public readonly string $source,
        public readonly string $produitCible,
        public readonly string $schemaCible,
        public readonly string $etape,
        public readonly ?int $position,
    ) {}

    public static function commencer(Livraison $livraison): self
    { return new self('expoactes','3.2.4',$livraison->version,$livraison->schema,'preparation',null); }

    /** @return list<string> */
    public static function etapes(): array
    {
        $etapes=['preparation'];
        foreach (['mar3','dec3','div3'] as $table) {
            foreach (['LADATE','DTDEPOT','DTMODIF'] as $colonne) $etapes[]="dates:actes:$table:$colonne";
        }
        foreach (['maj_solde','dtcreation','dtexpiration'] as $colonne) $etapes[]="dates:comptes:user3:$colonne";
        return [...$etapes,'capacite:mar3','capacite:dec3','capacite:div3','capacite:user3',
            'table:session','table:essai','table:reinit','table:acte_vu','table:courriel',
            'mots-de-passe','parametres','metadonnees','protections','verification','schema','produit','nettoyage'];
    }

    public static function decoder(string $json): self
    {
        try { $d=json_decode($json,true,32,JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new \RuntimeException('Avancement de migration illisible.'); }
        if (!is_array($d) || count($d)!==6 || array_diff(['format','nature','source','cible','etape','position'],array_keys($d))!==[]
            || $d['format']!==1 || $d['nature']!=='expoactes' || $d['source']!=='3.2.4'
            || !is_array($d['cible']) || count($d['cible'])!==2 || !self::version($d['cible']['produit']??null) || !self::version($d['cible']['schema']??null)
            || !is_string($d['etape']) || !in_array($d['etape'],self::etapes(),true)
            || ($d['position']!==null && (!is_int($d['position']) || $d['position']<0 || $d['etape']!=='mots-de-passe'))) {
            throw new \RuntimeException('Avancement de migration invalide ou inconnu.');
        }
        return new self($d['nature'],$d['source'],$d['cible']['produit'],$d['cible']['schema'],$d['etape'],$d['position']);
    }

    private static function version(mixed $v): bool
    { return is_string($v) && preg_match('/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\z/',$v)===1; }
    public function suivant(string $etape, ?int $position): self
    { return self::decoder((new self($this->nature,$this->source,$this->produitCible,$this->schemaCible,$etape,$position))->encoder()); }
    public function encoder(): string
    {
        return json_encode(['format'=>1,'nature'=>$this->nature,'source'=>$this->source,
            'cible'=>['produit'=>$this->produitCible,'schema'=>$this->schemaCible],
            'etape'=>$this->etape,'position'=>$this->position],JSON_THROW_ON_ERROR);
    }
    public function terminePour(string $produit,string $schema): bool
    { return $this->produitCible===$produit && $this->schemaCible===$schema; }
}
