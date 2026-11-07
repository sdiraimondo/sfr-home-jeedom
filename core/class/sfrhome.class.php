<?php

class sfrhome extends eqLogic {
    public static function cron() {
        $eqLogics = eqLogic::byType('sfrhome');
        foreach ($eqLogics as $eqLogic) {
            $eqLogic->refresh();
        }
    }

    public static function cron5() {
        self::cron();
    }

    public static function cron15() {
        self::syncDevices();
    }

    public static function syncDevices() {
        $username = config::byKey('username', 'sfrhome');
        $password = config::byKey('password', 'sfrhome');
        $cookiePath = config::byKey('cookiePath', 'sfrhome', '/tmp/sfrhome_session_cookies.json');
        
        if (empty($username) || empty($password)) {
            log::add('sfrhome', 'error', __('Identifiants SFR non configurés', __FILE__));
            return;
        }
        
        $cmd = 'php ' . dirname(__FILE__) . '/../php/sfrhome_api.php';
        $cmd .= ' ' . escapeshellarg($username);
        $cmd .= ' ' . escapeshellarg($password);
        $cmd .= ' ' . escapeshellarg($cookiePath);
        exec($cmd, $output, $returnVar);
        
        self::createOrUpdateDevices();
    }

    public static function createOrUpdateDevices() {
        $file = '/tmp/sfrhome_devices.json';
        if (!file_exists($file)) {
            return;
        }
        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data)) {
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
                $eqLogic->setConfiguration('deviceType', $device['deviceType']);
                $eqLogic->setConfiguration('model', isset($device['model_type']) ? $device['model_type'] : '');
                $eqLogic->save();
                $eqLogic->createCmds($device);
                log::add('sfrhome', 'info', 'Nouveau périphérique créé: ' . $device['name']);
            } else {
                $eqLogic->setConfiguration('deviceType', $device['deviceType']);
                $eqLogic->setConfiguration('model', isset($device['model_type']) ? $device['model_type'] : '');
                $eqLogic->save();
                $eqLogic->updateCmds($device);
            }
        }
    }

    public function preInsert() {
        $this->setCategory('security', 1);
        $this->setIsEnable(1);
        $this->setIsVisible(1);
    }

    public function postSave() {
        // Ensure commands exist
        $deviceType = $this->getConfiguration('deviceType');
        if (!empty($deviceType) && count($this->getCmd()) == 0) {
            $this->createCmds(array('deviceType' => $deviceType));
        }
    }

    public function refresh() {
        $username = config::byKey('username', 'sfrhome');
        $password = config::byKey('password', 'sfrhome');
        $cookiePath = config::byKey('cookiePath', 'sfrhome', '/tmp/sfrhome_session_cookies.json');
        
        if (empty($username) || empty($password)) {
            return;
        }
        
        // If this is the main controller, do a full sync
        if ($this->getLogicalId() == 'controller') {
            self::syncDevices();
            return;
        }
        
        // For individual devices, just update from cache
        $file = '/tmp/sfrhome_devices.json';
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            if (is_array($data)) {
                foreach ($data as $device) {
                    if ($device['id'] == $this->getLogicalId()) {
                        $this->updateCmds($device);
                        break;
                    }
                }
            }
        }
    }

    public function createCmds($device) {
        $deviceType = isset($device['deviceType']) ? $device['deviceType'] : $this->getConfiguration('deviceType');
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
            $this->createInfoCmd('Etat', 'light_state', 'info', 'binary');
        } elseif ($deviceType == 'CAMERA_WIFI') {
            $this->createInfoCmd('Mouvement', 'motion', 'info', 'binary');
            $this->createInfoCmd('URL snapshot', 'snapshot_url', 'info', 'string');
        }
    }

    public function updateCmds($device) {
        $deviceType = isset($device['deviceType']) ? $device['deviceType'] : $this->getConfiguration('deviceType');
        $status = isset($device['status']) ? $device['status'] : '';
        
        $statusCmd = $this->getCmd(null, 'status');
        if (is_object($statusCmd)) {
            $statusCmd->event($status);
        }
        
        if ($deviceType == 'TEMP_HUM' && isset($device['sensorValues'])) {
            if (isset($device['sensorValues']['Temperature'])) {
                $val = str_replace('°C', '', $device['sensorValues']['Temperature']['value']);
                $this->checkAndUpdateCmd('temperature', floatval($val));
            }
            if (isset($device['sensorValues']['Humidity'])) {
                $val = str_replace('%', '', $device['sensorValues']['Humidity']['value']);
                $this->checkAndUpdateCmd('humidity', floatval($val));
            }
        } elseif ($deviceType == 'MAGNETIC') {
            $contact = ($status == 'TRIGGERED' || $status == 'OPEN') ? 0 : 1;
            $this->checkAndUpdateCmd('contact', $contact);
        } elseif ($deviceType == 'PIR_DETECTOR') {
            $motion = ($status == 'TRIGGERED') ? 1 : 0;
            $this->checkAndUpdateCmd('motion', $motion);
        } elseif ($deviceType == 'SMOKE') {
            $smoke = ($status == 'TRIGGERED' || $status == 'ALARM') ? 1 : 0;
            $this->checkAndUpdateCmd('smoke', $smoke);
        } elseif ($deviceType == 'ALARM_PANEL') {
            $this->checkAndUpdateCmd('alarm_mode', $status);
        } elseif ($deviceType == 'CAMERA_WIFI') {
            $motion = ($status == 'TRIGGERED' || $status == 'MOTION') ? 1 : 0;
            $this->checkAndUpdateCmd('motion', $motion);
            if (isset($device['deviceMac'])) {
                $this->checkAndUpdateCmd('snapshot_url', 'https://home.sfr.fr/homescope/snapshot?mac=' . $device['deviceMac']);
            }
        }
    }

    public function createInfoCmd($name, $logicalId, $type, $subType, $unite = '') {
        $cmd = $this->getCmd(null, $logicalId);
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
        $cmd = $this->getCmd(null, $logicalId);
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
        
        $cmd = 'php ' . dirname(__FILE__) . '/../php/sfrhome_control.php';
        $cmd .= ' ' . escapeshellarg($username);
        $cmd .= ' ' . escapeshellarg($password);
        $cmd .= ' ' . escapeshellarg($cookiePath);
        
        if ($logicalId == 'on') {
            $cmd .= ' ' . escapeshellarg($deviceId) . ' on';
            exec($cmd);
        } elseif ($logicalId == 'off') {
            $cmd .= ' ' . escapeshellarg($deviceId) . ' off';
            exec($cmd);
        } elseif ($logicalId == 'alarm_on') {
            $cmd .= ' alarm on';
            exec($cmd);
        } elseif ($logicalId == 'alarm_off') {
            $cmd .= ' alarm off';
            exec($cmd);
        } elseif ($logicalId == 'alarm_custom') {
            $cmd .= ' alarm custom';
            exec($cmd);
        }
    }
}
?>
