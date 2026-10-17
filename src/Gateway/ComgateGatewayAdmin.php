<?php

namespace LHWooComgate\Gateway;

/**
 * Provides admin configuration for the Comgate gateway.
 */
class ComgateGatewayAdmin {
    /** @var ComgateGateway */
    private $gateway;

    /**
     * @param ComgateGateway $gateway
     */
    public function __construct(ComgateGateway $gateway) {
        $this->gateway = $gateway;
    }

    /**
     * Defines the settings available in WooCommerce > Settings > Payments.
     *
     * @return array<string, array<string, mixed>>
     */
    public function get_form_fields() {
        return [
            'enabled' => [
                'title' => __('Enable/Disable', 'lh-woo-comgate'),
                'type' => 'checkbox',
                'label' => __('Enable Comgate payments', 'lh-woo-comgate'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'lh-woo-comgate'),
                'type' => 'text',
                'description' => __('Displayed during checkout.', 'lh-woo-comgate'),
                'default' => __('Credit card (Comgate)', 'lh-woo-comgate'),
                'desc_tip' => true,
            ],
            'description' => [
                'title' => __('Description', 'lh-woo-comgate'),
                'type' => 'textarea',
                'default' => __('Secure card payment provided by Comgate.', 'lh-woo-comgate'),
            ],
            'merchant_id' => [
                'title' => __('Merchant ID', 'lh-woo-comgate'),
                'type' => 'text',
                'description' => __('Your Comgate merchant identifier.', 'lh-woo-comgate'),
                'default' => '',
            ],
            'secret' => [
                'title' => __('Secret', 'lh-woo-comgate'),
                'type' => 'password',
                'description' => __('API secret used to sign requests.', 'lh-woo-comgate'),
                'default' => '',
            ],
            'test_mode' => [
                'title' => __('Test mode', 'lh-woo-comgate'),
                'type' => 'checkbox',
                'label' => __('Send payments to the Comgate sandbox.', 'lh-woo-comgate'),
                'default' => 'no',
            ],
            'language' => [
                'title' => __('Language', 'lh-woo-comgate'),
                'type' => 'select',
                'options' => [
                    'cs' => __('Czech', 'lh-woo-comgate'),
                    'en' => __('English', 'lh-woo-comgate'),
                    'sk' => __('Slovak', 'lh-woo-comgate'),
                ],
                'default' => 'cs',
            ],
            'debug' => [
                'title' => __('Debug log', 'lh-woo-comgate'),
                'type' => 'checkbox',
                'label' => __('Log Comgate API communication', 'lh-woo-comgate'),
                'description' => sprintf(
                    /* translators: %s: URL of the WooCommerce logs screen */
                    __('Requests and responses go to the <a href="%s">WooCommerce logs</a> (source lh-woo-comgate) with the API secret masked. They include customer names, e-mails and payment details, so enable this only while debugging. Errors are logged either way.', 'lh-woo-comgate'),
                    esc_url(admin_url('admin.php?page=wc-status&tab=logs&source=' . ComgateLogger::SOURCE))
                ),
                'default' => 'no',
            ],
          /*  'prepare_only' => [
                'title' => __('Prepare only', 'lh-woo-comgate'),
                'type' => 'checkbox',
                'label' => __('Create payment in prepared state without redirecting immediately.', 'lh-woo-comgate'),
                'default' => 'no',
            ],*/
        ];
    }
}
