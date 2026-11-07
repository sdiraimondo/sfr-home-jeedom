<?php

class sfrhome extends eqLogic {

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

        foreach ($data as $device) {
            if (empty($device['id']) || empty($device['name'])) {
                continue;
            }

            $eqLogic = eqLogic::byLogicalId($device['id'], 'sfrhome');
            if (!is_object($eqLogic)) {
                $eqLogic = new sfrhome();
                $eqLogic->setEqType_name('sfrhome');
                $eqLogic->setLogicalId($device['id']);
                $eqLogic->setName($device['name']);
                $eqLogic->setIsEnable(1);
                $eqLogic->setIsVisible(1);
                $eqLogic->setCategory('security', 1);
                $eqLogic->setConfiguration('deviceType', $device['deviceType']);
                $eqLogic->setConfiguration('model', isset($device['model_type']) ? $device['model_type'] : '');
                $eqLogic->save();
                $eqLogic->createCmds($device);
                log::add('sfrhome', 'info', __('Nouveau périphérique créé : ', __FILE__) . $device['name'] . ' (' . $device['deviceType'] . ')');
            } else {
                $changed = ($eqLogic->getConfiguration('deviceType') !== $device['deviceType'])
                    || ($eqLogic->getConfiguration('model') !== (isset($device['model_type']) ? $device['model_type'] : ''));
                if ($changed) {
                    $eqLogic->setConfiguration('deviceType', $device['deviceType']);
                    $eqLogic->setConfiguration('model', isset($device['model_type']) ? $device['model_type'] : '');
                    $eqLogic->save();
                }
                $eqLogic->createCmds($device);
            }
            $eqLogic->updateCmds($device);
        }
    }

    public function preInsert() {
        $this->setCategory('security', 1);
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
        } elseif ($deviceType == 'SHUTTER_COMMAND' || $deviceType == 'SHUTTER_CONTROL') {
            $this->createActionCmd('Ouvrir', 'on', 'action', 'other');
            $this->createActionCmd('Fermer', 'off', 'action', 'other');
        } elseif ($deviceType == 'ALARM_PANEL') {
            $this->createActionCmd('Désactiver', 'alarm_off', 'action', 'other');
            $this->createActionCmd('Activer (Absent)', 'alarm_on', 'action', 'other');
            $this->createActionCmd('Nuit', 'alarm_custom', 'action', 'other');
            $this->createInfoCmd('Mode alarme', 'alarm_mode', 'info', 'string');
        } elseif ($deviceType == 'TEMP_HUM') {
            $this->createInfoCmd('Température', 'temperature', 'info', 'numeric', '°C');
            $this->createInfoCmd('Humidité', 'humidity', 'info', 'numeric', '%');
        } elseif ($deviceType == 'MAGNETIC') {
            $this->createInfoCmd('Contact', 'contact', 'info', 'binary');
        } elseif ($deviceType == 'PIR_DETECTOR') {
            $this->createInfoCmd('Mouvement', 'motion', 'info', 'binary');
        } elseif ($deviceType == 'SMOKE') {
            $this->createInfoCmd('Fumée', 'smoke', 'info', 'binary');
        } elseif (in_array($deviceType, array('LED_BULB_DIMMER', 'LED_BULB_HUE', 'LED_BULB_COLOR'))) {
            $this->createActionCmd('On', 'on', 'action', 'other');
            $this->createActionCmd('Off', 'off', 'action', 'other');
            $this->createInfoCmd('Etat binaire', 'state', 'info', 'binary');
        } elseif ($deviceType == 'CAMERA_WIFI') {
            $this->createInfoCmd('Mouvement', 'motion', 'info', 'binary');
            $this->createInfoCmd('URL snapshot', 'snapshot_url', 'info', 'string');
        }
    }

    public function updateCmds($device) {
        $deviceType = strtoupper(isset($device['deviceType']) ? $device['deviceType'] : $this->getConfiguration('deviceType'));
        $status = isset($device['status']) ? strtoupper($device['status']) : '';
        $sensorValues = isset($device['sensorValues']) && is_array($device['sensorValues']) ? $device['sensorValues'] : array();

        $this->checkAndUpdateCmd('status', $status);

        $isTriggered = in_array($status, array('TRIGGERED', 'OPEN', 'ALARM', 'MOTION'));

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
            $this->checkAndUpdateCmd('contact', $isTriggered ? 0 : 1);
        } elseif ($deviceType == 'PIR_DETECTOR') {
            $this->checkAndUpdateCmd('motion', $isTriggered ? 1 : 0);
        } elseif ($deviceType == 'SMOKE') {
            $this->checkAndUpdateCmd('smoke', $isTriggered ? 1 : 0);
        } elseif ($deviceType == 'ALARM_PANEL') {
            $this->checkAndUpdateCmd('alarm_mode', $status);
        } elseif ($deviceType == 'ON_OFF_PLUG' || in_array($deviceType, array('LED_BULB_DIMMER', 'LED_BULB_HUE', 'LED_BULB_COLOR'))) {
            $on = self::boolFromValue(isset($sensorValues['trigger']) ? $sensorValues['trigger']['value'] : '');
            if ($on === null) {
                $on = ($status !== 'UNREACHABLE' && $status !== 'OFF') ? 1 : 0;
            }
            $this->checkAndUpdateCmd('state', $on ? 1 : 0);
        } elseif ($deviceType == 'CAMERA_WIFI') {
            $this->checkAndUpdateCmd('motion', $isTriggered ? 1 : 0);
            if (!empty($device['deviceMac'])) {
                $this->checkAndUpdateCmd('snapshot_url', 'https://home.sfr.fr/homescope/snapshot?mac=' . $device['deviceMac']);
            }
        }
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
