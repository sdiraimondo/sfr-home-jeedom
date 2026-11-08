# Plugin SFR Home

Ce plugin permet de connecter et contrôler les équipements SFR Home dans Jeedom.

## Fonctionnalités

- Découverte automatique des équipements SFR Home
- Mise à jour automatique des états (fréquence configurable)
- Contrôle des prises connectées
- Gestion de la centrale d'alarme
- Capteurs (ouverture, mouvement, fumée, température/humidité)
- Volets roulants
- Lumières
- Caméras SFR Home (snapshot, état, enregistrement)

## Configuration

1. Installer et activer le plugin.
2. Aller dans **Plugins → SFR Home → Configuration**.
3. Renseigner l'identifiant et le mot de passe SFR.
4. Cliquer sur **Tester la connexion** pour vérifier immédiatement les identifiants.
5. Cliquer sur **Sauvegarder**, puis sur **Synchroniser les périphériques**.

Les équipements sont ensuite créés automatiquement dans **Plugins → SFR Home**.
La synchronisation automatique tourne chaque minute et ne récupère les données
SFR Home que si la fréquence de rafraîchissement configurée est écoulée.

## Catégories

Les équipements sont automatiquement classés dans les catégories Jeedom selon leur
type SFR :

- **Lumière** : ampoules connectées (LED, variateur, couleur)
- **Énergie** : prises connectées
- **Sécurité** : centrale d'alarme, sirènes, télécommandes, claviers,
  détecteurs d'ouverture, de mouvement, de fumée et de fuite d'eau
- **Multimédia** : caméras SFR Home
- **Chauffage** : capteurs de température/humidité et thermostats
- **Ouvrant** : volets roulants
- **Autre** : types non reconnus

Les équipements existants sont recatégorisés automatiquement lors de la première
synchronisation suivant la mise à jour. Les personnalisations effectuées ensuite
sont conservées.

## Caméras

Les caméras sont découvertes à la fois via `/mysensors` (types `CAMERA_WIFI` et
`PIR_CAMERA`) et via l'endpoint dédié SFR `/homescope/mycams`. Les deux sources
sont fusionnées par adresse MAC afin d'éviter les doublons.

Chaque caméra expose notamment :

- **URL snapshot** : image servie par le proxy local du plugin
  (`core/ajax/sfrhome_snapshot.php`). Les cookies de session SFR restent sur le
  serveur Jeedom et ne sont jamais transmis au navigateur.
- **URL flux** : URL FLV SFR. La lecture du flux nécessite une session SFR valide.
- **En ligne**, **Confidentialité**, **Enregistrement 24h/24**,
  **Profil de détection**, **Sensibilité**, **Image inversée**, **LED éteinte**.
- **Démarrer l'enregistrement** et **Arrêter l'enregistrement**.

Le proxy de snapshot est protégé par le jeton `snapshotToken`, généré
automatiquement à l'installation du plugin. L'URL du snapshot est relative
(`/plugins/sfrhome/...`) ; elle devient absolue si l'URL Jeedom est configurée
dans le cœur (clé `jeedom::url`).

## Authentification

Le plugin utilise le formulaire de connexion de SFR Home (avec jeton CSRF),
avec gestion et renouvellement automatique des cookies de session. En cas de session
expirée, une réauthentification est effectuée automatiquement.

## Dépannage

- Vérifier les logs dans **Analyse → Logs → sfrhome**.
- Si l'authentification échoue, supprimer le fichier de cookies
  (par défaut `/tmp/sfrhome_session_cookies.json`) puis relancer un test.
- PHP avec les extensions `curl` et `SimpleXML` est requis.
