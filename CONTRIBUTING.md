# Contribuer à ActaLibre

## Environnement de développement

Installer les dépendances de développement avec `composer install`. Le dossier `vendor/` n'est jamais déployé : l'archive de diffusion est construite depuis Git par `tools/construire_distribution.py`.

Les tests qui utilisent MySQL lisent les variables `GM_DB_HOST`, `GM_DB_USER`, `GM_DB_PASS` et `GM_DB_NAME`. Utiliser une base dédiée aux tests : son contenu est recréé à chaque passage. Sans ces variables, les tests concernés sont ignorés.

## Tests

    vendor/bin/phpunit --testsuite ActaLibre        # tests unitaires et parcours
    vendor/bin/phpunit --testsuite golden-master    # non-régression des pages
    vendor/bin/phpunit --testsuite distribution     # archive testée sous Apache (module PHP 8.4)
    composer analyse                                # PHPStan

Le Golden Master compare chaque page à une référence enregistrée. Une différence est un échec : ne jamais régénérer les références (`GM_MAJ=1`) sans avoir examiné et justifié chaque changement.

## Requêtes préparées

`ActaLibre\Db\Requete::executer($lien, $sql, $parametres)` rend un résultat lisible par les fonctions `EA_sql_*`. Passer `$u_db` pour les tables `EA_UDB`, et `EA_sql_which_link()` pour les tables `EA_DB`. Lire `EA_sql_error($lien)` et `EA_sql_affected_rows($lien)` avant tout autre appel.

À chaque migration d'une requête :

- passer les valeurs brutes, sans `sql_quote()` ni guillemets autour du `?` ; garder les `(int)`, et un `trim()` si l'ancienne requête en dépendait ;
- relire les comparaisons strictes sur les colonnes numériques, qui reviennent avec leur type natif ;
- contrôler le texte des colonnes `FLOAT` et `DOUBLE`, ou lire `CAST(col AS CHAR)` ;
- écrire `IS NULL` pour une valeur nulle, et ne jamais mettre de marqueur à la place d'un nom de colonne ;
- laisser `LOCK TABLES` sur `EA_sql_query()`, et retirer l'appel à `optimize()` ;
- vérifier que le Golden Master ne change pas.
