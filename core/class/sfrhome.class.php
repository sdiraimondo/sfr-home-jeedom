<?php

class sfrhome extends eqLogic {

    /**
     * Version du mapping des catégories. À incrémenter pour forcer la
     * réapplication des catégories sur les équipements existants.
     */
    const CATEGORY_VERSION = 2;

    /**
     * Catégories Jeedom utilisées par le plugin.
     */
    private static $jeedomCategories = array(
        'heating', 'security', 'energy', 'light', 'opening', 'automatism', 'multimedia', 'default',
    );

    /**
     * Cron appelé toutes les minutes par Jeedom.
     * On ne rafraîchit que si l'intervalle configuré est écoulé.
     */
    public static function cron() {
        if (config::byKey('autoSync', 'sfrhome', 1) != 1) {
            return;
        }
        $interval = max(30, intval(config::byKey('refreshInterval', 'sfrhome', 60)));
        $lastSync = intval(config::byKey('lastSync', 'sfrhome', 0));
        if ((time() - $lastSync) < $interval) {
            return;
        }
        self::syncDevices();
    }

    public static function cron15() {
        // Conservé pour compatibilité, la logique est dans cron()
        self::cron();
    }

    /**
     * Récupère les périphériques depuis SFR Home et met à jour Jeedom.
     */
    public static function syncDevices() {
        $username = trim(config::byKey('username', 'sfrhome', ''));
        $password = config::byKey('password', 'sfrhome', '');
        $cookiePath = config::byKey('cookiePath', 'sfrhome', '/tmp/sfrhome_session_cookies.json');

        if ($username === '' || $password === '') {
            log::add('sfrhome', 'error', __('Identifiants SFR non configurés', __FILE__));
            return array('ok' => false, 'message' => __('Identifiants SFR non configurés', __FILE__));
        }

        $result = self::callApi($username, $password, $cookiePath, false);
        if (!$result['ok']) {
            log::add('sfrhome', 'error', __('Synchronisation SFR Home échouée : ', __FILE__) . $result['message']);
            return $result;
        }

        config::save('lastSync', time(), 'sfrhome');
        self::applyDevices();
        log::add('sfrhome', 'debug', __('Synchronisation terminée : ', __FILE__) . $result['message']);
        return $result;
    }

    /**
     * Teste la connexion SFR Home avec les identifiants fournis.
     * Si des identifiants sont passés (formulaire non encore sauvegardé), ils sont utilisés en priorité.
     */
    public static function testConnection($username = null, $password = null, $cookiePath = null) {
        if ($username === null || $username === '') {
            $username = config::byKey('username', 'sfrhome', '');
        }
        if ($password === null || $password === '') {
            $password = config::byKey('password', 'sfrhome', '');
        }
        if ($cookiePath === null || $cookiePath === '') {
            $cookiePath = config::byKey('cookiePath', 'sfrhome', '/tmp/sfrhome_session_cookies.json');
        }
        $username = trim($username);

        if ($username === '' || $password === '') {
            return array('ok' => false, 'message' => __('Veuillez renseigner l\'identifiant et le mot de passe SFR.', __FILE__));
        }

        $result = self::callApi($username, $password, $cookiePath, true);
        if ($result['ok']) {
            return array(
                'ok' => true,
                'message' => __('Connexion réussie. ', __FILE__) . $result['message']
            );
        }
        return array('ok' => false, 'message' => $result['message']);
    }

    /**
     * Exécute le script PHP d'accès à l'API SFR et retourne son résultat JSON.
     */
    private static function callApi($username, $password, $cookiePath, $testOnly) {
        $script = dirname(__FILE__) . '/../php/sfrhome_api.php';
        if (!file_exists($script)) {
            return array('ok' => false, 'message' => __('Script API introuvable : ', __FILE__) . $script);
        }

        // En contexte web, PHP_BINARY peut pointer vers php-fpm : on utilise le PHP du PATH,
        // comme le fait le coeur de Jeedom (system::php()).
        $php = 'php';
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script);
        $cmd .= ' ' . escapeshellarg($username);
        $cmd .= ' ' . escapeshellarg($password);
        $cmd .= ' ' . escapeshellarg($cookiePath);
        if ($testOnly) {
            $cmd .= ' --test';
        }
        $cmd .= ' 2>&1';

        exec($cmd, $output, $returnVar);
        $raw = implode("\n", $output);

        // Le JSON attendu est la dernière ligne de la sortie ; on tolère d'éventuels
        // messages/warnings PHP émis avant.
        $json = json_decode(trim($raw), true);
        if (!is_array($json)) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $raw)), function ($line) {
                return $line !== '';
            }));
            $lastLine = end($lines);
            if ($lastLine !== false) {
                $json = json_decode($lastLine, true);
            }
        }
        if (!is_array($json) && preg_match('/\{.*\}\s*$/s', $raw, $matches)) {
            $json = json_decode($matches[0], true);
        }

        if (is_array($json) && isset($json['ok'])) {
            return $json;
        }

        return array(
            'ok' => false,
            'message' => __('Réponse inattendue du script API (code ', __FILE__) . $returnVar . ') : ' . $raw
        );
    }

    /**
     * Retourne les catégories Jeedom correspondant à un type de périphérique SFR.
     * SFR ne fournit pas de catégorie explicite : elle est déduite du deviceType.
     */
    public static function getCategoriesForDevice($deviceType) {
        $type = strtoupper(trim((string)$deviceType));

        $map = array(
            // Éclairage
            'LED_BULB_DIMMER'    => array('light'),
            'LED_BULB_HUE'       => array('light'),
            'LED_BULB_COLOR'     => array('light'),
            'LIGHT'              => array('light'),
            'LAMP'               => array('light'),
            'DIMMER_LIGHT'       => array('light'),
            // Prises et énergie
            'ON_OFF_PLUG'        => array('energy'),
            'PLUG'               => array('energy'),
            'SMART_PLUG'         => array('energy'),
            // Sécurité
            'ALARM_PANEL'        => array('security'),
            'SIREN'              => array('security'),
            'SOLAR_SIREN'        => array('security'),
            'REMOTE'             => array('security'),
            'KEYPAD'             => array('security'),
            'MAGNETIC'           => array('security'),
            'PIR_DETECTOR'       => array('security'),
            'SMOKE'              => array('security'),
            'WATER_LEAK'         => array('security'),
            // Caméras (Jeedom les classe en Multimédia)
            'CAMERA'             => array('multimedia'),
            'CAMERA_WIFI'        => array('multimedia'),
            'PIR_CAMERA'         => array('multimedia'),
            // Température et chauffage
            'TEMP_HUM'           => array('heating'),
            'THERMOSTAT'         => array('heating'),
            'TEMPERATURE_SENSOR' => array('heating'),
            'HUMIDITY_SENSOR'    => array('heating'),
            // Volets et ouvrants
            'SHUTTER_COMMAND'    => array('opening'),
            'SHUTTER_CONTROL'    => array('opening'),
        );

        if (isset($map[$type])) {
            return $map[$type];
        }

        // Repli par famille pour les variantes non listées.
        if (strpos($type, 'BULB') !== false || strpos($type, 'LIGHT') !== false
            || strpos($type, 'LAMP') !== false || strpos($type, 'DIMMER') !== false) {
            return array('light');
        }
        if (strpos($type, 'PLUG') !== false || strpos($type, 'SOCKET') !== false
            || strpos($type, 'POWER') !== false || strpos($type, 'METER') !== false) {
            return array('energy');
        }
        if (strpos($type, 'TEMP') !== false || strpos($type, 'THERMO') !== false
            || strpos($type, 'HEAT') !== false || strpos($type, 'HUMID') !== false) {
            return array('heating');
        }
        if (strpos($type, 'SHUTTER') !== false || strpos($type, 'BLIND') !== false
            || strpos($type, 'CURTAIN') !== false || strpos($type, 'GARAGE') !== false
            || strpos($type, 'GATE') !== false) {
            return array('opening');
        }
        if (strpos($type, 'CAMERA') !== false) {
            return array('multimedia');
        }
        if (strpos($type, 'ALARM') !== false || strpos($type, 'SIREN') !== false
            || strpos($type, 'DETECTOR') !== false || strpos($type, 'SENSOR') !== false
            || strpos($type, 'SMOKE') !== false || strpos($type, 'MAGNETIC') !== false
            || strpos($type, 'CONTACT') !== false || strpos($type, 'MOTION') !== false
            || strpos($type, 'LEAK') !== false
            || strpos($type, 'KEYPAD') !== false || strpos($type, 'REMOTE') !== false) {
            return array('security');
        }

        return array('default');
    }

    /**
     * Indique si un type SFR correspond à une caméra.
     */
    public static function isCameraType($deviceType) {
        $type = strtoupper(trim((string)$deviceType));
        return $type === 'CAMERA' || $type === 'CAMERA_WIFI' || $type === 'PIR_CAMERA'
            || strpos($type, 'CAMERA') !== false;
    }

    /**
     * Applique (et remplace) les catégories Jeedom d'un équipement.
     */
    private static function applyCategories($eqLogic, $deviceType) {
        $wanted = self::getCategoriesForDevice($deviceType);
        foreach (self::$jeedomCategories as $category) {
            $eqLogic->setCategory($category, in_array($category, $wanted, true) ? 1 : 0);
        }
    }

    /**
     * Génère (une seule fois) le jeton qui protège le proxy de snapshot.
     */
    private static function ensureSnapshotToken() {
        if (config::byKey('snapshotToken', 'sfrhome', '') !== '') {
            return;
        }
        try {
            config::save('snapshotToken', bin2hex(random_bytes(16)), 'sfrhome');
        } catch (Exception $e) {
            log::add('sfrhome', 'warning', __('Impossible de générer le jeton snapshot : ', __FILE__) . $e->getMessage());
        }
    }

    /**
     * Enregistre les informations caméra utiles dans la configuration de l'équipement.
     * Retourne true si une valeur a changé.
     */
    private static function updateCameraConfiguration($eqLogic, $device) {
        $deviceType = isset($device['deviceType']) ? $device['deviceType'] : '';
        if (!self::isCameraType($deviceType)) {
            return false;
        }

        $camera = (isset($device['camera']) && is_array($device['camera'])) ? $device['camera'] : array();
        $mac = isset($device['deviceMac']) ? strtolower(trim($device['deviceMac'])) : '';

        $values = array(
            'cameraMac'         => $mac,
            'cameraId'          => isset($camera['cameraId']) ? $camera['cameraId'] : '',
            'cameraSnapshotUrl' => isset($camera['snapshotUrl']) ? $camera['snapshotUrl'] : '',
            'cameraStreamUrl'   => isset($camera['streamUrl']) ? $camera['streamUrl'] : '',
        );

        $changed = false;
        foreach ($values as $key => $value) {
            // Ne pas effacer une information déjà connue si /mycams est momentanément indisponible.
            if ($value === '' && $key !== 'cameraMac') {
                continue;
            }
            if ($eqLogic->getConfiguration($key) !== $value) {
                $eqLogic->setConfiguration($key, $value);
                $changed = true;
            }
        }
        return $changed;
    }

    /**
     * Crée ou met à jour les équipements Jeedom depuis le cache JSON.
     */
    public static function applyDevices() {
        $file = '/tmp/sfrhome_devices.json';
        if (!file_exists($file)) {
            log::add('sfrhome', 'warning', __('Fichier des périphériques introuvable : ', __FILE__) . $file);
            return;
        }
        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data)) {
            log::add('sfrhome', 'error', __('Fichier des périphériques invalide', __FILE__));
            return;
        }

        self::ensureSnapshotToken();

        foreach ($data as $device) {
            if (empty($device['id']) || empty($device['name'])) {
                continue;
            }

            $deviceType = isset($device['deviceType']) ? $device['deviceType'] : '';
            $eqLogic = eqLogic::byLogicalId($device['id'], 'sfrhome');
            if (!is_object($eqLogic)) {
                $eqLogic = new sfrhome();
                $eqLogic->setEqType_name('sfrhome');
                $eqLogic->setLogicalId($device['id']);
                $eqLogic->setName($device['name']);
                $eqLogic->setIsEnable(1);
                $eqLogic->setIsVisible(1);
                self::applyCategories($eqLogic, $deviceType);
                $eqLogic->setConfiguration('categoryVersion', self::CATEGORY_VERSION);
                $eqLogic->setConfiguration('deviceType', $deviceType);
                $eqLogic->setConfiguration('model', isset($device['model_type']) ? $device['model_type'] : '');
                self::updateCameraConfiguration($eqLogic, $device);
                $eqLogic->save();
                $eqLogic->createCmds($device);
                log::add('sfrhome', 'info', __('Nouveau périphérique créé : ', __FILE__) . $device['name'] . ' (' . $deviceType . ')');
            } else {
                $typeChanged = ($eqLogic->getConfiguration('deviceType') !== $deviceType);
                $storedCategoryVersion = intval($eqLogic->getConfiguration('categoryVersion', 0));
                $categoryOutdated = $storedCategoryVersion < self::CATEGORY_VERSION;

                // Migration ciblée : en 0.2.2 les caméras étaient classées en "security".
                $applyCategories = $typeChanged;
                if ($categoryOutdated) {
                    if ($storedCategoryVersion < 1) {
                        $applyCategories = true;
                    } elseif (self::isCameraType($deviceType) && intval($eqLogic->getCategory('security', 0)) === 1) {
                        $applyCategories = true;
                    }
                }

                $cameraChanged = self::updateCameraConfiguration($eqLogic, $device);
                $changed = $typeChanged
                    || $categoryOutdated
                    || $cameraChanged
                    || ($eqLogic->getConfiguration('model') !== (isset($device['model_type']) ? $device['model_type'] : ''));

                if ($changed) {
                    if ($applyCategories) {
                        self::applyCategories($eqLogic, $deviceType);
                    }
                    if ($typeChanged || $categoryOutdated) {
                        $eqLogic->setConfiguration('categoryVersion', self::CATEGORY_VERSION);
                    }
                    $eqLogic->setConfiguration('deviceType', $deviceType);
                    $eqLogic->setConfiguration('model', isset($device['model_type']) ? $device['model_type'] : '');
                    $eqLogic->save();
                }
                $eqLogic->createCmds($device);
            }
            $eqLogic->updateCmds($device);
        }
    }

    public function preInsert() {
        $this->setIsEnable(1);
        $this->setIsVisible(1);
    }

    public function postSave() {
        if (count($this->getCmd()) == 0) {
            $deviceType = $this->getConfiguration('deviceType');
            if (!empty($deviceType)) {
                $this->createCmds(array('deviceType' => $deviceType, 'id' => $this->getLogicalId()));
            }
        }
    }

    /**
     * Rafraîchissement manuel d'un équipement : déclenche une synchro globale.
     */
    public function refresh() {
        self::syncDevices();
    }

    /**
     * Retourne une commande par son logicalId (évite les ambiguïtés de getCmd).
     */
    public function getCmdByLogicalId($logicalId) {
        foreach ($this->getCmd() as $cmd) {
            if ($cmd->getLogicalId() == $logicalId) {
                return $cmd;
            }
        }
        return null;
    }

    public function createCmds($device) {
        $deviceType = strtoupper(isset($device['deviceType']) ? $device['deviceType'] : $this->getConfiguration('deviceType'));

        $this->createInfoCmd('Etat', 'status', 'info', 'string');

        if ($deviceType == 'ON_OFF_PLUG') {
            $this->createActionCmd('On', 'on', 'action', 'other');
            $this->createActionCmd('Off', 'off', 'action', 'other');
            $this->createInfoCmd('Etat binaire', 'state', 'info', 'binary');
            $this->setCmdGenericType('on', 'ENERGY_ON');
            $this->setCmdGenericType('off', 'ENERGY_OFF');
            $this->setCmdGenericType('state', 'ENERGY_STATE');
        } elseif ($deviceType == 'SHUTTER_COMMAND' || $deviceType == 'SHUTTER_CONTROL') {
            $this->createActionCmd('Ouvrir', 'on', 'action', 'other');
            $this->createActionCmd('Fermer', 'off', 'action', 'other');
            $this->setCmdGenericType('on', 'FLAP_UP');
            $this->setCmdGenericType('off', 'FLAP_DOWN');
        } elseif ($deviceType == 'ALARM_PANEL') {
            $this->createActionCmd('Désactiver', 'alarm_off', 'action', 'other');
            $this->createActionCmd('Activer (Absent)', 'alarm_on', 'action', 'other');
            $this->createActionCmd('Nuit', 'alarm_custom', 'action', 'other');
            $this->createInfoCmd('Mode alarme', 'alarm_mode', 'info', 'string');
            $this->setCmdGenericType('alarm_off', 'ALARM_RELEASED');
            $this->setCmdGenericType('alarm_on', 'ALARM_ARMED');
            $this->setCmdGenericType('alarm_mode', 'ALARM_MODE');
        } elseif ($deviceType == 'TEMP_HUM') {
            $this->createInfoCmd('Température', 'temperature', 'info', 'numeric', '°C');
            $this->createInfoCmd('Humidité', 'humidity', 'info', 'numeric', '%');
            $this->setCmdGenericType('temperature', 'TEMPERATURE');
            $this->setCmdGenericType('humidity', 'HUMIDITY');
        } elseif ($deviceType == 'MAGNETIC') {
            $this->createInfoCmd('Contact', 'contact', 'info', 'binary');
            $this->setCmdGenericType('contact', 'OPENING');
        } elseif ($deviceType == 'PIR_DETECTOR') {
            $this->createInfoCmd('Mouvement', 'motion', 'info', 'binary');
            $this->setCmdGenericType('motion', 'PRESENCE');
        } elseif ($deviceType == 'SMOKE') {
            $this->createInfoCmd('Fumée', 'smoke', 'info', 'binary');
            $this->setCmdGenericType('smoke', 'SMOKE');
        } elseif (in_array($deviceType, array('LED_BULB_DIMMER', 'LED_BULB_HUE', 'LED_BULB_COLOR'))) {
            $this->createActionCmd('On', 'on', 'action', 'other');
            $this->createActionCmd('Off', 'off', 'action', 'other');
            $this->createInfoCmd('Etat binaire', 'state', 'info', 'binary');
            $this->setCmdGenericType('on', 'LIGHT_ON');
            $this->setCmdGenericType('off', 'LIGHT_OFF');
            $this->setCmdGenericType('state', 'LIGHT_STATE');
        } elseif (self::isCameraType($deviceType)) {
            $this->createInfoCmd('Mouvement', 'motion', 'info', 'binary');
            $this->createInfoCmd('URL snapshot', 'snapshot_url', 'info', 'string');
            $this->createInfoCmd('URL flux', 'stream_url', 'info', 'string');
            $this->createInfoCmd('En ligne', 'online', 'info', 'binary');
            $this->createInfoCmd('Confidentialité', 'privacy', 'info', 'binary');
            $this->createInfoCmd('Enregistrement 24h/24', 'rec24', 'info', 'binary');
            $this->createInfoCmd('Profil de détection', 'detection_profile', 'info', 'numeric');
            $this->createInfoCmd('Sensibilité', 'sensitivity', 'info', 'numeric');
            $this->createInfoCmd('Image inversée', 'flip', 'info', 'binary');
            $this->createInfoCmd('LED éteinte', 'led_off', 'info', 'binary');
            $this->createActionCmd("Démarrer l'enregistrement", 'camera_record', 'action', 'other');
            $this->createActionCmd("Arrêter l'enregistrement", 'camera_stop_record', 'action', 'other');
            $this->setCmdGenericType('motion', 'PRESENCE');
            $this->setCmdGenericType('snapshot_url', 'CAMERA_URL');
            $this->setCmdGenericType('online', 'ONLINE');
            $this->setCmdGenericType('rec24', 'CAMERA_RECORD_STATE');
            $this->setCmdGenericType('camera_record', 'CAMERA_RECORD');
        }
    }

    public function updateCmds($device) {
        $deviceType = strtoupper(isset($device['deviceType']) ? $device['deviceType'] : $this->getConfiguration('deviceType'));
        $status = isset($device['status']) ? strtoupper($device['status']) : '';
        $sensorValues = isset($device['sensorValues']) && is_array($device['sensorValues']) ? $device['sensorValues'] : array();
        $automation = isset($device['automation']) && is_array($device['automation']) ? $device['automation'] : array();
        $onOff = self::boolFromValue(isset($automation['on_off']) ? $automation['on_off'] : '');
        $triggerValue = array_key_exists('trigger', $device) ? self::boolFromValue($device['trigger']) : null;

        $this->checkAndUpdateCmd('status', $status);

        $isTriggered = $triggerValue !== null ? $triggerValue : in_array($status, array('TRIGGERED', 'OPEN', 'ALARM', 'MOTION'));

        if ($deviceType == 'TEMP_HUM') {
            if (isset($sensorValues['Temperature'])) {
                $val = str_replace(array('°C', 'C', ' '), '', $sensorValues['Temperature']['value']);
                $this->checkAndUpdateCmd('temperature', floatval($val));
            }
            if (isset($sensorValues['Humidity'])) {
                $val = str_replace(array('%', ' '), '', $sensorValues['Humidity']['value']);
                $this->checkAndUpdateCmd('humidity', floatval($val));
            }
        } elseif ($deviceType == 'MAGNETIC') {
            // Capteurs d'alarme SFR : <trigger>1</trigger> = ouvert/déclenché.
            // automation.on_off peut exister selon les installations ; trigger est prioritaire.
            $contact = $triggerValue !== null ? $triggerValue : $onOff;
            if ($contact !== null) {
                $this->checkAndUpdateCmd('contact', $contact ? 1 : 0);
            }
        } elseif ($deviceType == 'PIR_DETECTOR') {
            $motion = $triggerValue !== null ? $triggerValue : $onOff;
            if ($motion !== null) {
                $this->checkAndUpdateCmd('motion', $motion ? 1 : 0);
            }
        } elseif ($deviceType == 'SMOKE') {
            $smoke = $triggerValue !== null ? $triggerValue : $onOff;
            if ($smoke !== null) {
                $this->checkAndUpdateCmd('smoke', $smoke ? 1 : 0);
            }
        } elseif ($deviceType == 'ALARM_PANEL') {
            $this->checkAndUpdateCmd('alarm_mode', $status);
        } elseif ($deviceType == 'ON_OFF_PLUG' || in_array($deviceType, array('LED_BULB_DIMMER', 'LED_BULB_HUE', 'LED_BULB_COLOR'))) {
            $on = $onOff;
            if ($on === null) {
                $on = self::boolFromValue(isset($sensorValues['trigger']) ? $sensorValues['trigger']['value'] : '');
            }
            if ($on === null) {
                $on = ($status !== 'UNREACHABLE' && $status !== 'OFF') ? 1 : 0;
            }
            $this->checkAndUpdateCmd('state', $on ? 1 : 0);
        } elseif (self::isCameraType($deviceType)) {
            $camera = (isset($device['camera']) && is_array($device['camera'])) ? $device['camera'] : array();
            $this->checkAndUpdateCmd('motion', $isTriggered ? 1 : 0);

            $mac = $this->getConfiguration('cameraMac', isset($device['deviceMac']) ? $device['deviceMac'] : '');
            if ($mac !== '') {
                $this->checkAndUpdateCmd('snapshot_url', $this->getSnapshotProxyUrl($mac));
            }

            if (!empty($camera)) {
                $this->checkAndUpdateCmd('online', !empty($camera['reachable']) ? 1 : 0);
                $this->checkAndUpdateCmd('privacy', !empty($camera['privacy']) ? 1 : 0);
                $this->checkAndUpdateCmd('rec24', !empty($camera['rec24']) ? 1 : 0);
                $this->checkAndUpdateCmd('detection_profile', isset($camera['detectionProfile']) ? intval($camera['detectionProfile']) : 0);
                $this->checkAndUpdateCmd('sensitivity', isset($camera['sensitivity']) ? intval($camera['sensitivity']) : 0);
                $this->checkAndUpdateCmd('flip', !empty($camera['flip']) ? 1 : 0);
                $this->checkAndUpdateCmd('led_off', (isset($camera['ledMode']) && intval($camera['ledMode']) === 1) ? 1 : 0);
                $this->checkAndUpdateCmd('stream_url', isset($camera['streamUrl']) ? $camera['streamUrl'] : '');
            }
        }
    }

    /**
     * URL du proxy local qui sert le snapshot avec les cookies SFR du plugin.
     */
    private function getSnapshotProxyUrl($mac) {
        $url = '/plugins/sfrhome/core/ajax/sfrhome_snapshot.php?eqLogic_id=' . intval($this->getId());
        $url .= '&mac=' . rawurlencode(strtolower(trim((string)$mac)));
        $token = config::byKey('snapshotToken', 'sfrhome', '');
        if ($token !== '') {
            $url .= '&token=' . rawurlencode($token);
        }

        // URL absolue si Jeedom dispose d'un DNS/URL connu, sinon URL relative.
        $base = config::byKey('jeedom::url', 'core', '');
        if ($base !== '' && preg_match('#^https?://#i', $base)) {
            return rtrim($base, '/') . $url;
        }
        return $url;
    }

    private static function boolFromValue($value) {
        if (is_bool($value)) {
            return $value;
        }
        $value = strtolower(trim((string)$value));
        if (in_array($value, array('1', 'on', 'true', 'yes'))) {
            return true;
        }
        if (in_array($value, array('0', 'off', 'false', 'no'))) {
            return false;
        }
        return null;
    }

    public function createInfoCmd($name, $logicalId, $type, $subType, $unite = '') {
        $cmd = $this->getCmdByLogicalId($logicalId);
        if (!is_object($cmd)) {
            $cmd = new sfrhomeCmd();
            $cmd->setName($name);
            $cmd->setEqLogic_id($this->getId());
            $cmd->setLogicalId($logicalId);
            $cmd->setType($type);
            $cmd->setSubType($subType);
            if ($unite != '') {
                $cmd->setUnite($unite);
            }
            $cmd->save();
        }
        return $cmd;
    }

    public function createActionCmd($name, $logicalId, $type, $subType) {
        $cmd = $this->getCmdByLogicalId($logicalId);
        if (!is_object($cmd)) {
            $cmd = new sfrhomeCmd();
            $cmd->setName($name);
            $cmd->setEqLogic_id($this->getId());
            $cmd->setLogicalId($logicalId);
            $cmd->setType($type);
            $cmd->setSubType($subType);
            $cmd->save();
        }
        return $cmd;
    }

    /**
     * Associe un type générique Jeedom à une commande existante.
     */
    public function setCmdGenericType($logicalId, $genericType) {
        $cmd = $this->getCmdByLogicalId($logicalId);
        if (is_object($cmd) && $cmd->getGeneric_type() !== $genericType) {
            $cmd->setGeneric_type($genericType);
            $cmd->save();
        }
    }

    public static function sync() {
        self::syncDevices();
    }
}

class sfrhomeCmd extends cmd {
    public function execute($_options = array()) {
        $eqLogic = $this->getEqLogic();
        $deviceId = $eqLogic->getLogicalId();
        $logicalId = $this->getLogicalId();

        $username = config::byKey('username', 'sfrhome');
        $password = config::byKey('password', 'sfrhome');
        $cookiePath = config::byKey('cookiePath', 'sfrhome', '/tmp/sfrhome_session_cookies.json');

        $php = 'php';
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg(dirname(__FILE__) . '/../php/sfrhome_control.php');
        $cmd .= ' ' . escapeshellarg($username);
        $cmd .= ' ' . escapeshellarg($password);
        $cmd .= ' ' . escapeshellarg($cookiePath);

        if ($logicalId == 'on' || $logicalId == 'off') {
            $cmd .= ' ' . escapeshellarg($deviceId) . ' ' . escapeshellarg($logicalId);
        } elseif ($logicalId == 'camera_record' || $logicalId == 'camera_stop_record') {
            $mac = $eqLogic->getConfiguration('cameraMac', $eqLogic->getConfiguration('deviceMac', ''));
            $cmd .= ' ' . escapeshellarg($deviceId) . ' ' . escapeshellarg($logicalId) . ' ' . escapeshellarg($mac);
        } elseif ($logicalId == 'alarm_on') {
            $cmd .= ' alarm on';
        } elseif ($logicalId == 'alarm_off') {
            $cmd .= ' alarm off';
        } elseif ($logicalId == 'alarm_custom') {
            $cmd .= ' alarm custom';
        } else {
            return;
        }

        exec($cmd . ' 2>&1', $output, $returnVar);
        log::add('sfrhome', 'debug', __('Commande ', __FILE__) . $logicalId . ' -> ' . implode("\n", $output));
    }
}
?>
