<?php

try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    ajax::init();

    if (init('action') == 'sync') {
        $result = sfrhome::syncDevices();
        if (empty($result['ok'])) {
            ajax::error($result['message']);
        }
        ajax::success($result);
    }

    if (init('action') == 'test') {
        ajax::success(sfrhome::testConnection(
            init('username'),
            init('password'),
            init('cookiePath')
        ));
    }

    throw new Exception(__('Aucune méthode correspondante à', __FILE__) . ' : ' . init('action'));
} catch (Exception $e) {
    ajax::error(displayException($e), $e->getCode());
}
