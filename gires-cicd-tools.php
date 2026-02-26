<?php
/**
 * Plugin Name: Gires CI/CD Tools
 * Description: Outils CI/CD (migrations globales + agent REST sécurisé).
 * Version: 0.1.3
 * Author: Gires Conseil Digital
 * Text Domain: gires-cicd-tools
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('GIRES_CICD_DIR', plugin_dir_path(__FILE__));
define('GIRES_CICD_URL', plugin_dir_url(__FILE__));
define('GIRES_CICD_VERSION', '0.1.3');

require_once GIRES_CICD_DIR . 'includes/Settings.php';
require_once GIRES_CICD_DIR . 'includes/Migrations.php';
require_once GIRES_CICD_DIR . 'includes/Replication.php';
require_once GIRES_CICD_DIR . 'includes/Sync.php';
require_once GIRES_CICD_DIR . 'includes/Admin.php';
require_once GIRES_CICD_DIR . 'includes/RestAgent.php';
require_once GIRES_CICD_DIR . 'includes/Scripts.php';

function gires_cicd_init() {
    $settings = new \GiresCICD\Settings();
    $migrations = new \GiresCICD\Migrations($settings);
    $replication = new \GiresCICD\Replication($settings);
    $admin = new \GiresCICD\Admin($settings, $migrations, $replication);
    $rest = new \GiresCICD\RestAgent($settings, $migrations, $replication);

    $admin->init();
    $rest->init();
}

add_action('plugins_loaded', 'gires_cicd_init');
add_action('plugins_loaded', function () {
    load_plugin_textdomain('gires-cicd-tools', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

add_action('init', function () {
    if (!function_exists('wp_doing_ajax') || !wp_doing_ajax()) {
        return;
    }
    $action = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';
    if (strpos($action, 'gires_cicd_') !== 0) {
        return;
    }
    @ini_set('display_errors', '0');
    $current = error_reporting();
    error_reporting($current & ~E_DEPRECATED & ~E_USER_DEPRECATED & ~E_WARNING & ~E_NOTICE);
}, 0);

function gires_cicd_activate() {
    \GiresCICD\Settings::ensure_defaults();
    $settings = new \GiresCICD\Settings();
    $scripts = new \GiresCICD\Scripts($settings);
    $scripts->generate(false, true);
}

register_activation_hook(__FILE__, 'gires_cicd_activate');
