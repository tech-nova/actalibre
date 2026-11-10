<?php
declare(strict_types=1);
namespace ActaLibre\Install;

/** Lecture des noms et lstat exclusivement ; ne suit pas les liens et ne touche aucun inconnu. */
final class SignalementFichiers
{
    public function __construct(private readonly string $racine, private readonly Inventaire $inventaire, private readonly Conservation $regles) {}
    /** @return iterable<array{chemin:string,type:string}> */
    public function lister(): iterable
    {
        $this->inventaire->verifierPresence();
        yield from $this->parcourir('');
    }
    /** @return iterable<array{chemin:string,type:string}> */
    private function parcourir(string $relatif): iterable
    {
        $noms = @scandir($this->racine.($relatif === '' ? '' : '/'.$relatif));
        if ($noms === false) { throw new \RuntimeException('Un répertoire connu est illisible : analyse incomplète.'); }
        foreach ($noms as $nom) {
            if ($nom === '.' || $nom === '..') { continue; }
            $p = $relatif === '' ? $nom : $relatif.'/'.$nom;
            $stat = @lstat($this->racine.'/'.$p);
            if ($stat === false) { throw new \RuntimeException('Un chemin a disparu pendant le diagnostic : recommencez.'); }
            $type = $stat['mode'] & 0170000;
            if ($this->regles->classer($p,$stat) === 'inconnu') {
                yield ['chemin'=>preg_match('//u',$p) === 1 ? $p : '[base64:'.base64_encode($p).']', 'type'=>match ($type) { 0040000=>'dossier', 0100000=>'fichier', 0120000=>'lien', default=>'autre' }];
            }
            if ($type === 0040000 && $this->regles->doitDescendre($p)) { yield from $this->parcourir($p); }
        }
    }
}
