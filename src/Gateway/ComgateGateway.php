<?php

namespace LHWooComgate\Gateway;

use Comgate\Client as ComgateClient;
use Comgate\Entity\Payment as ComgatePayment;
use Comgate\Merchant as ComgateMerchant;
use WC_Order;
use WC_Payment_Gateway;

/**
 * WooCommerce payment gateway implementation for Comgate.
 */
class ComgateGateway extends WC_Payment_Gateway {
    /** @var string Endpoint slug used for synchronous return. */
    protected $returnEndpoint = 'lh_woo_comgate_return';

    /** @var string Endpoint slug used for asynchronous notifications. */
    protected $notifyEndpoint = 'lh_woo_comgate_notify';

    /**
     * Sets up the gateway configuration and hooks.
     */
    public function __construct() {
        $this->id = 'lh_woo_comgate';
        $this->has_fields = false;
        $this->method_title = __('Comgate', 'lh-woo-comgate');
        $this->method_description = __('Accept card payments via the Comgate payment gateway.', 'lh-woo-comgate');
        $this->supports = ['products'];

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_api_' . $this->returnEndpoint, [$this, 'handle_return']);
        add_action('woocommerce_api_' . $this->notifyEndpoint, [$this, 'handle_notify']);
    }

    /**
     * Defines the settings available in WooCommerce > Settings > Payments.
     *
     * @return void
     */
    public function init_form_fields() {
        $this->form_fields = [
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
                'default' => 'yes',
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
            'prepare_only' => [
                'title' => __('Prepare only', 'lh-woo-comgate'),
                'type' => 'checkbox',
                'label' => __('Create payment in prepared state without redirecting immediately.', 'lh-woo-comgate'),
                'default' => 'no',
            ],
        ];
    }

    /**
     * Processes an order and creates a payment in Comgate.
     *
     * @param int $order_id WooCommerce order identifier.
     * @return array<string, string>
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);

        if (!$order instanceof WC_Order) {
            wc_add_notice(__('Unable to load order for Comgate payment.', 'lh-woo-comgate'), 'error');
            return ['result' => 'failure'];
        }

        try {
            $response = $this->create_remote_payment($order);
        } catch (\Exception $exception) {
            $order->update_status('failed', sprintf(__('Comgate payment failed: %s', 'lh-woo-comgate'), $exception->getMessage()));
            wc_add_notice(__('There was a problem communicating with Comgate. Please try again.', 'lh-woo-comgate'), 'error');

            return ['result' => 'failure'];
        }

        if (!$response->isOk() || !$response->getRedirectUrl()) {
            $order->update_status('failed', __('Comgate did not accept the payment request.', 'lh-woo-comgate'));
            wc_add_notice(__('Payment could not be initiated. Please try another payment method.', 'lh-woo-comgate'), 'error');

            return ['result' => 'failure'];
        }

        $order->update_status('pending', __('Awaiting payment confirmation from Comgate.', 'lh-woo-comgate'));

        $transactionId = $response->getTransactionId();
        if ($transactionId) {
            $order->update_meta_data('_lh_woo_comgate_trans_id', $transactionId);
        }

        $order->save();

        return [
            'result' => 'success',
            'redirect' => $response->getRedirectUrl(),
        ];
    }

    /**
     * Handles the synchronous return from the Comgate gateway.
     *
     * @return void
     */
    public function handle_return() {
        $transId = isset($_GET['transId']) ? sanitize_text_field(wp_unslash($_GET['transId'])) : null;
        if (!$transId) {
            wp_die(__('Missing Comgate transaction identifier.', 'lh-woo-comgate'));
        }

        $order = $this->find_order_by_transaction($transId);
        if (!$order) {
            wp_die(__('Order not found for Comgate transaction.', 'lh-woo-comgate'));
        }

        wp_safe_redirect($this->get_return_url($order));
        exit;
    }

    /**
     * Handles asynchronous notifications sent by Comgate.
     *
     * @return void
     */
    public function handle_notify() {
        $transId = isset($_POST['transId']) ? sanitize_text_field(wp_unslash($_POST['transId'])) : null;
        $status = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : null;

        if (!$transId || !$status) {
            status_header(400);
            echo 'Missing parameters';
            exit;
        }

        $order = $this->find_order_by_transaction($transId);
        if (!$order) {
            status_header(404);
            echo 'Order not found';
            exit;
        }

        if (in_array($status, ['PAID', 'AUTHORIZED'], true)) {
            $order->payment_complete($transId);
            $order->add_order_note(__('Comgate confirmed the payment.', 'lh-woo-comgate'));
        } elseif (in_array($status, ['CANCELED', 'TIMEOUT', 'REFUND'], true)) {
            $order->update_status('cancelled', __('Comgate reported that the payment was cancelled.', 'lh-woo-comgate'));
        }

        echo 'OK';
        exit;
    }

    /**
     * Creates the API client and sends the payment request.
     *
     * @param WC_Order $order WooCommerce order.
     * @return PaymentResponse
     */
    protected function create_remote_payment(WC_Order $order) {
        if (!class_exists(ComgateClient::class)) {
            throw new \RuntimeException(__('Comgate SDK for PHP is not installed.', 'lh-woo-comgate'));
        }

        $merchantId = trim((string) $this->get_option('merchant_id'));
        $secret = trim((string) $this->get_option('secret'));

        if ('' === $merchantId || '' === $secret) {
            throw new \RuntimeException(__('Merchant credentials are not configured.', 'lh-woo-comgate'));
        }

        $merchant = new ComgateMerchant(
            $merchantId,
            $secret,
            'yes' === $this->get_option('test_mode')
        );

        $client = new ComgateClient($merchant);
        $payment = new ComgatePayment($this->build_payment_payload($order));

        return $client->createPayment($payment);
    }

    /**
     * Converts the WooCommerce order into the payload consumed by the API.
     *
     * @param WC_Order $order WooCommerce order instance.
     * @return array<string, mixed>
     */
    protected function build_payment_payload(WC_Order $order) {
        $total = $order->get_total();
        $currency = $order->get_currency();

        return [
            'price' => (string) round($total * 100),
            'curr' => $currency,
            'label' => sprintf(__('Order %s', 'lh-woo-comgate'), $order->get_order_number()),
            'refId' => (string) $order->get_id(),
            'email' => $order->get_billing_email(),
            'method' => 'CARD',
            'lang' => $this->get_option('language', 'cs'),
            'prepareOnly' => 'yes' === $this->get_option('prepare_only') ? 'true' : 'false',
            'url' => $this->get_return_endpoint_url(),
            'urlNotify' => $this->get_notify_endpoint_url(),
        ];
    }

    /**
     * Resolves an order by its Comgate transaction identifier.
     *
     * @param string $transId Transaction ID from Comgate.
     * @return WC_Order|null
     */
    protected function find_order_by_transaction($transId) {
        $query = new \WP_Query([
            'post_type' => 'shop_order',
            'meta_key' => '_lh_woo_comgate_trans_id',
            'meta_value' => $transId,
            'posts_per_page' => 1,
            'fields' => 'ids',
        ]);

        if (empty($query->posts)) {
            return null;
        }

        return wc_get_order((int) $query->posts[0]);
    }

    /**
     * Returns the absolute URL to the synchronous callback handler.
     *
     * @return string
     */
    protected function get_return_endpoint_url() {
        return home_url('/wc-api/' . $this->returnEndpoint);
    }

    /**
     * Returns the absolute URL to the asynchronous notification handler.
     *
     * @return string
     */
    protected function get_notify_endpoint_url() {
        return home_url('/wc-api/' . $this->notifyEndpoint);
    }
}
