<?php

// If uninstall not called from WordPress, then exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Order meta (_lh_woo_comgate_*) stays: it is the payment history of existing orders.
delete_option('woocommerce_lh_woo_comgate_settings');
