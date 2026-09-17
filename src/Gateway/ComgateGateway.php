<?php

namespace LHWooComgate\Gateway;

use WC_Payment_Gateway;

/**
 * WooCommerce payment gateway implementation for Comgate.
 */
class ComgateGateway extends WC_Payment_Gateway {
    /** @var string Endpoint slug used for synchronous return. */
    protected $returnEndpoint = 'lh_woo_comgate_return';

    /** @var string Endpoint slug used for asynchronous notifications. */
    protected $notifyEndpoint = 'lh_woo_comgate_notify';

    /** @var ComgateGatewayAdmin */
    protected $admin;

    /** @var ComgateGatewayPayment */
    protected $payments;

    /**
     * Sets up the gateway configuration and hooks.
     */
    public function __construct() {
        $this->id = 'lh_woo_comgate';
        $this->has_fields = false;
        $this->method_title = __('LH Comgate', 'lh-woo-comgate');
        $this->method_description = __('Accept card payments via the Comgate payment gateway.', 'lh-woo-comgate');
        $this->supports = ['products'];

        $this->admin = new ComgateGatewayAdmin($this);
        $this->init_form_fields();

        $logger = new ComgateLogger('yes' === $this->get_option('debug'), $this->get_option('secret'));
        $this->payments = new ComgateGatewayPayment($this, $logger);

        $this->title = $this->get_option('title');
        $asset_base = plugin_dir_url(dirname(__FILE__, 2)) . 'assets/img/';
        $this->icon = esc_url($asset_base . 'cg-thor.svg');
        $description_icons = sprintf(
            '<span class="lh-comgate-icons">' .
            '<img src="%s" alt="%s" width="64" height="32" />' .
            '<img src="%s" alt="%s" width="64" height="32" />' .
            '<img src="%s" alt="%s" width="64" height="32" />' .
            '</span>',
            esc_url($asset_base . 'card.svg'),
            esc_attr__('Visa, Mastercard', 'lh-woo-comgate'),
            esc_url($asset_base . 'apple-pay.svg'),
            esc_attr__('Apple Pay', 'lh-woo-comgate'),
            esc_url($asset_base . 'google-pay.svg'),
            esc_attr__('Google Pay', 'lh-woo-comgate')
        );
        $description_text = $this->get_option('description');
        $this->description = $description_text ? $description_text . ' ' . $description_icons : $description_icons;

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_api_' . $this->returnEndpoint, [$this, 'handle_return']);
        add_action('woocommerce_api_' . $this->notifyEndpoint, [$this, 'handle_notify']);
        add_filter('woocommerce_gateway_icon', [$this, 'filter_gateway_icon'], 10, 2);
    }

    /**
     * Defines the settings available in WooCommerce > Settings > Payments.
     *
     * @return void
     */
    public function init_form_fields() {
        $this->form_fields = $this->admin->get_form_fields();
    }

    /**
     * Processes an order and creates a payment in Comgate.
     *
     * @param int $order_id WooCommerce order identifier.
     * @return array<string, string>
     */
    public function process_payment($order_id) {
        return $this->payments->process_payment($order_id);
    }

    /**
     * Handles the synchronous return from the Comgate gateway.
     *
     * @return void
     */
    public function handle_return() {
        $this->payments->handle_return();
    }

    /**
     * Handles asynchronous notifications sent by Comgate.
     *
     * @return void
     */
    public function handle_notify() {
        $this->payments->handle_notify();
    }

    /**
     * Adds a custom class to the gateway icon markup.
     *
     * @param string $icon Current icon HTML.
     * @param string $id Gateway ID.
     * @return string
     */
    public function filter_gateway_icon($icon, $id) {
        if ($id !== $this->id || empty($this->icon)) {
            return $icon;
        }

        return sprintf(
            '<img src="%s" class="lh-comgate-icon" alt="%s" />',
            esc_url($this->icon),
            esc_attr($this->get_title())
        );
    }
}
