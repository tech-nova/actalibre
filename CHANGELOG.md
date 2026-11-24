# Journal des modifications

## [Non publié]

## [1.1.2] - 2026-11-24

### Corrigé

- Paramétrage : les réglages du groupe « Géoloc » (contenu de la page d’accueil, carte) s’enregistrent de nouveau.
- Chargement CSV : renvoyer un fichier déjà chargé (retour arrière du navigateur) invite à le déposer à nouveau et n’inscrit plus « SECU : fichier refusé » au journal.

## [1.1.1] - 2026-11-24

### Corrigé

- `update.php` : après un refus de création d’administrateur, le formulaire reste ouvert, affiche l’erreur et conserve la saisie, sauf les mots de passe.
- `update.php` : si la migration échoue juste après la création du compte, la page indique que le compte existe et invite à se reconnecter avec lui.

## [1.1.0] - 2026-11-24

### Modifié

- `install.php` procède en deux écrans : le premier ne valide que la connexion MySQL et écrit le fichier de connexion ; le second, sur une base vierge, demande l’adresse du site et crée le premier administrateur. Une base existante (ExpoActes ou ActaLibre) est renvoyée vers `update.php`.
- La création d’un administrateur exige une preuve de contrôle du serveur : un fichier vide, au nom aléatoire affiché, déposé par FTP dans `_config/`.
- Le recalcul des statistiques et les imports ne contactent plus le service de géocodage : les localités nouvelles sont ajoutées sans coordonnées et signalées, avec un lien vers la liste des localités.
- Le géocodage respecte une requête par seconde pour tout le site, et la pause demandée par Nominatim (HTTP 429 ou 503, `Retry-After`).

### Ajouté

- `update.php` permet, pendant une migration depuis ExpoActes, de créer un nouvel administrateur sur la même preuve FTP lorsque les identifiants existants sont perdus.
- Liste des localités : sélection des lignes affichées, « Sélectionner les communes non géolocalisées » et mise à jour par Nominatim avec suivi, pause et reprise.
- Fiche d’une localité : recherche Nominatim ; une localité corrigée à la main n’est remplacée qu’après validation, et un enregistrement est refusé si la fiche a changé depuis son ouverture.
- Nouveau statut « Introuvable » et recensement à la demande des localités des actes, sans service extérieur.

## [1.0.0] - 2026-11-11

### Corrigé

- Contrôle des protections resté « indéterminé » chez les hébergeurs qui ralentissent les requêtes sans User-Agent : la sonde s'annonce comme ActaLibre et dispose de délais adaptés à un aller-retour par l'adresse publique.
- La page de contrôle affiche les états en français et la cause de chaque essai non vérifié.

### Modifié

- La page de contrôle des protections date le dernier contrôle en heure locale (« le 09/11/2026 à 20h12 ») au lieu du format technique UTC.
- `update.php` affiche une roue animée et « Mise à jour en cours, ne fermez pas cette page… » pendant chaque étape, au lieu d'une page réduite à son titre ; un bouton « Continuer » reste disponible sans JavaScript.
- L'état du contrôle des protections et le nombre de fichiers non reconnus apparaissent dans le groupe de paramètres « Protection », avec un lien vers le détail ; le lien de la page d'accueil de l'administration est retiré.

## [0.9.0] - 2026-11-09

### Ajouté

- Première version d'ActaLibre, à partir d'ExpoActes 3.2.4.
- Installation sur base vierge et migration contrôlée depuis ExpoActes 3.2.4.
- Livraison et schéma lus dans composer.json ; marqueurs internes protégés.
- Édition des paramètres limitée au groupe et restauration XML validée entre préfixes différents.
- Journaux et sauvegardes dans tmp/, configuration maintenue dans _config/.
- Archivage en bloc des anciens dossiers de données, contrôle informatif des protections Apache et signalement sans déplacement des fichiers inconnus.
- Archive autonome avec inventaire de ses fichiers, sans dépendances de développement.

Le format s'appuie sur [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/),
et le projet suit le [versionnage sémantique](https://semver.org/lang/fr/).
