# Changelog plugin SFR Home

# 08/11/2026 - 0.2.4

- Correction de l'état des contacts magnétiques : utilisation de
  `automation.on_off` (`1` = ouvert, `0` = fermé) au lieu du champ `status`
- `Etat` reste un indicateur de santé du capteur (`OK`, `LOW_BAT`,
  `UNREACHABLE`…) et n'est plus confondu avec l'ouverture
- Même correction pour les détecteurs de mouvement et de fumée
- Analyse des vraies balises `<sensorValues name="...">valeur</sensorValues>`
  (température, humidité)
- Utilisation de `automation.on_off` pour l'état des prises et des lumières

# 08/11/2026 - 0.2.3

- Découverte des caméras via l'endpoint dédié SFR `/homescope/mycams`
- Fusion des caméras avec les capteurs `CAMERA_WIFI` / `PIR_CAMERA` par adresse
  MAC, sans créer de doublon
- Catégorie Jeedom **Multimédia** pour les caméras, avec migration automatique
  des caméras encore classées en **Sécurité** (les personnalisations ultérieures
  sont conservées)
- Nouvelles commandes caméra : en ligne, confidentialité, enregistrement 24h/24,
  profil de détection, sensibilité, image inversée, LED éteinte et URL du flux
- Snapshot affiché via un proxy local authentifié : les cookies SFR restent
  côté serveur
- Commandes **Démarrer l'enregistrement** et **Arrêter l'enregistrement**

# 08/11/2026 - 0.2.2

- Catégorisation automatique des équipements dans Jeedom selon leur type SFR
  (lumières, prises, caméras, alarme, capteurs, volets…)
- Migration automatique des équipements existants vers les bonnes catégories
- Ajout des types génériques des commandes (`LIGHT_STATE`, `ENERGY_STATE`,
  `TEMPERATURE`, `HUMIDITY`, `OPENING`, `PRESENCE`, etc.)

# 08/11/2026 - 0.2.1

- Correction de l'authentification : SFR a supprimé l'endpoint `sso-connector.php`
- Utilisation du nouveau formulaire `/login` avec jeton CSRF
- Messages d'erreur SFR remontés tels quels (ex. « Utilisateur inconnu. »)
- Gestion correcte des redirections avec mise à jour des cookies

# 07/11/2026 - 0.2.0

- Rafraîchissement automatique fiable des états (synchronisation globale selon la fréquence configurée)
- Ajout du bouton **Tester la connexion** dans la configuration
- Le test utilise les identifiants saisis, même avant sauvegarde
- Renouvellement automatique de la session lors des commandes (403)
- Parseur XML `/mysensors` plus robuste (structures attributs/éléments)
- Nettoyage des fichiers résiduels du template Jeedom

# 06/11/2026 - 0.1.0

- Version initiale du plugin
- Découverte automatique des équipements
- Contrôle des prises, volets, lumières et de l'alarme
- Capteurs de température/humidité, ouverture, mouvement et fumée
