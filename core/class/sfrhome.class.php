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

    public static function deamon_info() {
        $return = array();
        $return['log'] = 'sfrhome';
        $return['state'] = 'ok';
        $return['launchable'] = 'ok';
        $return['launchable_message'] = '';
        return $return;
    }

    public static function deamon_start() {
        self::deamon_stop();
        $deamon_info = self::deamon_info();
        if ($deamon_info['launchable'] != 'ok') {
            throw new Exception(__('Veuillez vérifier la configuration', __FILE__) . ' - ' . $deamon_info['launchable_message']);
        }
        $cmd = 'php ' . dirname(__FILE__) . '/../../resources/sfrhomed/sfrhomed.php';
        $cmd .= ' >> ' . log::getPathToLog('sfrhome') . ' 2>&1 &';
        exec($cmd);
    }

    public static function deamon_stop() {
        $pid_file = jeedom::getTmpFolder('sfrhome') . '/sfrhome.pid';
        if (file_exists($pid_file)) {
            $pid = intval(file_get_contents($pid_file));
            system('kill ' . $pid . ' 2>&1 > /dev/null');
            @unlink($pid_file);
        }
    }

    public function preInsert() {
        $this->setCategory('security', 1);
        $this->setIsEnable(0);
        $this->setIsVisible(0);
    }

    public function postSave() {
        if ($this->getIsEnable() == 1) {
            $this->refresh();
        }
    }

    public function refresh() {
        $username = config::byKey('username', 'sfrhome');
        $password = config::byKey('password', 'sfrhome');
        $cookiePath = config::byKey('cookiePath', 'sfrhome', '/tmp/sfrhome_session_cookies.json');
        
        if (empty($username) || empty($password)) {
            log::add('sfrhome', 'error', __('Identifiants SFR non configurés', __FILE__));
            return;
        }
        
        // Call Python script or PHP to fetch data
        $cmd = 'php ' . dirname(__FILE__) . '/../php/sfrhome_api.php';
        $cmd .= ' ' . escapeshellarg($username);
        $cmd .= ' ' . escapeshellarg($password);
        $cmd .= ' ' . escapeshellarg($cookiePath);
        exec($cmd, $output, $returnVar);
        
        // Parse and update equipment
        $this->updateDevices();
    }

    public function updateDevices() {
        $file = '/tmp/sfrhome_devices.json';
        if (!file_exists($file)) {
            return;
        }
        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data)) {
            return;
        }
        
        foreach ($data as $device) {
            $eqLogic = eqLogic::byLogicalId($device['id'], 'sfrhome');
            if (!is_object($eqLogic)) {
                $eqLogic = new sfrhome();
                $eqLogic->setEqType_name('sfrhome');
                $eqLogic->setLogicalId($device['id']);
                $eqLogic->setName($device['name']);
                $eqLogic->setIsEnable(1);
                $eqLogic->setIsVisible(1);
                $eqLogic->save();
                $eqLogic->createCmds($device);
            } else {
                $eqLogic->updateCmds($device);
            }
            $eqLogic->setConfiguration('deviceType', $device['deviceType']);
            $eqLogic->setConfiguration('model', $device['model_type']);
            $eqLogic->save();
        }
    }

    public function createCmds($device) {
        $deviceType = $device['deviceType'];
        $this->createInfoCmd('Etat', 'status', 'info', 'string');
        
        if ($deviceType == 'ON_OFF_PLUG' || $deviceType == 'SHUTTER_COMMAND' || $deviceType == 'SHUTTER_CONTROL') {
            $this->createActionCmd('On', 'on', 'action', 'other');
            $this->createActionCmd('Off', 'off', 'action', 'other');
            $this->createInfoCmd('Etat', 'state', 'info', 'binary');
        } elseif ($deviceType == 'ALARM_PANEL') {
            $this->createActionCmd('Désactiver', 'alarm_off', 'action', 'other');
            $this->createActionCmd('Activer', 'alarm_on', 'action', 'other');
            $this->createActionCmd('Nuit', 'alarm_custom', 'action', 'other');
            $this->createInfoCmd('Mode alarme', 'alarm_mode', 'info', 'string');
        } elseif ($deviceType == 'TEMP_HUM') {
            $this->createInfoCmd('Température', 'temperature', 'info', 'numeric', '°C');
            $this->createInfoCmd('Humidité', 'humidity', 'info', 'numeric', '%');
        } elseif ($deviceType == 'MAGNETIC' || $deviceType == 'PIR_DETECTOR' || $deviceType == 'SMOKE') {
            $this->createInfoCmd('Détecté', 'detected', 'info', 'binary');
        }
    }

    public function updateCmds($device) {
        $cmds = $this->getCmd();
        foreach ($cmds as $cmd) {
            if ($cmd->getLogicalId() == 'status') {
                $cmd->event($device['status']);
            } elseif ($cmd->getLogicalId() == 'temperature' && isset($device['sensorValues']['Temperature'])) {
                $val = str_replace('°C', '', $device['sensorValues']['Temperature']['value']);
                $cmd->event(floatval($val));
            } elseif ($cmd->getLogicalId() == 'humidity' && isset($device['sensorValues']['Humidity'])) {
                $val = str_replace('%', '', $device['sensorValues']['Humidity']['value']);
                $cmd->event(floatval($val));
            } elseif ($cmd->getLogicalId() == 'detected') {
                $detected = ($device['status'] == 'TRIGGERED' || $device['status'] == 'OPEN' || $device['status'] == 'ALARM') ? 1 : 0;
                $cmd->event($detected);
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
}

class sfrhomeCmd extends cmd {
    public function execute($_options = array()) {
        $eqLogic = $this->getEqLogic();
        $deviceId = $eqLogic->getLogicalId();
        $logicalId = $this->getLogicalId();
        
        $username = config::byKey('username', 'sfrhome');
        $password = config::byKey('password', 'sfrhome');
        $cookiePath = config::byKey('cookiePath', 'sfrhome', '/tmp/sfrhome_session_cookies.json');
        
        if ($logicalId == 'on') {
            $cmd = 'php ' . dirname(__FILE__) . '/../php/sfrhome_control.php ' . escapeshellarg($username) . ' ' . escapeshellarg($password) . ' ' . escapeshellarg($cookiePath) . ' ' . escapeshellarg($deviceId) . ' on';
            exec($cmd);
        } elseif ($logicalId == 'off') {
            $cmd = 'php ' . dirname(__FILE__) . '/../php/sfrhome_control.php ' . escapeshellarg($username) . ' ' . escapeshellarg($password) . ' ' . escapeshellarg($cookiePath) . ' ' . escapeshellarg($deviceId) . ' off';
            exec($cmd);
        } elseif ($logicalId == 'alarm_on') {
            $cmd = 'php ' . dirname(__FILE__) . '/../php/sfrhome_control.php ' . escapeshellarg($username) . ' ' . escapeshellarg($password) . ' ' . escapeshellarg($cookiePath) . ' alarm on';
            exec($cmd);
        } elseif ($logicalId == 'alarm_off') {
            $cmd = 'php ' . dirname(__FILE__) . '/../php/sfrhome_control.php ' . escapeshellarg($username) . ' ' . escapeshellarg($password) . ' ' . escapeshellarg($cookiePath) . ' alarm off';
            exec($cmd);
        } elseif ($logicalId == 'alarm_custom') {
            $cmd = 'php ' . dirname(__FILE__) . '/../php/sfrhome_control.php ' . escapeshellarg($username) . ' ' . escapeshellarg($password) . ' ' . escapeshellarg($cookiePath) . ' alarm custom';
            exec($cmd);
        }
    }
}
?>
