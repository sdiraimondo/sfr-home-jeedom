<?php
/**
 * Accès à l'API SFR Home pour le plugin Jeedom.
 *
 * Usage :
 *   php sfrhome_api.php <username> <password> <cookiePath> [--test]
 *   php sfrhome_api.php <username> <password> <cookiePath> --snapshot <mac>
 *
 * Sortie : JSON {"ok":bool,"count":int,"message":string}
 * En mode --snapshot, la sortie est l'image binaire (ou une erreur sur STDERR).
 */

$username   = isset($argv[1]) ? $argv[1] : '';
$password   = isset($argv[2]) ? $argv[2] : '';
$cookiePath = isset($argv[3]) ? $argv[3] : '/tmp/sfrhome_session_cookies.json';
$testOnly   = in_array('--test', $argv, true);

// Mode utilisé par le proxy de snapshot : php sfrhome_api.php ... --snapshot <mac>
$snapshotMac = '';
$snapshotIndex = array_search('--snapshot', $argv, true);
if ($snapshotIndex !== false && isset($argv[$snapshotIndex + 1])) {
    $snapshotMac = trim($argv[$snapshotIndex + 1]);
}

const SFR_BASE_URL     = 'https://home.sfr.fr/';
const SFR_LOGIN_URL    = 'https://home.sfr.fr/login';
const SFR_MYSENSORS_URL = 'https://home.sfr.fr/mysensors';
const SFR_MYCAMS_URL   = 'https://home.sfr.fr/homescope/mycams';
const SFR_SNAPSHOT_URL = 'https://home.sfr.fr/homescope/snapshot';
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
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    $origin = $scheme . '://' . $host . $port;
    if (substr($relative, 0, 1) === '/') {
        return $origin . $relative;
    }
    $path = isset($parts['path']) ? $parts['path'] : '/';
    $dir = substr($path, 0, strrpos($path, '/') + 1);
    return $origin . $dir . $relative;
}

function getRedirectLocation($headers) {
    if (preg_match('/^Location:\s*(.+?)\s*$/mi', $headers, $matches)) {
        return trim($matches[1]);
    }
    return '';
}

/**
 * Suit les redirections en mettant à jour les cookies à chaque étape
 * (curl ne gère pas de jar de cookies entre les redirections).
 */
function followRedirects(&$cookies, $url, $max = 5) {
    $resp = null;
    for ($i = 0; $i < $max; $i++) {
        $resp = httpRequest($url, 'GET', null, $cookies, false);
        $cookies = mergeCookies($cookies, extractCookies($resp['headers']));
        if ($resp['code'] >= 300 && $resp['code'] < 400) {
            $location = getRedirectLocation($resp['headers']);
            if ($location === '') {
                break;
            }
            $url = resolveUrl($url, $location);
            continue;
        }
        break;
    }
    return $resp;
}

/**
 * Authentification SFR Home (formulaire /login avec jeton CSRF).
 * Retourne la liste des cookies ou null en cas d'échec.
 */
function authenticate($username, $password, $cookiePath) {
    $cookies = array();

    // Étape 1 : page de connexion (session + jeton CSRF)
    $resp = httpRequest(SFR_LOGIN_URL, 'GET', null, $cookies, true);
    if ($resp['code'] === 0) {
        return array('cookies' => null, 'message' => 'Erreur réseau lors du chargement de la page de connexion : ' . $resp['error']);
    }
    if ($resp['code'] !== 200) {
        return array('cookies' => null, 'message' => 'Page de connexion inaccessible (HTTP ' . $resp['code'] . ')');
    }
    $cookies = mergeCookies($cookies, extractCookies($resp['headers']));
    $loginPage = $resp['body'];

    // Étape 2 : extraction du formulaire de connexion
    $payload = array();
    $actionUrl = SFR_LOGIN_URL;
    $method = 'post';

    if (class_exists('DOMDocument')) {
        $dom = new DOMDocument();
        @$dom->loadHTML($loginPage);
        $xpath = new DOMXPath($dom);
        $form = $xpath->query("//form[@id='loginForm']")->item(0);
        if (!$form) {
            $form = $xpath->query("//form[@id='login_form_add_rib']")->item(0);
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

    // Repli sans DOMDocument : on récupère au minimum le jeton CSRF.
    if (empty($payload) && preg_match('/name=["\']csrf_login["\'][^>]*value=["\']([^"\']*)["\']/i', $loginPage, $matches)) {
        $payload['csrf_login'] = $matches[1];
    }

    if (empty($payload)) {
        return array('cookies' => null, 'message' => 'Formulaire de login introuvable');
    }

    // Les champs sont vides dans la page : on renseigne les identifiants.
    $payload['email'] = $username;
    $payload['passwd'] = $password;
    // Compatibilité avec d'anciens formulaires.
    if (array_key_exists('connectionSFR', $payload)) {
        $payload['connectionSFR'] = $username;
    }
    if (array_key_exists('passSFR', $payload)) {
        $payload['passSFR'] = $password;
    }
    unset($payload['token_sso']);

    // Étape 3 : soumission du formulaire
    if ($method === 'get') {
        $url = $actionUrl . (strpos($actionUrl, '?') === false ? '?' : '&') . http_build_query($payload);
        $resp = httpRequest($url, 'GET', null, $cookies, false);
    } else {
        $actionParts = parse_url($actionUrl);
        $origin = (isset($actionParts['scheme']) ? $actionParts['scheme'] : 'https') . '://' . (isset($actionParts['host']) ? $actionParts['host'] : 'home.sfr.fr');
        $resp = httpRequest($actionUrl, 'POST', $payload, $cookies, false, array(
            'Origin: ' . $origin,
            'Referer: ' . SFR_LOGIN_URL,
        ));
    }

    if ($resp['code'] === 0) {
        return array('cookies' => null, 'message' => 'Erreur réseau lors de la connexion : ' . $resp['error']);
    }
    $cookies = mergeCookies($cookies, extractCookies($resp['headers']));

    // Échec : SFR réaffiche le formulaire avec un message d'erreur.
    if ($resp['code'] === 200 && (stripos($resp['body'], 'id="loginForm"') !== false || stripos($resp['body'], "id='loginForm'") !== false)) {
        $message = 'Identifiants SFR refusés.';
        if (preg_match('/id=["\']errorMessage["\'][^>]*>(.*?)<\/p>/is', $resp['body'], $matches)) {
            $error = trim(html_entity_decode(strip_tags($matches[1]), ENT_QUOTES, 'UTF-8'));
            if ($error !== '') {
                $message = $error;
            }
        }
        return array('cookies' => null, 'message' => $message);
    }

    if (!in_array($resp['code'], array(200, 301, 302, 303, 307, 308), true)) {
        return array('cookies' => null, 'message' => 'Réponse inattendue de la page de connexion (HTTP ' . $resp['code'] . ')');
    }

    // Étape 4 : finalisation de la session (suivi des redirections avec cookies à jour)
    $redirect = getRedirectLocation($resp['headers']);
    if ($redirect !== '') {
        $target = resolveUrl($actionUrl, $redirect);
        followRedirects($cookies, $target);
    }
    $dashboard = followRedirects($cookies, SFR_BASE_URL);
    if ($dashboard === null || $dashboard['code'] === 0) {
        return array('cookies' => null, 'message' => 'Erreur réseau lors de l\'ouverture de la session');
    }
    if (stripos($dashboard['body'], 'id="loginForm"') !== false || stripos($dashboard['body'], "id='loginForm'") !== false) {
        return array('cookies' => null, 'message' => 'Identifiants SFR refusés (session non établie).');
    }

    saveCookies($cookiePath, $cookies);
    return array('cookies' => $cookies, 'message' => 'Authentification réussie');
}

function fetchMysensors($cookies) {
    return httpRequest(SFR_MYSENSORS_URL, 'GET', null, $cookies, false, array(
        'Referer: https://home.sfr.fr/accueil',
        'X-Requested-With: XMLHttpRequest',
        'Accept: application/xml,text/xml,*/*',
    ));
}

/**
 * Liste des caméras SFR (endpoint distinct de /mysensors).
 * Réponse XML : <Cameras><status>OK</status><Camera id="...">...</Camera></Cameras>
 */
function fetchCameras($cookies) {
    return httpRequest(SFR_MYCAMS_URL, 'GET', null, $cookies, false, array(
        'Referer: https://home.sfr.fr/accueil',
        'X-Requested-With: XMLHttpRequest',
        'Accept: application/xml,text/xml,*/*',
    ));
}

/**
 * Snapshot d'une caméra (image binaire).
 */
function fetchSnapshot($cookies, $mac) {
    $url = SFR_SNAPSHOT_URL . '?mac=' . rawurlencode(strtolower(trim((string)$mac))) . '&size=8';
    return httpRequest($url, 'GET', null, $cookies, false, array(
        'Referer: https://home.sfr.fr/accueil',
        'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
    ));
}

/**
 * Normalise un MAC pour les comparaisons (sans séparateurs, en minuscules).
 */
function normalizeMac($mac) {
    return strtolower(preg_replace('/[^0-9a-f]/i', '', (string)$mac));
}

function looksLikeImage($body) {
    if ($body === '') {
        return false;
    }
    return strncmp($body, "\xFF\xD8\xFF", 3) === 0       // JPEG
        || strncmp($body, "\x89PNG\r\n\x1A\n", 8) === 0  // PNG
        || strncmp($body, 'GIF8', 4) === 0;              // GIF
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
            // État ON/OFF du capteur : <automation on_off="0|1">.
            // C'est la source fiable pour un contact magnétique, un détecteur
            // de mouvement ou un détecteur de fumée (status ne décrit que la santé).
            $automation = array();
            if (isset($node->automation)) {
                foreach ($node->automation->attributes() as $key => $value) {
                    $automation[strtolower((string)$key)] = trim((string)$value);
                }
            }

            $sensorValues = array();
            $collect = function ($parent) use (&$collect, &$sensorValues, $attr, $childText) {
                foreach ($parent->children() as $child) {
                    $childTag = strtolower($child->getName());
                    if (!in_array($childTag, array('sensorvalue', 'sensorvalues', 'sv'), true)) {
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
                        // Balise conteneur sans attribut name : explorer ses enfants.
                        $collect($child);
                        continue;
                    }
                    $value = trim((string)$child);
                    if ($value === '') {
                        $value = $attr($child, 'value');
                    }
                    if ($value === '') {
                        $value = $childText($child, 'value');
                    }
                    $sensorValues[$svName] = array('value' => $value);
                }
            };
            $collect($node);

            $found[] = array(
                'id'           => $id,
                'name'         => $name !== '' ? $name : 'SFR ' . $id,
                'deviceType'   => strtoupper($deviceType),
                'model_type'   => $modelType,
                'status'       => $status,
                'automation'   => $automation,
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
        if (!empty($device['automation']) && is_array($device['automation'])) {
            foreach ($device['automation'] as $key => $value) {
                if (!isset($byId[$device['id']]['automation'][$key]) || $byId[$device['id']]['automation'][$key] === '') {
                    $byId[$device['id']]['automation'][$key] = $value;
                }
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
            'automation'   => array(),
            'deviceMac'    => '',
            'sensorValues' => array('AlarmMode' => array('value' => $alarmInfo['mode'])),
        );
    }

    return array('devices' => $devices, 'message' => count($devices) . ' périphérique(s) récupéré(s)', 'valid' => true);
}

/**
 * Analyse la réponse XML de /homescope/mycams.
 *
 * Structure confirmée dans l'application Android (SimpleXML) :
 *   <Cameras>
 *     <status>OK</status>
 *     <error></error>
 *     <Camera id="12">
 *       <title>Jardin</title><model>...</model><mac>...</mac>
 *       <privacyButton>0</privacyButton><reachable>1</reachable>
 *       <rec24>1</rec24><detection_profile>2</detection_profile>
 *       <flip>0</flip><ledMode>0</ledMode><sensitivity>3</sensitivity>
 *     </Camera>
 *   </Cameras>
 */
function parseCameras($xmlString) {
    $cameras = array();
    if (!function_exists('simplexml_load_string')) {
        return array('cameras' => $cameras, 'message' => 'Extension PHP SimpleXML manquante', 'valid' => false);
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlString);
    if (!$xml) {
        libxml_clear_errors();
        return array('cameras' => $cameras, 'message' => 'XML mycams invalide', 'valid' => false);
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
    $intValue = function ($node, $keys) use ($nodeValue) {
        $value = $nodeValue($node, $keys);
        return $value === '' ? null : intval($value);
    };

    $status = $nodeValue($xml, array('status', 'STATUS'));
    $error = $nodeValue($xml, array('error', 'ERROR'));
    if ($status !== '' && strtoupper($status) !== 'OK') {
        return array(
            'cameras' => array(),
            'message' => $error !== '' ? $error : ('Statut caméras : ' . $status),
            'valid'   => false,
        );
    }

    $parseCamera = function ($node) use ($nodeValue, $intValue) {
        $id = $nodeValue($node, array('id', 'cameraId'));
        $mac = $nodeValue($node, array('mac', 'macAddress', 'deviceMac'));
        if ($id === '' && $mac === '') {
            return null;
        }

        $title = $nodeValue($node, array('title', 'name', 'cameraName'));
        $model = $nodeValue($node, array('model', 'model_type', 'modelType'));
        $reachable = $intValue($node, array('reachable', 'online'));
        $privacy = $intValue($node, array('privacyButton', 'privacy', 'privacyMode'));
        $rec24 = $intValue($node, array('rec24'));
        $detection = $intValue($node, array('detection_profile', 'detectionProfile'));
        $flip = $intValue($node, array('flip'));
        $ledMode = $intValue($node, array('ledMode', 'led_mode'));
        $sensitivity = $intValue($node, array('sensitivity'));

        $macUrl = strtolower(trim((string)$mac));
        $slug = $id !== '' ? $id : normalizeMac($mac);
        $slug = preg_replace('/[^0-9a-zA-Z_-]/', '', $slug);
        if ($slug === '') {
            $slug = substr(md5($title . $macUrl), 0, 8);
        }

        return array(
            'id'           => 'CAMERA_' . $slug,
            'name'         => $title !== '' ? $title : ('Caméra ' . $slug),
            'deviceType'   => 'CAMERA',
            'model_type'   => $model,
            'status'       => ($reachable === 1) ? 'ON' : (($reachable === 0) ? 'OFF' : ''),
            'deviceMac'    => $macUrl,
            'sensorValues' => array(),
            'camera'       => array(
                'cameraId'         => $id,
                'snapshotUrl'      => SFR_SNAPSHOT_URL . '?mac=' . rawurlencode($macUrl) . '&size=8',
                'streamUrl'        => SFR_BASE_URL . 'homescope/flv?mac=' . rawurlencode($macUrl) . '&audio=1',
                'reachable'        => $reachable === 1 ? 1 : 0,
                'privacy'          => $privacy === 1 ? 1 : 0,
                'rec24'            => $rec24 === 1 ? 1 : 0,
                'detectionProfile' => $detection === null ? 0 : $detection,
                'flip'             => $flip === 1 ? 1 : 0,
                'ledMode'          => $ledMode === null ? 0 : $ledMode,
                'sensitivity'      => $sensitivity === null ? 0 : $sensitivity,
            ),
        );
    };

    $visit = function ($node) use (&$visit, &$cameras, $parseCamera) {
        foreach ($node->children() as $child) {
            if (strtolower($child->getName()) === 'camera') {
                $camera = $parseCamera($child);
                if ($camera !== null) {
                    $cameras[] = $camera;
                }
            } else {
                $visit($child);
            }
        }
    };
    $visit($xml);

    return array(
        'cameras' => $cameras,
        'message' => count($cameras) . ' caméra(s) récupérée(s)',
        'valid'   => true,
    );
}

/**
 * Fusionne les caméras avec les périphériques /mysensors.
 * Si un capteur CAMERA_WIFI/PIR_CAMERA possède le même MAC, on l'enrichit
 * au lieu de créer un doublon. Sinon la caméra devient un équipement à part.
 */
function mergeCameras($devices, $cameras) {
    $cameraIndexByMac = array();
    $existingIds = array();

    foreach ($devices as $index => $device) {
        if (!empty($device['id'])) {
            $existingIds[$device['id']] = true;
        }
        $type = strtoupper(isset($device['deviceType']) ? $device['deviceType'] : '');
        $mac = normalizeMac(isset($device['deviceMac']) ? $device['deviceMac'] : '');
        if ($mac !== '' && strpos($type, 'CAMERA') !== false) {
            $cameraIndexByMac[$mac] = $index;
        }
    }

    foreach ($cameras as $camera) {
        $mac = normalizeMac(isset($camera['deviceMac']) ? $camera['deviceMac'] : '');
        if ($mac !== '' && isset($cameraIndexByMac[$mac])) {
            $index = $cameraIndexByMac[$mac];
            $devices[$index]['camera'] = $camera['camera'];
            if (empty($devices[$index]['deviceMac'])) {
                $devices[$index]['deviceMac'] = $camera['deviceMac'];
            }
            continue;
        }

        $id = $camera['id'];
        if (isset($existingIds[$id])) {
            $suffix = $mac !== '' ? $mac : substr(md5($camera['name']), 0, 6);
            $id .= '_' . $suffix;
        }
        $camera['id'] = $id;
        $devices[] = $camera;
        $existingIds[$id] = true;
    }

    return $devices;
}

// ---------------------------------------------------------------------------
// Programme principal
// ---------------------------------------------------------------------------

// Permet d'inclure ce fichier dans les tests sans exécuter la synchronisation.
if (defined('SFRHOME_API_NO_RUN')) {
    return;
}

if ($username === '' || $password === '') {
    output(false, 'Identifiant ou mot de passe manquant');
}

$cookies = loadCookies($cookiePath);

// ---------------------------------------------------------------------------
// Mode snapshot : utilisé par le proxy PHP du plugin pour afficher l'image
// dans Jeedom (le navigateur n'a pas les cookies SFR).
// ---------------------------------------------------------------------------
if ($snapshotMac !== '') {
    $response = fetchSnapshot($cookies, $snapshotMac);

    $needsAuth = $response['code'] === 0
        || in_array($response['code'], array(401, 403), true)
        || ($response['code'] === 200 && !looksLikeImage($response['body']));

    if ($needsAuth) {
        $auth = authenticate($username, $password, $cookiePath);
        if ($auth['cookies'] === null) {
            fwrite(STDERR, $auth['message'] . "\n");
            exit(1);
        }
        $cookies = $auth['cookies'];
        $response = fetchSnapshot($cookies, $snapshotMac);
    }

    if ($response['code'] === 200 && looksLikeImage($response['body'])) {
        echo $response['body'];
        exit(0);
    }

    fwrite(STDERR, 'Snapshot indisponible (HTTP ' . $response['code'] . ")\n");
    exit(1);
}

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

// ---------------------------------------------------------------------------
// Caméras : endpoint séparé /homescope/mycams.
// Un échec côté caméras ne doit pas faire échouer la synchronisation des capteurs.
// ---------------------------------------------------------------------------
$cameraParsed = array('cameras' => array(), 'message' => '', 'valid' => false);
$cameraMessage = '';
$cameraResponse = fetchCameras($cookies);

$cameraNeedsAuth = $cameraResponse['code'] === 0
    || in_array($cameraResponse['code'], array(401, 403), true)
    || ($cameraResponse['code'] === 200 && !looksLikeXml(trim($cameraResponse['body'])));

if ($cameraNeedsAuth) {
    $auth = authenticate($username, $password, $cookiePath);
    if ($auth['cookies'] !== null) {
        $cookies = $auth['cookies'];
        $cameraResponse = fetchCameras($cookies);
    }
}

if ($cameraResponse['code'] === 200) {
    $cameraBody = trim($cameraResponse['body']);
    if ($cameraBody !== '' && looksLikeXml($cameraBody)) {
        file_put_contents('/tmp/sfrhome_mycams.xml', $cameraBody);
        $cameraParsed = parseCameras($cameraBody);
        if ($cameraParsed['valid']) {
            $parsed['devices'] = mergeCameras($parsed['devices'], $cameraParsed['cameras']);
        } else {
            $cameraMessage = $cameraParsed['message'];
        }
    } else {
        $cameraMessage = 'Réponse inattendue de /homescope/mycams';
    }
} else {
    $cameraMessage = 'Caméras indisponibles (HTTP ' . $cameraResponse['code'] . ')';
}

file_put_contents('/tmp/sfrhome_devices.json', json_encode($parsed['devices'], JSON_UNESCAPED_UNICODE));

saveCookies($cookiePath, $cookies);

$messages = array(count($parsed['devices']) . ' périphérique(s) récupéré(s)');
if ($cameraParsed['valid']) {
    $messages[] = $cameraParsed['message'];
} elseif ($cameraMessage !== '') {
    $messages[] = $cameraMessage;
}
$message = implode(' ; ', $messages);

if (empty($parsed['devices'])) {
    output(true, 'Connexion réussie mais aucun périphérique trouvé', 0);
}

output(true, $message, count($parsed['devices']));
