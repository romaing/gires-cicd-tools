<?php

if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/gires_cicd_wp/');
}

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

$GLOBALS['gires_cicd_options'] = [];

function get_option($key, $default = false) {
    return $GLOBALS['gires_cicd_options'][$key] ?? $default;
}

function update_option($key, $value) {
    $GLOBALS['gires_cicd_options'][$key] = $value;
    return true;
}

function wp_upload_dir() {
    $base = sys_get_temp_dir() . '/gires_cicd_uploads';
    if (!is_dir($base)) {
        mkdir($base, 0777, true);
    }
    return ['basedir' => $base];
}

function wp_mkdir_p($dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    return true;
}

require_once __DIR__ . '/../includes/Settings.php';
require_once __DIR__ . '/../includes/Replication.php';
require_once __DIR__ . '/../includes/Sync.php';
