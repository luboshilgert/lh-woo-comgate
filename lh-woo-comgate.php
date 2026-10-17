<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://www.hilgert.cz/
 * @since             1.0.0
 * @package           LH Woo Comgate
 *
 * @wordpress-plugin
 * Plugin Name:       LH Woo Comgate
 * Plugin URI:        https://www.hilgert.cz/
 * Description:       LH Woo Comgate
 * Version:           1.1.0
 * Author:            Lubos Hilgert
 * Author URI:        https://www.hilgert.cz/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       lh-woo-comgate
 * Domain Path:       /languages
 */
// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// The plugin's own classes load through Composer too, so without vendor/ nothing else can run.
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    add_action('admin_notices', function () {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        $message = __('LH Woo Comgate is inactive: the vendor directory is missing. Run "composer install" in the plugin directory.', 'lh-woo-comgate');
        echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
    });
    return;
}

require_once __DIR__ . '/vendor/autoload.php';

\LHWooComgate\Init::register_services();
