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

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($value) {
        return trim((string) $value);
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key($value) {
        $value = strtolower((string) $value);
        $value = preg_replace('/[^a-z0-9_\\-]/', '', $value);
        return $value ?? '';
    }
}

if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($value) {
        return trim((string) $value);
    }
}

if (!function_exists('esc_url_raw')) {
    function esc_url_raw($value) {
        return trim((string) $value);
    }
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

require_once __DIR__ . '/../includes/Settings.php';
require_once __DIR__ . '/../includes/Migrations.php';
require_once __DIR__ . '/../includes/Admin.php';
require_once __DIR__ . '/../includes/Replication.php';
require_once __DIR__ . '/../includes/Sync.php';
