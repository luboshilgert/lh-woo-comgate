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
        add_action('wp_loaded', [$this, 'restore_payment_settings_tab'], 1);
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
     * Restores the missing `tab=checkout` parameter before WooCommerce processes settings saves.
     *
     * WooCommerce 10.8 introduced a React-based payment settings UI that uses the History API
     * to navigate between sections. When the user opens a gateway detail page, React pushes a
     * new URL that drops the `tab=checkout` query parameter. Because the settings form's action
     * attribute is empty, the POST request on save lands on that tab-less URL.
     *
     * `WC_Admin_Menus::save_settings()` runs on `wp_loaded` at priority 10 and determines which
     * settings section to save entirely from `$_GET['tab']`. Without `tab=checkout` it defaults
     * to `general`, so the `woocommerce_update_options_payment_gateways_lh_woo_comgate` action
     * is never fired and gateway credentials (merchant_id, secret, …) are silently discarded.
     *
     * This method runs at priority 1 (before WC's priority 10) and re-injects `tab=checkout`
     * into `$_GET` and `$_REQUEST` whenever a POST targeting our gateway section is detected,
     * restoring the correct routing without touching anything else.
     *
     * Coupling note: this targets WooCommerce's classic form-POST save path (`wp_loaded`).
     * WC also has a REST save path that reads the tab from the request body, not `$_GET`; if a
     * future WC version moves gateway settings entirely to REST, this hook becomes a harmless
     * no-op and the fix would need to move accordingly.
     *
     * @return void
     */
    public function restore_payment_settings_tab() {
        if (!is_admin() || empty($_POST['save'])) {
            return;
        }

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ('wc-settings' !== $page) {
            return;
        }

        if (!empty($_GET['tab'])) {
            return;
        }

        $section = isset($_REQUEST['section']) ? sanitize_title(wp_unslash($_REQUEST['section'])) : '';
        if ('lh_woo_comgate' !== $section) {
            return;
        }

        $_GET['tab']     = 'checkout';
        $_REQUEST['tab'] = 'checkout';
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
