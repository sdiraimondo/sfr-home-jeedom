<?php
require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';

function sfrhome_ensure_snapshot_token() {
    if (config::byKey('snapshotToken', 'sfrhome', '') !== '') {
        return;
    }
    try {
        config::save('snapshotToken', bin2hex(random_bytes(16)), 'sfrhome');
    } catch (Exception $e) {
        log::add('sfrhome', 'warning', __('Impossible de générer le jeton snapshot : ', __FILE__) . $e->getMessage());
    }
}

function sfrhome_install() {
    sfrhome_ensure_snapshot_token();
}

function sfrhome_update() {
    sfrhome_ensure_snapshot_token();
}

function sfrhome_remove() {
    
}
?>
