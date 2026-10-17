<?php

namespace LHWooComgate\Base;

use \LHWooComgate\Base\BaseController;

/**
 * Declares which WooCommerce features the plugin supports.
 */
class Compatibility extends BaseController {
    /**
     * @return void
     */
    public function register() {
        add_action('before_woocommerce_init', [$this, 'declare_compatibility']);
    }

    /**
     * Orders are read and written only through the WC_Order API, so HPOS tables work.
     *
     * @return void
     */
    public function declare_compatibility() {
        if (!class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            return;
        }

        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', $this->plugin_path . 'lh-woo-comgate.php', true);
    }
}
