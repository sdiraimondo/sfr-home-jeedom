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
- Caméras SFR Home

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
- **Sécurité** : centrale d'alarme, sirènes, télécommandes, claviers, caméras,
  détecteurs d'ouverture, de mouvement, de fumée et de fuite d'eau
- **Chauffage** : capteurs de température/humidité et thermostats
- **Ouvrant** : volets roulants
- **Autre** : types non reconnus

Les équipements existants sont recatégorisés automatiquement lors de la première
synchronisation suivant la mise à jour.

## Authentification

Le plugin utilise le formulaire de connexion de SFR Home (avec jeton CSRF),
avec gestion et renouvellement automatique des cookies de session. En cas de session
expirée, une réauthentification est effectuée automatiquement.

## Dépannage

- Vérifier les logs dans **Analyse → Logs → sfrhome**.
- Si l'authentification échoue, supprimer le fichier de cookies
  (par défaut `/tmp/sfrhome_session_cookies.json`) puis relancer un test.
- PHP avec les extensions `curl` et `SimpleXML` est requis.
