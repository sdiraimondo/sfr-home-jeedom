<?php
/**
 * Accès à l'API SFR Home pour le plugin Jeedom.
 *
 * Usage :
 *   php sfrhome_api.php <username> <password> <cookiePath> [--test]
 *
 * Sortie : JSON {"ok":bool,"count":int,"message":string}
 */

$username   = isset($argv[1]) ? $argv[1] : '';
$password   = isset($argv[2]) ? $argv[2] : '';
$cookiePath = isset($argv[3]) ? $argv[3] : '/tmp/sfrhome_session_cookies.json';
$testOnly   = in_array('--test', $argv, true);

const SFR_BASE_URL     = 'https://home.sfr.fr/';
const SFR_LOGIN_URL    = 'https://home.sfr.fr/login';
const SFR_SSO_URL      = 'https://home.sfr.fr/sso-connector.php';
const SFR_MYSENSORS_URL = 'https://home.sfr.fr/mysensors';
const USER_AGENT       = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

function output($ok, $message, $count = 0) {
    echo json_encode(array('ok' => (bool)$ok, 'message' => $message, 'count' => (int)$count), JSON_UNESCAPED_UNICODE);
    exit($ok ? 0 : 1);
}

function loadCookies($path) {
    if (!file_exists($path)) {
        return array();
    }
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : array();
}

function saveCookies($path, $cookies) {
    @file_put_contents($path, json_encode(array_values($cookies), JSON_UNESCAPED_UNICODE));
}

function cookiesToHeader($cookies) {
    $parts = array();
    foreach ($cookies as $cookie) {
        if (!empty($cookie['name'])) {
            $parts[] = $cookie['name'] . '=' . (isset($cookie['value']) ? $cookie['value'] : '');
        }
    }
    return implode('; ', $parts);
}

function extractCookies($headers) {
    $cookies = array();
    if (preg_match_all('/^Set-Cookie:\s*([^;\r\n]+)/mi', $headers, $matches)) {
        foreach ($matches[1] as $line) {
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $cookies[] = array(
                    'name'  => trim($parts[0]),
                    'value' => trim($parts[1]),
                );
            }
        }
    }
    return $cookies;
}

function mergeCookies($existing, $new) {
    $map = array();
    foreach ($existing as $cookie) {
        if (!empty($cookie['name'])) {
            $map[$cookie['name']] = $cookie;
        }
    }
    foreach ($new as $cookie) {
        if (!empty($cookie['name'])) {
            $map[$cookie['name']] = $cookie;
        }
    }
    return array_values($map);
}

function httpRequest($url, $method = 'GET', $postFields = null, $cookies = array(), $follow = true, $extraHeaders = array()) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $follow);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, USER_AGENT);

    $headerMap = array(
        'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.8',
    );
    if (!empty($cookies)) {
        $headerMap['Cookie'] = cookiesToHeader($cookies);
    }
    foreach ($extraHeaders as $header) {
        $parts = explode(':', $header, 2);
        if (count($parts) === 2) {
            $headerMap[trim($parts[0])] = trim($parts[1]);
        }
    }

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($postFields) ? http_build_query($postFields) : $postFields);
        $headerMap['Content-Type'] = 'application/x-www-form-urlencoded';
    }

    $headers = array();
    foreach ($headerMap as $name => $value) {
        $headers[] = $name . ': ' . $value;
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($errno) {
        return array('code' => 0, 'headers' => '', 'body' => '', 'error' => $error);
    }

    return array(
        'code'    => $code,
        'headers' => substr($response, 0, $headerSize),
        'body'    => substr($response, $headerSize),
        'error'   => '',
    );
}

function resolveUrl($base, $relative) {
    if (preg_match('#^https?://#i', $relative)) {
        return $relative;
    }
    $parts = parse_url($base);
    $scheme = isset($parts['scheme']) ? $parts['scheme'] : 'https';
    $host = isset($parts['host']) ? $parts['host'] : 'home.sfr.fr';
    if (substr($relative, 0, 1) === '/') {
        return $scheme . '://' . $host . $relative;
    }
    $path = isset($parts['path']) ? $parts['path'] : '/';
    $dir = substr($path, 0, strrpos($path, '/') + 1);
    return $scheme . '://' . $host . $dir . $relative;
}

/**
 * Authentification SSO SFR Home.
 * Retourne la liste des cookies ou null en cas d'échec.
 */
function authenticate($username, $password, $cookiePath) {
    $cookies = array();

    // Étape 1 : POST SSO
    $resp = httpRequest(SFR_SSO_URL, 'POST', array(
        'connectionSFR' => $username,
        'passSFR'       => $password,
    ), $cookies, true, array(
        'Origin: https://home.sfr.fr',
        'Referer: ' . SFR_LOGIN_URL,
    ));
    if ($resp['code'] === 0) {
        return array('cookies' => null, 'message' => 'Erreur réseau SSO : ' . $resp['error']);
    }
    $cookies = mergeCookies($cookies, extractCookies($resp['headers']));

    $token = null;
    $data = json_decode($resp['body'], true);
    if (isset($data['result']['token_sso'])) {
        $token = $data['result']['token_sso'];
    }
    if (!$token && isset($data['token_sso'])) {
        $token = $data['token_sso'];
    }
    if (!$token) {
        return array('cookies' => null, 'message' => 'Token SSO absent (identifiants invalides ou flux modifié)');
    }

    // Étape 2 : page de login
    $resp = httpRequest(SFR_LOGIN_URL, 'GET', null, $cookies, true);
    $cookies = mergeCookies($cookies, extractCookies($resp['headers']));
    $loginPage = $resp['body'];

    // Étape 3 : soumission du formulaire
    $payload = array();
    $actionUrl = SFR_LOGIN_URL;
    $method = 'post';

    if (class_exists('DOMDocument')) {
        $dom = new DOMDocument();
        @$dom->loadHTML($loginPage);
        $xpath = new DOMXPath($dom);
        $form = $xpath->query("//form[@id='login_form_add_rib']")->item(0);
        if (!$form) {
            $form = $xpath->query("//form[@id='loginForm']")->item(0);
        }
        if ($form) {
            $action = $form->getAttribute('action');
            if ($action !== '') {
                $actionUrl = resolveUrl(SFR_LOGIN_URL, $action);
            }
            $method = strtolower($form->getAttribute('method')) === 'get' ? 'get' : 'post';

            foreach ($xpath->query('.//input', $form) as $input) {
                $name = $input->getAttribute('name');
                if ($name === '') {
                    continue;
                }
                $type = strtolower($input->getAttribute('type'));
                if ($type === 'checkbox' && !$input->hasAttribute('checked')) {
                    continue;
                }
                $payload[$name] = $input->getAttribute('value');
            }
            $submit = $xpath->query(".//input[@type='submit']", $form)->item(0);
            if ($submit && $submit->getAttribute('name') !== '') {
                $payload[$submit->getAttribute('name')] = $submit->getAttribute('value');
            }
        }
    }

    if (empty($payload)) {
        return array('cookies' => null, 'message' => 'Formulaire de login introuvable');
    }

    $payload['token_sso'] = $token;
    if (isset($payload['email']) && $payload['email'] === '') {
        $payload['email'] = $username;
    }
    if (isset($payload['passwd']) && $payload['passwd'] === '') {
        $payload['passwd'] = $password;
    }
    // Certaines versions utilisent connectionSFR/passSFR
    if (isset($payload['connectionSFR']) && $payload['connectionSFR'] === '') {
        $payload['connectionSFR'] = $username;
    }
    if (isset($payload['passSFR']) && $payload['passSFR'] === '') {
        $payload['passSFR'] = $password;
    }

    if ($method === 'get') {
        $url = $actionUrl . (strpos($actionUrl, '?') === false ? '?' : '&') . http_build_query($payload);
        $resp = httpRequest($url, 'GET', null, $cookies, true);
    } else {
        $actionParts = parse_url($actionUrl);
        $origin = (isset($actionParts['scheme']) ? $actionParts['scheme'] : 'https') . '://' . (isset($actionParts['host']) ? $actionParts['host'] : 'home.sfr.fr');
        $resp = httpRequest($actionUrl, 'POST', $payload, $cookies, true, array(
            'Origin: ' . $origin,
            'Referer: ' . SFR_LOGIN_URL,
        ));
    }
    $cookies = mergeCookies($cookies, extractCookies($resp['headers']));

    // Étape 4 : dashboard
    $resp = httpRequest(SFR_BASE_URL, 'GET', null, $cookies, true);
    $cookies = mergeCookies($cookies, extractCookies($resp['headers']));

    saveCookies($cookiePath, $cookies);
    return array('cookies' => $cookies, 'message' => 'Authentification SSO réussie');
}

function fetchMysensors($cookies) {
    return httpRequest(SFR_MYSENSORS_URL, 'GET', null, $cookies, false, array(
        'Referer: https://home.sfr.fr/accueil',
        'X-Requested-With: XMLHttpRequest',
        'Accept: application/xml,text/xml,*/*',
    ));
}

function looksLikeXml($body) {
    if ($body === '') {
        return false;
    }
    $head = ltrim($body, "\xEF\xBB\xBF \t\r\n");
    if (stripos($head, '<html') === 0 || stripos($head, '<!doctype html') === 0) {
        return false;
    }
    return strpos($head, '<') === 0;
}

function parseDevices($xmlString) {
    $devices = array();
    if (!function_exists('simplexml_load_string')) {
        return array('devices' => $devices, 'message' => 'Extension PHP SimpleXML manquante', 'valid' => false);
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlString);
    if (!$xml) {
        libxml_clear_errors();
        return array('devices' => $devices, 'message' => 'XML mysensors invalide', 'valid' => false);
    }

    $attr = function ($node, $key) {
        $attrs = $node->attributes();
        return isset($attrs[$key]) ? trim((string)$attrs[$key]) : '';
    };
    $childText = function ($node, $key) {
        return isset($node->$key) ? trim((string)$node->$key) : '';
    };
    $nodeValue = function ($node, $keys) use ($attr, $childText) {
        foreach ($keys as $key) {
            $value = $attr($node, $key);
            if ($value !== '') {
                return $value;
            }
            $value = $childText($node, $key);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    };

    $found = array();
    $alarmInfo = null;
    $sensorTags = array('sensorvalue', 'sensorvalues', 'sv');

    $visit = function ($node) use (&$visit, &$found, &$alarmInfo, $nodeValue, $attr, $childText, $sensorTags) {
        if (!($node instanceof SimpleXMLElement)) {
            return;
        }

        $tag = strtolower($node->getName());
        $id = $nodeValue($node, array('id', 'uid', 'rrd_id'));
        $deviceType = $nodeValue($node, array('deviceType', 'devicetype', 'model_type', 'modelType'));
        $modelType = $nodeValue($node, array('model_type', 'modelType', 'model', 'deviceModel'));
        $name = $nodeValue($node, array('name', 'deviceName', 'label', 'long_name'));
        $status = $nodeValue($node, array('status', 'deviceStatus'));
        $deviceMac = $nodeValue($node, array('deviceMac', 'mac'));

        // Détection de la centrale d'alarme (attributs ou enfants de la racine).
        $alarmMode = $nodeValue($node, array('alarm_mode', 'alarmMode'));
        if ($alarmInfo === null && ($modelType === 'TSC06SFR' || $alarmMode !== '' || strtoupper($deviceType) === 'ALARM_PANEL')) {
            $alarmInfo = array(
                'model' => $modelType !== '' ? $modelType : 'TSC06SFR',
                'mode'  => $alarmMode !== '' ? strtoupper($alarmMode) : 'OFF',
                'name'  => $name !== '' ? $name : 'Centrale',
            );
        }

        $hasSensorValue = false;
        foreach ($node->children() as $child) {
            if (in_array(strtolower($child->getName()), $sensorTags, true)) {
                $hasSensorValue = true;
                break;
            }
        }

        $blockedTags = array('sensorvalue', 'sensorvalues', 'sv', 'name', 'status', 'value', 'mysensors', 'root', 'sensors', 'devices', 'homescope');
        $isDevice = $id !== ''
            && !in_array($tag, $blockedTags, true)
            && ($deviceType !== '' || $status !== '' || $hasSensorValue);

        if ($isDevice) {
            $sensorValues = array();
            $collect = function ($parent) use (&$collect, &$sensorValues, $attr, $childText, $sensorTags) {
                foreach ($parent->children() as $child) {
                    $childTag = strtolower($child->getName());
                    if (!in_array($childTag, array('sensorvalue', 'sv'), true)) {
                        continue;
                    }
                    $svName = $attr($child, 'name');
                    if ($svName === '') {
                        $svName = $childText($child, 'name');
                    }
                    if ($svName === '') {
                        $svName = $attr($child, 'id');
                    }
                    if ($svName === '') {
                        $svName = 'value_' . count($sensorValues);
                    }
                    $value = $attr($child, 'value');
                    if ($value === '') {
                        $value = trim((string)$child);
                    }
                    if ($value === '') {
                        $value = $childText($child, 'value');
                    }
                    $sensorValues[$svName] = array('value' => $value);
                }
            };
            $collect($node);
            foreach ($node->children() as $child) {
                if (strtolower($child->getName()) === 'sensorvalues') {
                    $collect($child);
                }
            }

            $found[] = array(
                'id'           => $id,
                'name'         => $name !== '' ? $name : 'SFR ' . $id,
                'deviceType'   => strtoupper($deviceType),
                'model_type'   => $modelType,
                'status'       => $status,
                'deviceMac'    => $deviceMac,
                'sensorValues' => $sensorValues,
            );
            return; // Ne pas interpréter les enfants d'un périphérique comme des périphériques.
        }

        foreach ($node->children() as $child) {
            $visit($child);
        }
    };
    $visit($xml);

    // Déduplication par identifiant, fusion des champs complémentaires.
    $byId = array();
    foreach ($found as $device) {
        if (!isset($byId[$device['id']])) {
            $byId[$device['id']] = $device;
            continue;
        }
        foreach (array('name', 'deviceType', 'model_type', 'status', 'deviceMac') as $key) {
            if ($byId[$device['id']][$key] === '' && $device[$key] !== '') {
                $byId[$device['id']][$key] = $device[$key];
            }
        }
        $byId[$device['id']]['sensorValues'] = array_merge(
            $byId[$device['id']]['sensorValues'],
            $device['sensorValues']
        );
    }
    $devices = array_values($byId);

    // Ajout de la centrale si elle n'est pas déjà présente.
    $hasAlarmPanel = false;
    foreach ($devices as $device) {
        if (strtoupper($device['deviceType']) === 'ALARM_PANEL') {
            $hasAlarmPanel = true;
            break;
        }
    }
    if (!$hasAlarmPanel && $alarmInfo !== null) {
        $devices[] = array(
            'id'           => 'ALARM_PANEL_MAIN',
            'name'         => $alarmInfo['name'],
            'deviceType'   => 'ALARM_PANEL',
            'model_type'   => $alarmInfo['model'],
            'status'       => $alarmInfo['mode'],
            'deviceMac'    => '',
            'sensorValues' => array('AlarmMode' => array('value' => $alarmInfo['mode'])),
        );
    }

    return array('devices' => $devices, 'message' => count($devices) . ' périphérique(s) récupéré(s)', 'valid' => true);
}

// ---------------------------------------------------------------------------
// Programme principal
// ---------------------------------------------------------------------------

if ($username === '' || $password === '') {
    output(false, 'Identifiant ou mot de passe manquant');
}

$cookies = loadCookies($cookiePath);
$response = null;

// Essai avec les cookies existants (sauf en mode test : on doit valider les identifiants fournis).
if (!$testOnly && !empty($cookies)) {
    $response = fetchMysensors($cookies);
    $body = $response['code'] === 200 ? trim($response['body']) : '';
    if ($response['code'] !== 200 || !looksLikeXml($body)) {
        $cookies = array();
        $response = null;
    }
}

// Réauthentification si nécessaire
if ($response === null) {
    $auth = authenticate($username, $password, $cookiePath);
    if ($auth['cookies'] === null) {
        output(false, $auth['message']);
    }
    $cookies = $auth['cookies'];
    $response = fetchMysensors($cookies);
}

if ($response['code'] === 0) {
    output(false, 'Erreur réseau : ' . $response['error']);
}
if ($response['code'] !== 200) {
    output(false, 'Accès refusé à /mysensors (HTTP ' . $response['code'] . ')');
}

$body = trim($response['body']);
if ($body === '') {
    output(false, 'Réponse vide de /mysensors');
}
if (!looksLikeXml($body)) {
    output(false, 'Réponse inattendue de /mysensors (authentification probablement invalide)');
}

file_put_contents('/tmp/sfrhome_mysensors.xml', $body);

$parsed = parseDevices($body);
if (empty($parsed['valid'])) {
    output(false, $parsed['message']);
}

file_put_contents('/tmp/sfrhome_devices.json', json_encode($parsed['devices'], JSON_UNESCAPED_UNICODE));

saveCookies($cookiePath, $cookies);

if (empty($parsed['devices'])) {
    output(true, 'Connexion réussie mais aucun périphérique trouvé', 0);
}

output(true, $parsed['message'], count($parsed['devices']));
