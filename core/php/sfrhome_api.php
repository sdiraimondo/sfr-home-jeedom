<?php

$username = isset($argv[1]) ? $argv[1] : '';
$password = isset($argv[2]) ? $argv[2] : '';
$cookiePath = isset($argv[3]) ? $argv[3] : '/tmp/sfrhome_session_cookies.json';

function loadCookies($path) {
    if (!file_exists($path)) return null;
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

function saveCookies($path, $cookies) {
    file_put_contents($path, json_encode($cookies));
}

function cookiesToHeader($cookies) {
    if (!is_array($cookies)) return '';
    $parts = array();
    foreach ($cookies as $c) {
        if (isset($c['name']) && isset($c['value'])) {
            $parts[] = $c['name'] . '=' . $c['value'];
        }
    }
    return implode('; ', $parts);
}

function extractCookies($responseHeaders) {
    $cookies = array();
    if (preg_match_all('/^Set-Cookie:\s*([^;]+)/mi', $responseHeaders, $matches)) {
        foreach ($matches[1] as $cookie) {
            $parts = explode('=', $cookie, 2);
            if (count($parts) == 2) {
                $cookies[] = array('name' => trim($parts[0]), 'value' => trim($parts[1]));
            }
        }
    }
    return $cookies;
}

function mergeCookies($existing, $new) {
    $map = array();
    if (is_array($existing)) {
        foreach ($existing as $c) $map[$c['name']] = $c;
    }
    if (is_array($new)) {
        foreach ($new as $c) $map[$c['name']] = $c;
    }
    return array_values($map);
}

function doSSO($username, $password, $cookiePath) {
    $cookies = array();
    $headers = array();
    
    // Step 1: SSO
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://home.sfr.fr/sso-connector.php');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array('connectionSFR' => $username, 'passSFR' => $password)));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/x-www-form-urlencoded',
        'Origin: https://home.sfr.fr',
        'Referer: https://home.sfr.fr/login',
        'User-Agent: Mozilla/5.0'
    ));
    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $responseHeaders = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    curl_close($ch);
    
    $cookies = mergeCookies($cookies, extractCookies($responseHeaders));
    
    $token = null;
    $data = json_decode($body, true);
    if (isset($data['result']['token_sso'])) {
        $token = $data['result']['token_sso'];
    }
    if (!$token) return $cookies;
    
    // Step 2: Login page
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://home.sfr.fr/login');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Cookie: ' . cookiesToHeader($cookies),
        'Referer: https://home.sfr.fr/login',
        'User-Agent: Mozilla/5.0'
    ));
    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $responseHeaders = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    curl_close($ch);
    
    $cookies = mergeCookies($cookies, extractCookies($responseHeaders));
    
    // Step 3: Submit form (simplified)
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://home.sfr.fr/login');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array('token_sso' => $token, 'email' => $username, 'passwd' => $password)));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Cookie: ' . cookiesToHeader($cookies),
        'Referer: https://home.sfr.fr/login',
        'User-Agent: Mozilla/5.0'
    ));
    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $responseHeaders = substr($response, 0, $headerSize);
    curl_close($ch);
    
    $cookies = mergeCookies($cookies, extractCookies($responseHeaders));
    
    // Step 4: Dashboard
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://home.sfr.fr/');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Cookie: ' . cookiesToHeader($cookies),
        'Referer: https://home.sfr.fr/',
        'User-Agent: Mozilla/5.0'
    ));
    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $responseHeaders = substr($response, 0, $headerSize);
    curl_close($ch);
    
    $cookies = mergeCookies($cookies, extractCookies($responseHeaders));
    saveCookies($cookiePath, $cookies);
    
    return $cookies;
}

function fetchMysensors($cookies) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://home.sfr.fr/mysensors');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Cookie: ' . cookiesToHeader($cookies),
        'Referer: https://home.sfr.fr/accueil',
        'X-Requested-With: XMLHttpRequest',
        'User-Agent: Mozilla/5.0',
        'Accept: application/xml,text/xml,*/*'
    ));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return array('code' => $httpCode, 'body' => $response);
}

// Main
$cookies = loadCookies($cookiePath);
$result = $cookies ? fetchMysensors($cookies) : array('code' => 403, 'body' => '');

if ($result['code'] != 200) {
    if (!empty($username) && !empty($password)) {
        $cookies = doSSO($username, $password, $cookiePath);
        $result = fetchMysensors($cookies);
    }
}

if ($result['code'] == 200 && $result['body']) {
    file_put_contents('/tmp/sfrhome_mysensors.xml', $result['body']);
    $xml = @simplexml_load_string($result['body']);
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
            $dev['deviceMac'] = (string)$sensor->deviceMac;
            $dev['sensorValues'] = array();
            foreach ($sensor->sensorValue as $sv) {
                $name = (string)$sv['name'];
                $dev['sensorValues'][$name] = array('value' => (string)$sv);
            }
            $devices[] = $dev;
        }
        // Check for alarm panel
        $alarmMode = isset($xml['alarm_mode']) ? strtoupper((string)$xml['alarm_mode']) : '';
        $modelType = isset($xml['model_type']) ? (string)$xml['model_type'] : '';
        $panelName = isset($xml['name']) ? (string)$xml['name'] : 'Centrale';
        if ($modelType == 'TSC06SFR' || !empty($alarmMode)) {
            $devices[] = array(
                'id' => 'ALARM_PANEL_MAIN',
                'name' => $panelName,
                'deviceType' => 'ALARM_PANEL',
                'model_type' => $modelType ?: 'TSC06SFR',
                'status' => $alarmMode ?: 'OFF',
                'deviceMac' => '',
                'sensorValues' => array()
            );
        }
        file_put_contents('/tmp/sfrhome_devices.json', json_encode($devices, JSON_UNESCAPED_UNICODE));
        echo "OK: " . count($devices) . " devices\n";
    }
} else {
    echo "ERROR: " . $result['code'] . "\n";
}
