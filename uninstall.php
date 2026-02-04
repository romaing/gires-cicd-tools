<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/includes/Settings.php';
require_once __DIR__ . '/includes/Scripts.php';

\GiresCICD\Scripts::cleanup();

// Optionnel: conserver les settings pour réinstallation
// delete_option('gires_cicd_settings');
