<?php

$username = isset($argv[1]) ? $argv[1] : '';
$password = isset($argv[2]) ? $argv[2] : '';
$cookiePath = isset($argv[3]) ? $argv[3] : '/tmp/sfrhome_session_cookies.json';

// Use Python auth script if available, else try PHP
$pythonScript = dirname(__FILE__) . '/../../resources/sfrhome_auth.py';
if (file_exists($pythonScript)) {
    $cmd = 'python3 ' . escapeshellarg($pythonScript) . ' --user ' . escapeshellarg($username) . ' --password ' . escapeshellarg($password);
    exec($cmd, $output, $returnVar);
}

// Fetch devices
$cookieHeader = '';
if (file_exists($cookiePath)) {
    $cookies = json_decode(file_get_contents($cookiePath), true);
    if (is_array($cookies)) {
        $parts = array();
        foreach ($cookies as $c) {
            $parts[] = $c['name'] . '=' . $c['value'];
        }
        $cookieHeader = implode('; ', $parts);
    }
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://home.sfr.fr/mysensors');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Cookie: ' . $cookieHeader,
    'Referer: https://home.sfr.fr/accueil',
    'X-Requested-With: XMLHttpRequest',
    'User-Agent: Mozilla/5.0'
));
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode == 200 && $response) {
    // Save raw XML for parsing
    file_put_contents('/tmp/sfrhome_mysensors.xml', $response);
    // Parse XML
    $xml = simplexml_load_string($response);
    if ($xml) {
        $devices = array();
        // Parse sensors
        foreach ($xml->xpath('//Sensor') as $sensor) {
            $dev = array();
            $dev['id'] = (string)$sensor['id'];
            $dev['name'] = (string)$sensor->name;
            $dev['deviceType'] = (string)$sensor->deviceType;
            $dev['model_type'] = (string)$sensor->deviceModel;
            $dev['status'] = (string)$sensor->status;
            $dev['sensorValues'] = array();
            foreach ($sensor->sensorValue as $sv) {
                $name = (string)$sv['name'];
                $dev['sensorValues'][$name] = array('value' => (string)$sv);
            }
            $devices[] = $dev;
        }
        // Check for alarm panel
        if (isset($xml['model_type']) && (string)$xml['model_type'] == 'TSC06SFR') {
            $devices[] = array(
                'id' => 'ALARM_PANEL_MAIN',
                'name' => (string)$xml['name'] ?: 'Centrale',
                'deviceType' => 'ALARM_PANEL',
                'model_type' => 'TSC06SFR',
                'status' => strtoupper((string)$xml['alarm_mode'] ?: 'OFF'),
                'sensorValues' => array()
            );
        }
        file_put_contents('/tmp/sfrhome_devices.json', json_encode($devices));
    }
}
