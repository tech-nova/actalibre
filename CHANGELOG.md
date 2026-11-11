# Journal des modifications

Toutes les modifications notables d'ActaLibre sont notées dans ce fichier.

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
