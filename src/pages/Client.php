<?php

namespace LHWooComgate\Pages;

use LHWooComgate\Base\BaseController;
use LHWooComgate\Gateway\ComgateGateway;

/**
 * Registers the Comgate payment gateway with WooCommerce.
 */
class Client extends BaseController {
    /**
     * Hooks into WordPress to expose the gateway for checkout.
     *
     * @return void
     */
    public function register() {
        add_filter('woocommerce_payment_gateways', [$this, 'register_gateway']);
        add_action('plugins_loaded', [$this, 'boot_gateway']);
        add_action('admin_notices', [$this, 'maybe_show_missing_sdk_notice']);
    }

    /**
     * Adds the Comgate gateway to WooCommerce.
     *
     * @param array<int, string> $gateways Registered gateways.
     * @return array<int, string>
     */
    public function register_gateway($gateways) {
        $gateways[] = ComgateGateway::class;

        return $gateways;
    }

    /**
     * Ensures the gateway class is available once WooCommerce is ready.
     *
     * @return void
     */
    public function boot_gateway() {
        if (!class_exists('\\WC_Payment_Gateway')) {
            return;
        }

        class_exists(ComgateGateway::class);
    }

    /**
     * Displays an admin notice when the Comgate SDK is missing.
     *
     * @return void
     */
    public function maybe_show_missing_sdk_notice() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        if (class_exists('\\Comgate\\Client') && !defined('LHWOO_COMGATE_SDK_STUB')) {
            return;
        }

        $message = __('Comgate SDK for PHP is not installed. Run "composer install" in the plugin directory to download vendor libraries.', 'lh-woo-comgate');

        if (defined('LHWOO_COMGATE_SDK_STUB')) {
            $message = __('Comgate SDK for PHP (official package) is missing. The bundled lightweight fallback is active, but you should run "composer install" to load the official library before going live.', 'lh-woo-comgate');
        }

        echo '<div class="notice notice-warning"><p>' . esc_html($message) . '</p></div>';
    }
}
