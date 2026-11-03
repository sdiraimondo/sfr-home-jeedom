<?php

$username = isset($argv[1]) ? $argv[1] : '';
$password = isset($argv[2]) ? $argv[2] : '';
$cookiePath = isset($argv[3]) ? $argv[3] : '/tmp/sfrhome_session_cookies.json';
$deviceId = isset($argv[4]) ? $argv[4] : '';
$action = isset($argv[5]) ? $argv[5] : '';

// Load cookies
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
if ($deviceId == 'alarm') {
    $url = 'https://home.sfr.fr/alarmmode?action=' . urlencode($action);
} else {
    $url = 'https://home.sfr.fr/plugcontrol?uid=' . urlencode($deviceId) . '&action=' . urlencode($action);
}

curl_setopt($ch, CURLOPT_URL, $url);
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
curl_close($ch);

echo $response;
