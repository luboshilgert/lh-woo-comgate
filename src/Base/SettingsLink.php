<?php

namespace LHWooComgate\Base;
use \LHWooComgate\Base\BaseController;

/**
 * Adds a quick link to the WooCommerce payment settings from the plugin list.
 */
class SettingsLink extends BaseController {
    /**
     * Registers the hook that injects the settings link.
     *
     * @return void
     */
    public function register() {
        add_filter("plugin_action_links_{$this->plugin}", [$this, 'settings_link']);
    }

    /**
     * Adds the settings link to the plugin action links.
     *
     * @param array<int, string> $links Existing action links.
     * @return array<int, string>
     */
    public function settings_link($links) {
        $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=lh_woo_comgate')) . '">' . esc_html__('Settings', 'lh-woo-comgate') . '</a>';
        $links[] = $settings_link;

        return $links;
    }
}
