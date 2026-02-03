<?php
/**
 * Plugin Name: GH3 Hash Runs Email Gateway
 * Plugin URI: https://github.com/nonatech-uk/hash-calendar-email
 * Description: Email gateway for creating and updating hash runs via ForwardEmail.net webhook and Claude AI parsing
 * Version: 1.0.0
 * Author: Guildford Hash House Harriers
 * License: Non-Commercial Use License
 * GitHub Plugin URI: nonatech-uk/hash-calendar-email
 * Text Domain: gh3-hash-runs-email
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('GH3_EMAIL_VERSION', '1.0.0');
define('GH3_EMAIL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GH3_EMAIL_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include required files
require_once GH3_EMAIL_PLUGIN_DIR . 'includes/class-gh3-email-settings.php';
require_once GH3_EMAIL_PLUGIN_DIR . 'includes/class-gh3-email-webhook.php';
require_once GH3_EMAIL_PLUGIN_DIR . 'includes/class-gh3-email-parser.php';
require_once GH3_EMAIL_PLUGIN_DIR . 'includes/class-gh3-email-processor.php';
require_once GH3_EMAIL_PLUGIN_DIR . 'includes/class-gh3-email-updater.php';

/**
 * Check if GH3 Hash Runs plugin is active
 */
function gh3_email_check_dependency() {
    if (!is_plugin_active('gh3-hash-runs/gh3-hash-runs.php')) {
        add_action('admin_notices', 'gh3_email_dependency_notice');
        return false;
    }
    return true;
}

/**
 * Display admin notice when dependency is missing
 */
function gh3_email_dependency_notice() {
    ?>
    <div class="notice notice-error">
        <p><strong>GH3 Hash Runs Email Gateway</strong> requires the <strong>GH3 Hash Runs</strong> plugin to be installed and activated.</p>
    </div>
    <?php
}

/**
 * Initialize the plugin
 */
function gh3_email_init() {
    // Check dependency
    if (!gh3_email_check_dependency()) {
        return;
    }

    // Initialize settings
    if (is_admin()) {
        $settings = new GH3_Email_Settings();
        $settings->init();
    }

    // Initialize webhook endpoint (REST API - always needed)
    $webhook = new GH3_Email_Webhook();
    $webhook->init();

    // Initialize GitHub updater
    if (is_admin()) {
        $updater = new GH3_Email_Updater(__FILE__);
        $updater->init();
    }
}
add_action('plugins_loaded', 'gh3_email_init');

/**
 * Activation hook
 */
function gh3_email_activate() {
    // Check dependency on activation
    if (!is_plugin_active('gh3-hash-runs/gh3-hash-runs.php')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            'GH3 Hash Runs Email Gateway requires the GH3 Hash Runs plugin to be installed and activated.',
            'Plugin Activation Error',
            array('back_link' => true)
        );
    }
}
register_activation_hook(__FILE__, 'gh3_email_activate');
