<?php
/**
 * Plugin Name: Gires CI/CD Tools
 * Description: Outils CI/CD (migrations globales + agent REST sécurisé).
 * Version: 0.1.1
 * Author: Gires Conseil Digital
 * Text Domain: gires-cicd-tools
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('GIRES_CICD_DIR', plugin_dir_path(__FILE__));
define('GIRES_CICD_URL', plugin_dir_url(__FILE__));
define('GIRES_CICD_VERSION', '0.1.1');

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

function gires_cicd_activate() {
    \GiresCICD\Settings::ensure_defaults();
    $settings = new \GiresCICD\Settings();
    $scripts = new \GiresCICD\Scripts($settings);
    $scripts->generate(false, true);
}

register_activation_hook(__FILE__, 'gires_cicd_activate');
