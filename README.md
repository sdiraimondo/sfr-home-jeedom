# Plugin SFR Home pour Jeedom

Ce plugin permet de connecter et contrôler vos équipements SFR Home dans Jeedom.

## Fonctionnalités

- Découverte automatique des équipements SFR Home
- Rafraîchissement automatique des états (fréquence configurable)
- Contrôle des prises connectées
- Gestion de l'alarme SFR Home
- Capteurs (ouverture, mouvement, fumée, température/humidité)
- Volets roulants
- Lumières
- Caméras SFR Home (snapshot, état, enregistrement)

## Installation

Le dossier doit s'appeler **`sfrhome`** dans le répertoire `plugins` de Jeedom :

```bash
cd /var/www/html/plugins
sudo git clone https://github.com/sdiraimondo/sfr-home-jeedom.git sfrhome
sudo chown -R www-data:www-data sfrhome
```

Puis dans Jeedom : **Plugins → Gestion des plugins → SFR Home → Activer**.

## Configuration

1. Aller dans **Plugins → SFR Home → Configuration**.
2. Renseigner l'identifiant et le mot de passe SFR.
3. Cliquer sur **Tester la connexion** : le résultat s'affiche immédiatement
   (les identifiants saisis sont utilisés même s'ils ne sont pas encore sauvegardés).
4. Cliquer sur **Sauvegarder**.
5. Cliquer sur **Synchroniser les périphériques** (ou attendre le cron).

Les équipements sont créés automatiquement dans **Plugins → SFR Home**.

## Rafraîchissement

La synchronisation automatique s'exécute chaque minute, mais n'interroge SFR Home
que si la fréquence configurée (`refreshInterval`, 60 secondes par défaut) est écoulée.
Chaque équipement est ensuite mis à jour avec les états frais de `/mysensors`.

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
expirée, une réauthentification est effectuée automatiquement. Les erreurs renvoyées
par SFR (identifiant inconnu, mot de passe incorrect, etc.) sont affichées telles quelles.

- Cookies par défaut : `/tmp/sfrhome_session_cookies.json`

## Dépannage

- Logs : **Analyse → Logs → `sfrhome`**.
- Si l'authentification échoue, supprimer le fichier de cookies puis relancer un test.
- Prérequis : PHP avec les extensions `curl` et `SimpleXML`.
