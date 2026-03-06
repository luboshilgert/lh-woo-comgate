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

        if ($this->has_official_comgate_sdk()) {
            return;
        }

        $message = __('Comgate SDK for PHP is not installed. Run "composer install" in the plugin directory to download vendor libraries.', 'lh-woo-comgate');

        echo '<div class="notice notice-warning"><p>' . esc_html($message) . '</p></div>';
    }

    /**
     * Determines whether the official Comgate SDK dependency is available.
     *
     * @return bool
     */
    protected function has_official_comgate_sdk() {
        if (class_exists('\\Comgate\\Client')) {
            return true;
        }

        if (class_exists('\\Composer\\InstalledVersions')) {
            try {
                if (\Composer\InstalledVersions::isInstalled('comgate/sdk')) {
                    return true;
                }
            } catch (\OutOfBoundsException $exception) {
                // Ignore the exception and continue with filesystem checks.
            }
        }

        $basePath = $this->plugin_path;
        if (function_exists('trailingslashit')) {
            $basePath = trailingslashit($basePath);
        } else {
            $basePath = rtrim((string) $basePath, '/\\') . '/';
        }

        $vendorDir = $basePath . 'vendor/';

        if (is_dir($vendorDir . 'comgate/sdk')) {
            return true;
        }

        $installedJson = $vendorDir . 'composer/installed.json';
        if (is_readable($installedJson)) {
            $contents = file_get_contents($installedJson);
            if (false !== $contents && false !== strpos($contents, '"comgate/sdk"')) {
                return true;
            }
        }

        return false;
    }
}
