<?php

require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

$pid_file = jeedom::getTmpFolder('sfrhome') . '/sfrhome.pid';
file_put_contents($pid_file, getmypid());

log::add('sfrhome', 'info', 'Démon SFR Home démarré');

while (true) {
    $eqLogics = eqLogic::byType('sfrhome');
    $refreshInterval = config::byKey('refreshInterval', 'sfrhome', 60);
    
    foreach ($eqLogics as $eqLogic) {
        try {
            $eqLogic->refresh();
        } catch (Exception $e) {
            log::add('sfrhome', 'error', $e->getMessage());
        }
    }
    
    sleep($refreshInterval);
}
