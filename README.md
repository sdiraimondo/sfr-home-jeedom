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
- Caméras SFR Home

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
- **Sécurité** : centrale d'alarme, sirènes, télécommandes, claviers, caméras,
  détecteurs d'ouverture, de mouvement, de fumée et de fuite d'eau
- **Chauffage** : capteurs de température/humidité et thermostats
- **Ouvrant** : volets roulants
- **Autre** : types non reconnus

Les équipements existants sont recatégorisés automatiquement lors de la première
synchronisation suivant la mise à jour. Les personnalisations effectuées ensuite
sont conservées.

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
