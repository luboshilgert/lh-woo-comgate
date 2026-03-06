<?php

namespace LHWooComgate\Gateway;

use Comgate\SDK\Entity\Codes\CategoryCode;
use Comgate\SDK\Entity\Codes\CurrencyCode;
use Comgate\SDK\Entity\Codes\DeliveryCode;
use Comgate\SDK\Entity\Codes\PaymentStatusCode;
use Comgate\SDK\Entity\Codes\PaymentMethodCode;
use Comgate\SDK\Entity\Codes\RequestCode;
use Comgate\SDK\Entity\Money;
use Comgate\SDK\Entity\Payment;
use Comgate\SDK\Entity\PaymentNotification;
use Comgate\SDK\Entity\Response\PaymentCreateResponse;
use Comgate\SDK\Entity\Response\PaymentStatusResponse;
use Comgate\SDK\Exception\ApiException;
use WC_Order;

/**
 * Handles payment flows and callback endpoints for Comgate.
 */
class ComgateGatewayPayment {
    /** @var ComgateGateway */
    private $gateway;

    /** @var ComgateClientFactory */
    private $clientFactory;

    /**
     * @param ComgateGateway $gateway
     * @param string $returnEndpoint
     * @param string $notifyEndpoint
     */
    public function __construct(ComgateGateway $gateway) {
        $this->gateway = $gateway;
        $this->clientFactory = new ComgateClientFactory(dirname(__DIR__, 2));
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
            error_log('[lh-woo-comgate] process_payment: order not found for order_id=' . $order_id);
            wc_add_notice(__('Unable to load order for Comgate payment.', 'lh-woo-comgate'), 'error');
            return ['result' => 'failure'];
        }

        try {
            $response = $this->create_remote_payment($order);
        } catch (\Exception $exception) {
            error_log('[lh-woo-comgate] process_payment: exception=' . $exception->getMessage());
            $order->update_status('failed', sprintf(__('Comgate payment failed: %s', 'lh-woo-comgate'), $exception->getMessage()));
            wc_add_notice(__('There was a problem communicating with Comgate. Please try again.', 'lh-woo-comgate'), 'error');

            return ['result' => 'failure'];
        }

        if ($response->getCode() !== RequestCode::OK || '' === $response->getRedirect()) {
            error_log(sprintf(
                '[lh-woo-comgate] process_payment: createPayment failed code=%s message=%s redirect=%s',
                $response->getCode(),
                $response->getMessage(),
                $response->getRedirect() !== '' ? $response->getRedirect() : 'n/a'
            ));
            $order->update_status('failed', __('Comgate did not accept the payment request.', 'lh-woo-comgate'));
            wc_add_notice(__('Payment could not be initiated. Please try another payment method.', 'lh-woo-comgate'), 'error');

            return ['result' => 'failure'];
        }

        $order->update_status('pending', __('Awaiting payment confirmation from Comgate.', 'lh-woo-comgate'));

        $transactionId = $response->getTransId();
        if ($transactionId !== '') {
            $order->update_meta_data('_lh_woo_comgate_trans_id', $transactionId);
        }

        $order->save();

        return [
            'result' => 'success',
            'redirect' => $response->getRedirect(),
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

        try {
            $status = $this->get_payment_status($transId);
        } catch (ApiException $exception) {
            error_log('[lh-woo-comgate] handle_return: status error=' . $exception->getMessage());
            wp_die(__('Unable to verify Comgate payment status.', 'lh-woo-comgate'));
        }

        if (in_array($status, [PaymentStatusCode::PAID, PaymentStatusCode::AUTHORIZED], true)) {
            $order->payment_complete($transId);
            $order->add_order_note(__('Comgate confirmed the payment.', 'lh-woo-comgate'));
        } elseif ($status === PaymentStatusCode::CANCELLED) {
            $order->update_status('cancelled', __('Comgate reported that the payment was cancelled.', 'lh-woo-comgate'));
        } elseif ($status === PaymentStatusCode::PENDING) {
            $order->add_order_note(__('Comgate reported that the payment is pending.', 'lh-woo-comgate'));
        }

        wp_safe_redirect($this->gateway->get_return_url($order));
        exit;
    }

    /**
     * Handles asynchronous notifications sent by Comgate.
     *
     * @return void
     */
    public function handle_notify() {
        if (!$this->clientFactory->isAvailable()) {
            status_header(500);
            echo 'Comgate client is not initialised.';
            exit;
        }

        $data = wp_unslash($_POST);
        error_log('[lh-woo-comgate] handle_notify: payload=' . wp_json_encode($data));

        try {
            $notification = PaymentNotification::createFrom($data);
        } catch (\Throwable $exception) {
            status_header(400);
            echo 'Unable to parse notification payload';
            exit;
        }

        $transId = $notification->getTransactionId();
        if (!is_string($transId) || $transId === '') {
            status_header(400);
            echo 'Notification does not include a valid transaction identifier';
            exit;
        }

        try {
            $paymentStatus = $this->get_payment_status($transId);
        } catch (ApiException $exception) {
            $code = $exception->getCode();
            $httpStatus = (is_int($code) && $code >= 500 && $code < 600) ? 502 : 400;
            status_header($httpStatus);
            echo $exception->getMessage();
            exit;
        }

        $status = $paymentStatus->getStatus();

        $order = $this->find_order_by_transaction($transId);
        if (!$order) {
            $refId = $paymentStatus->getRefId();
            $order = $this->find_order_by_reference($refId);

            if ($order) {
                error_log(sprintf(
                    '[lh-woo-comgate] handle_notify: ignoring stale transaction transId=%s for order_id=%d resolved via refId=%s',
                    $transId,
                    $order->get_id(),
                    (string) $refId
                ));
                status_header(200);
                echo 'OK';
                exit;
            }

            status_header(404);
            echo 'Order not found';
            exit;
        }

        if (in_array($status, [PaymentStatusCode::PAID, PaymentStatusCode::AUTHORIZED], true)) {
            $order->payment_complete($transId);
            $order->add_order_note(__('Comgate confirmed the payment.', 'lh-woo-comgate'));
        } elseif ($status === PaymentStatusCode::CANCELLED) {
            $order->update_status('cancelled', __('Comgate reported that the payment was cancelled.', 'lh-woo-comgate'));
        } elseif ($status === PaymentStatusCode::PENDING) {
            $order->add_order_note(__('Comgate reported that the payment is pending.', 'lh-woo-comgate'));
        }

        status_header(200);
        echo 'OK';
        exit;
    }

    /**
     * Creates the API client and sends the payment request.
     *
     * @param WC_Order $order WooCommerce order.
     * @return PaymentCreateResponse
     */
    private function create_remote_payment(WC_Order $order) {
        $merchantId = trim((string) $this->gateway->get_option('merchant_id'));
        $secret = trim((string) $this->gateway->get_option('secret'));

        if ('' === $merchantId || '' === $secret) {
            error_log('[lh-woo-comgate] create_remote_payment: missing merchant credentials.');
            throw new \RuntimeException(__('Merchant credentials are not configured.', 'lh-woo-comgate'));
        }

        $testMode = 'yes' === $this->gateway->get_option('test_mode');

        if (!$this->clientFactory->isAvailable()) {
            error_log('[lh-woo-comgate] create_remote_payment: Comgate SDK class missing.');
            throw new \RuntimeException(__('Comgate SDK for PHP is not installed.', 'lh-woo-comgate'));
        }

        $client = $this->clientFactory->create($merchantId, $secret);

        try {
            $payment = $this->build_sdk_payment($order, $testMode);
            $response = $client->createPayment($payment);
            if ($response->getCode() !== RequestCode::OK) {
                error_log('[lh-woo-comgate] create_remote_payment: SDK error=' . $response->getMessage());
                throw new \RuntimeException('Comgate payment initiation failed: ' . $response->getMessage());
            }
        } catch (ApiException $exception) {
            error_log('[lh-woo-comgate] create_remote_payment: SDK API exception=' . $exception->getMessage());
            throw new \RuntimeException('Comgate payment API error: ' . $exception->getMessage(), 0, $exception);
        }

        return $response;
    }

    /**
     * Builds a Comgate SDK payment request.
     *
     * @param WC_Order $order WooCommerce order instance.
     * @param bool $testMode Whether Comgate should run in test mode.
     * @return Payment
     */
    private function build_sdk_payment(WC_Order $order, $testMode) {
        $payment = new Payment();
        $billingName = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());

        $payment
            ->setPrice(Money::ofInt($order->get_total()))
            ->setCurrency(CurrencyCode::CZK)
            ->setParam('enableApplePayGooglePay', true)
            ->setLabel(sprintf(__('Objednávka %s', 'lh-woo-comgate'), $order->get_order_number()))
            ->setReferenceId((string) $order->get_id())
            ->setEmail((string) $order->get_billing_email())
            ->setName($billingName !== '' ? $billingName : (string) $order->get_billing_email())
            ->setTest((bool) $testMode)
            ->addMethod(PaymentMethodCode::CARD_CARD_CZ_COMGATE)
            ->setCategory(CategoryCode::OTHER)
            ->setDelivery(DeliveryCode::ELECTRONIC_DELIVERY);

        return $payment;
    }

    /**
     * Resolves an order by its Comgate transaction identifier.
     *
     * @param string|null $transId Transaction ID from Comgate.
     * @return WC_Order|null
     */
    private function find_order_by_transaction($transId) {
        $transId = $transId !== null ? trim((string) $transId) : '';
        if ($transId === '') {
            return null;
        }

        $orders = wc_get_orders([
            'limit' => 1,
            'return' => 'objects',
            'meta_key' => '_lh_woo_comgate_trans_id',
            'meta_value' => $transId,
        ]);
        if (!empty($orders)) {
            return $orders[0];
        }

        return null;
    }

    /**
     * Resolves an order by its Comgate reference identifier.
     *
     * @param string|null $refId Reference ID from Comgate, mapped to WooCommerce order ID.
     * @return WC_Order|null
     */
    private function find_order_by_reference($refId) {
        $refId = $refId !== null ? trim((string) $refId) : '';
        if ($refId === '' || !ctype_digit($refId)) {
            return null;
        }

        $order = wc_get_order((int) $refId);

        return $order instanceof WC_Order ? $order : null;
    }

    /**
     * Fetches the current status from Comgate.
     *
     * @param string $transactionId
     * @return PaymentStatusResponse
     * @throws ApiException
     */
    private function get_payment_status($transactionId) {
        $merchantId = trim((string) $this->gateway->get_option('merchant_id'));
        $secret = trim((string) $this->gateway->get_option('secret'));
        $client = $this->clientFactory->create($merchantId, $secret);

        return $client->getStatus($transactionId);
    }

}
