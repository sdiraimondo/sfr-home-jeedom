<?php
/**
 * Proxy de snapshot caméra SFR Home.
 *
 * Les cookies SFR restent côté serveur : le navigateur (ou un scénario)
 * appelle cette URL et reçoit directement l'image JPEG.
 *
 * Accès autorisé :
 *   - utilisateur Jeedom connecté ;
 *   - ou jeton `snapshotToken` généré par le plugin.
 */

require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');

function sfrhome_snapshot_fail($code, $message) {
    http_response_code($code);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo $message;
    exit;
}

$eqLogicId = intval(init('eqLogic_id'));
$eqLogic = eqLogic::byId($eqLogicId);
if (!is_object($eqLogic) || $eqLogic->getEqType_name() !== 'sfrhome') {
    sfrhome_snapshot_fail(404, 'Équipement SFR Home introuvable');
}

$expectedToken = config::byKey('snapshotToken', 'sfrhome', '');
$providedToken = trim(init('token'));
$authorized = isConnect('user');
if (!$authorized && $expectedToken !== '' && $providedToken !== '') {
    $authorized = hash_equals($expectedToken, $providedToken);
}
if (!$authorized) {
    sfrhome_snapshot_fail(403, 'Accès non autorisé');
}

$mac = trim(init('mac'));
if ($mac === '') {
    $mac = trim($eqLogic->getConfiguration('cameraMac', $eqLogic->getConfiguration('deviceMac', '')));
}
if ($mac === '') {
    sfrhome_snapshot_fail(404, 'MAC caméra inconnu');
}

$username = config::byKey('username', 'sfrhome', '');
$password = config::byKey('password', 'sfrhome', '');
$cookiePath = config::byKey('cookiePath', 'sfrhome', '/tmp/sfrhome_session_cookies.json');

$cmd = 'php ' . escapeshellarg(dirname(__FILE__) . '/../php/sfrhome_api.php');
$cmd .= ' ' . escapeshellarg($username);
$cmd .= ' ' . escapeshellarg($password);
$cmd .= ' ' . escapeshellarg($cookiePath);
$cmd .= ' --snapshot ' . escapeshellarg($mac);
$cmd .= ' 2>/dev/null';

$body = shell_exec($cmd);
if (!is_string($body) || $body === '') {
    sfrhome_snapshot_fail(502, 'Snapshot SFR indisponible');
}

$image = @getimagesizefromstring($body);
if (!is_array($image) || empty($image['mime']) || strpos($image['mime'], 'image/') !== 0) {
    sfrhome_snapshot_fail(502, 'Réponse SFR invalide pour le snapshot');
}

header('Content-Type: ' . $image['mime']);
header('Content-Length: ' . strlen($body));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
echo $body;
