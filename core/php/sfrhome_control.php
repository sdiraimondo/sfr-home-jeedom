<?php

$username = isset($argv[1]) ? $argv[1] : '';
$password = isset($argv[2]) ? $argv[2] : '';
$cookiePath = isset($argv[3]) ? $argv[3] : '/tmp/sfrhome_session_cookies.json';
$deviceId = isset($argv[4]) ? $argv[4] : '';
$action = isset($argv[5]) ? $argv[5] : '';

function loadCookieHeader($cookiePath) {
    if (!file_exists($cookiePath)) {
        return '';
    }
    $cookies = json_decode(file_get_contents($cookiePath), true);
    if (!is_array($cookies)) {
        return '';
    }
    $parts = array();
    foreach ($cookies as $cookie) {
        if (isset($cookie['name'])) {
            $parts[] = $cookie['name'] . '=' . (isset($cookie['value']) ? $cookie['value'] : '');
        }
    }
    return implode('; ', $parts);
}

function sendSfrCommand($url, $cookieHeader) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Cookie: ' . $cookieHeader,
        'Referer: https://home.sfr.fr/accueil',
        'X-Requested-With: XMLHttpRequest',
        'User-Agent: Mozilla/5.0',
    ));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array('code' => $code, 'body' => $body);
}

if ($deviceId === 'alarm') {
    $url = 'https://home.sfr.fr/alarmmode?action=' . urlencode($action);
} else {
    $url = 'https://home.sfr.fr/plugcontrol?uid=' . urlencode($deviceId) . '&action=' . urlencode($action);
}

$cookieHeader = loadCookieHeader($cookiePath);
$result = sendSfrCommand($url, $cookieHeader);

// Session expirée : on rafraîchit les cookies via le script API puis on réessaie.
if (in_array($result['code'], array(0, 302, 303, 401, 403), true) && $username !== '' && $password !== '') {
    $cmd = 'php ' . escapeshellarg(dirname(__FILE__) . '/sfrhome_api.php');
    $cmd .= ' ' . escapeshellarg($username);
    $cmd .= ' ' . escapeshellarg($password);
    $cmd .= ' ' . escapeshellarg($cookiePath);
    $cmd .= ' 2>&1';
    exec($cmd);

    $cookieHeader = loadCookieHeader($cookiePath);
    $result = sendSfrCommand($url, $cookieHeader);
}

echo $result['body'];
