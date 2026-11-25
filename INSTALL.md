# Installer ou mettre à jour ActaLibre

La première version prend en charge **Apache avec PHP 8.3 ou plus récent**, MySQL et le pilote mysqlnd. Les autres serveurs ne sont pas pris en charge en 1.0. Les extensions obligatoires figurent dans `composer.json` : filter, gd, iconv, mbstring, mysqli, mysqlnd, session et xml. OpenSSL sert au géocodage HTTPS ; cURL permet le contrôle HTTP des protections. Leur absence laisse ces fonctions indisponibles.

L’archive doit être transférée en entier, fichiers commençant par un point compris. Elle contient `composer.json` et `inventaire-actalibre.json`, sans `vendor/` ni `composer.lock`. Composer n’est pas nécessaire sur le serveur. Servez le site en HTTPS pour protéger les identifiants transmis par les formulaires.

## Première installation sur une base vierge

1. Créer la base MySQL et un utilisateur ayant les droits nécessaires sur cette base, avec l’outil de l’hébergeur. ActaLibre crée ses tables, pas la base MySQL elle-même.
2. Extraire l’archive, puis transférer son contenu par FTP dans le dossier choisi, à la racine du domaine ou dans un sous-dossier.
3. Ouvrir `install.php`. **Écran 1 — connexion** : indiquer les accès MySQL et le préfixe des tables. La connexion est vérifiée et le fichier de connexion est écrit ; aucune table n’est créée à cette étape.
4. **Écran 2 — administrateur** : indiquer l’adresse complète et canonique du site, sous-dossier compris, puis l’identifiant, le courriel et le mot de passe du premier administrateur. Pour prouver le contrôle du serveur, déposer d’abord par FTP un fichier vide portant le nom affiché (`admin-…txt`) dans le dossier `_config/`. Ce nom est propre au navigateur et valable une heure ; le fichier est supprimé après la création du compte.
5. La base doit être vierge pour ce préfixe. Aucune donnée ExpoActes n’est nécessaire : les tables, paramètres, métadonnées et prénoms sont livrés. Le site s’appelle initialement « Mon site principal ». Aucune ligne `EA_VERSION` n’est créée.
6. Après une interruption, rouvrir `install.php` : l’écran 2 reprend l’installation. Si un administrateur a déjà été créé, ses identifiants sont exigés ; aucun second compte n’est ajouté.

Le fichier de connexion est publié complet dans `_config/connect.inc.php`, sans remplacer un fichier existant. Si la publication automatique est impossible, déposer par FTP le contenu exact affiché, fermer cette page (elle contient les accès MySQL), puis rouvrir `install.php` pour l’écran 2. Pour corriger des accès erronés, supprimer ce fichier par FTP et recommencer l’écran 1.

Si l’écran 1 désigne une base existante (ExpoActes 3.2.4 ou ActaLibre), `install.php` renvoie vers `update.php`, qui demande les identifiants d’un administrateur existant ou, s’ils sont perdus, une preuve FTP pour en créer un nouveau. Le groupe facultatif « Base des comptes distincte » sert à une table des comptes située dans une autre base ou sous un autre préfixe. Le rattachement ne crée ni tables ni comptes.

## Migration depuis ExpoActes 3.2.4 uniquement

1. Mettre l’ancien site en maintenance et sauvegarder **toutes** ses bases, notamment la table des comptes si elle est distincte. Une sauvegarde des seuls actes ne suffit pas. Garder également une copie complète des fichiers, hors du dossier publié.
2. Transférer l’archive ActaLibre entière, fichiers cachés compris, **dans un répertoire vide** : un transfert par-dessus l’ancien site ne supprime rien et laisse en place d’anciens scripts PHP, qui restent appelables. Activer PHP ≥ 8.3 à ce moment : l’ancien code peut ne pas fonctionner avec cette version.

   Recopier ensuite de l’ancien site, aux mêmes emplacements, seulement ce qui a été personnalisé ou ajouté :

   - `_config/` : `actes.css`, `actes_print.css`, `bandeau.htm`, `commentaire.htm`, `acces.htm`. Relire les `.htm` : PHP les inclut, et exécuterait le code qu’ils contiendraient ;
   - `admin/_upload/*.m_csv` (ou le dépôt défini par `UPLOAD_DIR`) : les modèles de chargement ;
   - les photos et images ajoutées.

   Ne pas recopier les `.htaccess`, ni les CSV de `admin/_upload/`. Ouvrir `install.php` et saisir la connexion à la base existante (ainsi que celle des comptes si elle est distincte) : le fichier de connexion et les fichiers absents sont créés, sans remplacer ceux qui viennent d’être recopiés, puis la page renvoie vers `update.php`.
3. Ouvrir `update.php`, vérifier l’adresse canonique puis s’authentifier avec un compte de niveau 9. Si ces identifiants sont perdus, la rubrique « Identifiants perdus : créer un nouvel administrateur » crée un compte de niveau 9 après dépôt par FTP, dans `_config/`, d’un fichier vide portant le nom affiché ; la migration démarre aussitôt avec ce compte. Les pages ordinaires restent en maintenance jusqu’à la fin de la migration. `install/update.php` redirige vers la nouvelle adresse sans rejouer le POST.
4. Laisser les formulaires de continuation se soumettre automatiquement. Sans JavaScript, cliquer sur « Continuer ». Après une interruption ou une session expirée, rouvrir `update.php` et se reconnecter : l’avancement est repris. Les utilisateurs gardent leur mot de passe, converti au nouveau format.
5. Vérifier le bilan, le contrôle des protections et la liste des fichiers non reconnus, puis retirer ceux qui sont inutiles par FTP. Sortir de la maintenance d’exploitation si elle était activée dans les paramètres.

Les anciens dossiers `_logs/` et `_backup/` sont archivés entiers, par renommage, dans `tmp/archive/YYMMDDHHMMSS/logs` et `backups`. Il n’y a ni lecture de leur contenu, ni copie puis suppression. Une collision, un changement de volume ou un échec laisse la source en place et affiche un avertissement sans bloquer SQL. Une reprise peut utiliser un autre horodatage pour une source restante. Les anciens fichiers de protection sont conservés avec ces dossiers.

Pour restaurer une ancienne sauvegarde d’actes `.bea` après migration, recopier par FTP le fichier voulu depuis `tmp/archive/YYMMDDHHMMSS/backups/` vers `tmp/backups/`, sans écraser un fichier existant : la page de restauration ne liste que les sauvegardes de ce dernier dossier.

Sur de grosses tables MyISAM, un `ALTER TABLE` peut dépasser le délai PHP ou Apache. La reprise ne supprime pas cette limite : faire augmenter les délais de l’hébergement si la même étape échoue à chaque essai. Pour revenir en arrière, restaurer les fichiers **et toutes les bases** sauvegardées, ainsi que l’ancienne version de PHP si nécessaire ; les empreintes converties ne sont plus lisibles par ExpoActes.

Depuis ActaLibre, sauvegarder également les fichiers et bases, transférer le nouveau code par-dessus le site puis ouvrir `update.php`. L’archive ne contient ni `_config/` ni `admin/_upload/` : les personnalisations et les modèles de chargement ne sont pas écrasés. Ne pas personnaliser `_config_init/`, remplacé à chaque version. Vérifier ensuite la liste des fichiers non reconnus. À schéma identique, seul le produit est mis à jour après authentification ; le contrôle des protections est ensuite refait et son résultat est affiché avec un lien vers la page de contrôle. Si les identifiants sont perdus ou refusés, la rubrique « Identifiants perdus : créer un nouvel administrateur » d’`update.php` fonctionne comme pour la migration : déposer par FTP, dans `_config/`, un fichier vide portant le nom affiché, choisir un identifiant encore inutilisé, puis valider ; la mise à jour démarre aussitôt avec ce compte. Les comptes existants ne sont pas modifiés ; le nouvel administrateur peut ensuite changer leur mot de passe. Cette rubrique n’apparaît que si une mise à jour ou un nettoyage est à faire. Aucune migration vers un autre schéma ActaLibre n’est livrée en 1.0.

## Stockage et protections

- `_config/` garde les fichiers de connexion et les personnalisations CSS. **La configuration ne va pas dans tmp.** Les CSS restent accessibles ; les autres fichiers sont refusés par le modèle Apache.
- `tmp/logs/` reçoit les journaux de fichiers ; le journal des opérations en base reste dans sa table SQL.
- `tmp/backups/` reçoit les sauvegardes `.bea`, leurs fichiers de progression et les dumps `<nom-base>_AAAA-MM-JJ.sql`.
- `tmp/archive/` conserve les anciens journaux et sauvegardes ; `tmp/actalibre/protection.json` contient le dernier rapport HTTP, sans secret ni corps de réponse.
- `admin/_upload/`, ou le dépôt défini par `UPLOAD_DIR`, reste protégé et accueille les imports et leurs modèles.

PHP doit pouvoir écrire dans ces dossiers. Faire ajuster leurs droits par l’hébergeur si nécessaire ; ne pas rendre les fichiers de connexion publics. Les écritures de journaux et sauvegardes restent dans `tmp/` même si sa protection n’est pas vérifiée : l’avertissement doit être examiné.

Les modèles `.htaccess` sont installés, ceux restés identiques à des modèles anciens peuvent être actualisés, et les personnalisations sont conservées. Pendant une migration Apache, un bloc de refus pour `composer.*` et l’inventaire peut être ajouté à la racine, sans remplacer ses réglages. Un bloc incomplet ou un cas complexe est signalé pour intervention manuelle. `install/.htaccess` refuse les sources et `p_info.php`.

Le contrôle HTTP utilise l’adresse canonique validée, un témoin public et des témoins privés temporaires. Le site s’interroge lui-même par son adresse publique, en s’annonçant `ActaLibre/<version> (controle des protections)`, avec au plus 2 s par essai et 10 s en tout. La page détaille chaque essai : résultat, code HTTP ou cause. « x » indique qu’aucun code HTTP n’a été reçu.

Les résultats distinguent plusieurs situations :

- **Vérifiée** : la réponse confirme la protection attendue. Pour les témoins public et CSS, elle confirme au contraire qu’ils sont bien servis.
- **Refusée** : la protection est en défaut, un fichier privé est accessible publiquement. Un bandeau rouge le signale, même si d’autres essais n’ont pas abouti.
- **Non testé** : la sonde HTTP n’a pas été exécutée, faute de témoin public validé, de budget de temps ou de cURL.
- **Non vérifiable** : un obstacle identifié empêche de vérifier, par exemple un fichier absent, un témoin impossible à déposer, une connexion impossible ou un certificat refusé.
- **Indéterminé** : la réponse reçue ne permet pas de conclure (statut ou contenu inattendu), ou un ancien rapport ne précise pas la cause.

Le bilan indique **contrôle partiel** si certains essais sont concluants et d’autres non. Le nombre d’essais concluants inclut aussi les expositions détectées : un contrôle complet ne signifie pas nécessairement que les protections sont correctes. Si aucun essai n’est concluant, le bilan le précise ; si toutes les sondes ont été écartées avant leur exécution HTTP, il indique « Aucun essai HTTP réalisé ». Sans rapport valable pour la version courante, il indique « Aucun résultat de contrôle disponible » : cette absence ne permet pas de savoir si les essais n’ont jamais eu lieu ou si leur rapport n’a pas pu être enregistré. Dans les bilans d’installation et de mise à jour, le lien « Relancer le contrôle » apparaît sous le tableau.

Une réponse 500, un délai dépassé, une URL incorrecte ou l’absence de cURL ne prouvent pas une protection. Si le fichier public témoin n’est pas servi, les autres essais ne sont pas tentés : vérifier d’abord que le serveur peut joindre sa propre adresse (pare-feu, DNS interne, redirection vers HTTPS). L’installation et SQL peuvent aboutir avec un avertissement. Un serveur PHP intégré, qui ne lit pas les `.htaccess`, ne valide pas cette protection.

Un administrateur de niveau 9 trouve l’état du dernier contrôle et le nombre de fichiers non reconnus dans **Paramétrage du logiciel → Protection**. Le lien « Détail et nouveau contrôle » ouvre le tableau des essais, la liste des chemins et le formulaire qui relance le contrôle. Le bandeau administratif persiste tant qu’un contrôle n’est pas vérifié. La page de consultation n’effectue aucune sonde ; seule l’action POST protégée la relance. Si Apache n’accepte pas les directives ou ignore les `.htaccess`, faire corriger sa configuration, puis relancer le contrôle.

## Fichiers non reconnus et retrait par FTP

La liste compare les noms et types à l’inventaire de cette livraison et aux données produites par l’application. **Aucun fichier inconnu n’est déplacé, supprimé, ouvert, haché ou exécuté par cette analyse.** Un dossier entièrement inconnu apparaît sur une seule ligne, sans détailler son contenu ; les liens ne sont pas suivis. Un inventaire absent, invalide ou une copie incomplète donne un diagnostic d’analyse non réalisée.

La présence dans la liste ne signifie pas qu’un fichier est malveillant. Des fichiers légitimes de l’hébergeur ou des personnalisations, par exemple `.well-known/`, `.user.ini` ou des images ajoutées, peuvent y figurer. Vérifier chaque chemin avec son responsable et garder une copie hors de l’espace publié avant de le retirer par FTP. Conserver les éléments nécessaires à l’hébergement. Le déplacement automatique est reporté à une version ultérieure.

Les anciens scripts disparus et les fichiers de debug encore présents après transfert doivent également être examinés. La liste montre leurs chemins sans afficher leur contenu ni fournir de lien pour les exécuter. L’ancien assistant `install/configuration.php`, s’il subsiste, doit être retiré.

## Paramètres et restauration XML

L’édition est limitée au groupe affiché ; les groupes cachés et les marqueurs internes sont protégés. Une sauvegarde XML peut être restaurée vers un autre préfixe : tout le document est validé avant la première écriture. Une colonne inconnue, une clé manquante ou répétée, un mélange de préfixes ou une ambiguïté entre clé NULL et vide refuse l’import. Une clé vide réutilise une ligne NULL ou vide unique et est insérée comme chaîne vide si elle manque.

L’adresse `URL_PUBLIQUE`, les marqueurs et l’absence ou la valeur historique d’`EA_VERSION` restent propres au site destinataire. Les tables MyISAM ne permettent pas d’annuler toutes les écritures après une erreur SQL : contrôler la base puis rejouer l’import corrigé. Les aides publiques et les modèles CSV livrés restent accessibles depuis l’administration.
