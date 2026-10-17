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
use Automattic\WooCommerce\Caches\OrderCache;
use Automattic\WooCommerce\Utilities\OrderUtil;
use WC_Order;

/**
 * Handles payment flows and callback endpoints for Comgate.
 */
class ComgateGatewayPayment {
    /** @var ComgateGateway */
    private $gateway;

    /** @var ComgateClientFactory */
    private $clientFactory;

    /** @var ComgateLogger */
    private $logger;

    /**
     * @param ComgateGateway $gateway
     * @param ComgateLogger $logger
     */
    public function __construct(ComgateGateway $gateway, ComgateLogger $logger) {
        $this->gateway = $gateway;
        $this->logger = $logger;
        $this->clientFactory = new ComgateClientFactory($logger);
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
            $this->logger->error('process_payment: order not found for order_id=' . $order_id);
            wc_add_notice(__('Unable to load order for Comgate payment.', 'lh-woo-comgate'), 'error');
            return ['result' => 'failure'];
        }

        // is_available() checks the shop currency, but an older order paid again on order-pay keeps its own.
        if ('CZK' !== $order->get_currency()) {
            $this->logger->error(sprintf('process_payment: order_id=%d has currency %s, Comgate payments are created in CZK only', $order->get_id(), $order->get_currency()));
            wc_add_notice(__('This order cannot be paid via Comgate. Please choose another payment method.', 'lh-woo-comgate'), 'error');
            return ['result' => 'failure'];
        }

        try {
            $response = $this->create_remote_payment($order);
        } catch (\Exception $exception) {
            // The detail goes only to the masking logger; order notes are not redacted.
            $this->logger->error('process_payment: exception=' . $exception->getMessage());
            $order->update_status('failed', __('Comgate payment could not be created. See the lh-woo-comgate log for details.', 'lh-woo-comgate'));
            wc_add_notice(__('There was a problem communicating with Comgate. Please try again.', 'lh-woo-comgate'), 'error');

            return ['result' => 'failure'];
        }

        if ($response->getCode() !== RequestCode::OK || '' === $response->getRedirect()) {
            $this->logger->error(sprintf(
                'process_payment: createPayment failed code=%s message=%s redirect=%s',
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
            // The mode the payment was created in, so switching the setting later does not hold pending payments.
            $order->update_meta_data('_lh_woo_comgate_test', 'yes' === $this->gateway->get_option('test_mode') ? 'yes' : 'no');
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
        $transId = isset($_GET['transId']) ? $this->normalize_trans_id(wp_unslash($_GET['transId'])) : null;
        if ($transId === null) {
            wp_die(__('Missing Comgate transaction identifier.', 'lh-woo-comgate'));
        }
        $order = $this->find_order_by_transaction($transId);
        if (!$order) {
            $this->logger->warning('handle_return: order not found for transId=' . $transId);
            wp_die(__('Order not found for Comgate transaction.', 'lh-woo-comgate'));
        }

        try {
            $paymentStatus = $this->get_payment_status($transId);
        } catch (\Throwable $exception) {
            $this->logger->error('handle_return: status error=' . $exception->getMessage());
            wp_die(__('Unable to verify Comgate payment status.', 'lh-woo-comgate'));
        }

        // A failure here is retried by the notification, so the customer still goes to the order page.
        $this->process_transaction($order, $transId, $paymentStatus, true, 'handle_return');

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
        $this->logger->debug('handle_notify: payload=' . wp_json_encode($data));

        try {
            $notification = PaymentNotification::createFrom($data);
        } catch (\Throwable $exception) {
            $this->logger->warning('handle_notify: unable to parse payload: ' . $exception->getMessage());
            status_header(400);
            echo 'Unable to parse notification payload';
            exit;
        }

        $transId = $this->normalize_trans_id($notification->getTransactionId());
        if ($transId === null) {
            $this->logger->warning('handle_notify: notification without a valid transaction identifier');
            status_header(400);
            echo 'Notification does not include a valid transaction identifier';
            exit;
        }

        try {
            $paymentStatus = $this->get_payment_status($transId);
        } catch (ApiException $exception) {
            $code = $exception->getCode();
            $httpStatus = (is_int($code) && $code >= 500 && $code < 600) ? 502 : 400;
            $this->logger->warning(sprintf('handle_notify: status check failed transId=%s code=%s message=%s', $transId, (string) $code, $exception->getMessage()));
            status_header($httpStatus);
            echo 'Unable to verify payment status';
            exit;
        } catch (\Throwable $exception) {
            $this->logger->error(sprintf('handle_notify: status check failed transId=%s error=%s', $transId, $exception->getMessage()));
            status_header(500);
            echo 'Unable to verify payment status';
            exit;
        }

        $owned = true;
        $order = $this->find_order_by_transaction($transId);
        if (!$order) {
            $owned = false;
            $refId = $paymentStatus->getRefId();
            $order = $this->find_order_by_reference($refId);

            if (!$order) {
                $this->logger->warning(sprintf('handle_notify: order not found transId=%s refId=%s', $transId, (string) $refId));
                status_header(404);
                echo 'Order not found';
                exit;
            }
        }

        // Comgate repeats a notification until it gets 200, which retries a lock timeout or a failed completion.
        if (!$this->process_transaction($order, $transId, $paymentStatus, $owned, 'handle_notify')) {
            status_header(503);
            echo 'Temporarily unable to process the notification';
            exit;
        }

        status_header(200);
        echo 'OK';
        exit;
    }

    /**
     * Applies a verified transaction to its order under a per-order database lock.
     *
     * @param WC_Order $order Order resolved for the transaction.
     * @param string $transId Comgate transaction identifier.
     * @param PaymentStatusResponse $paymentStatus Status fetched from the Comgate API.
     * @param bool $owned True when the order was found by its stored transaction, false when only by refId.
     * @param string $context Caller name for log records.
     * @return bool False when the caller should let Comgate retry (lock timeout, failed completion).
     */
    private function process_transaction(WC_Order $order, $transId, PaymentStatusResponse $paymentStatus, $owned, $context) {
        global $wpdb;

        // Return and notify often arrive together; without the lock both see an unpaid order and complete it twice.
        $lock = 'lh_woo_comgate_order_' . $order->get_id();
        $result = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', $lock));
        $locked = '1' === (string) $result;
        if ('0' === (string) $result) {
            $this->logger->warning(sprintf('%s: timed out waiting for the lock on order_id=%d transId=%s', $context, $order->get_id(), $transId));
            return false;
        }
        if (!$locked) {
            // NULL: the database does not support named locks, which is no reason to stop taking payments.
            $this->logger->warning(sprintf('%s: GET_LOCK unavailable for order_id=%d, continuing without the lock', $context, $order->get_id()));
        }

        try {
            $fresh = $this->reload_order($order->get_id());
            $order = $fresh instanceof WC_Order ? $fresh : $order;

            // The customer may have paid the order with another gateway after this Comgate attempt.
            if ($owned && $order->get_payment_method() === $this->gateway->id) {
                return $this->apply_status($order, $transId, $paymentStatus, $context);
            }

            $this->handle_stale_transaction($order, $transId, $paymentStatus, $owned);
            return true;
        } finally {
            if ($locked) {
                $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            }
        }
    }

    /**
     * Loads the order from the database, bypassing the copy cached before the lock was taken.
     *
     * @param int $orderId
     * @return WC_Order|false
     */
    private function reload_order($orderId) {
        // Same caches WooCommerce's order data store clears after a save.
        clean_post_cache($orderId);
        wp_cache_delete('order-items-' . $orderId, 'orders');
        if (class_exists(OrderUtil::class) && OrderUtil::orders_cache_usage_is_enabled()) {
            wc_get_container()->get(OrderCache::class)->remove($orderId);
        }

        return wc_get_order($orderId);
    }

    /**
     * Moves the order to the state of a verified Comgate transaction.
     *
     * @param WC_Order $order Order the transaction is stored on.
     * @param string $transId Comgate transaction identifier.
     * @param PaymentStatusResponse $paymentStatus Status fetched from the Comgate API.
     * @param string $context Caller name for log records.
     * @return bool False when completing the payment failed and Comgate should retry.
     */
    private function apply_status(WC_Order $order, $transId, PaymentStatusResponse $paymentStatus, $context) {
        $status = $paymentStatus->getStatus();

        if (in_array($status, [PaymentStatusCode::PAID, PaymentStatusCode::AUTHORIZED], true)) {
            if ($order->is_paid()) {
                // The same transaction confirmed by both return and notify is fine; a different one means a second payment.
                if ($order->get_transaction_id() !== $transId) {
                    $this->logger->warning(sprintf('%s: transId=%s was paid for already paid order_id=%d', $context, $transId, $order->get_id()));
                    $this->add_flag_note($order, $transId, sprintf(
                        /* translators: %s: Comgate transaction ID */
                        __('Comgate confirmed transaction %s although the order is already paid. The customer may have paid twice - check the Comgate portal and refund one payment.', 'lh-woo-comgate'),
                        $transId
                    ));
                }
                return true;
            }

            $mismatch = $this->find_payment_mismatch($order, $paymentStatus);
            if ($mismatch !== null) {
                $this->logger->warning(sprintf('%s: transaction does not match order_id=%d transId=%s mismatch=%s', $context, $order->get_id(), $transId, $mismatch));
                if (!$order->has_status('on-hold')) {
                    $order->update_status('on-hold', sprintf(
                        /* translators: 1: Comgate transaction ID, 2: what does not match, e.g. "amount" */
                        __('Comgate confirmed transaction %1$s, but it does not match the order (%2$s). Check the payment in the Comgate portal before fulfilling the order.', 'lh-woo-comgate'),
                        $transId,
                        $this->get_mismatch_label($mismatch)
                    ));
                }
                return true;
            }

            // WooCommerce returns false when a payment hook throws; it already adds an error note to the order.
            if (!$order->payment_complete($transId)) {
                $this->logger->error(sprintf('%s: payment_complete failed for order_id=%d transId=%s', $context, $order->get_id(), $transId));
                return false;
            }
            $order->add_order_note($paymentStatus->isTest()
                ? __('Comgate confirmed the test payment.', 'lh-woo-comgate')
                : __('Comgate confirmed the payment.', 'lh-woo-comgate'));
        } elseif ($status === PaymentStatusCode::CANCELLED) {
            // Comgate cancels unpaid payments when they expire, also after the shop moved the order on (paid, on-hold).
            if (!$order->has_status('pending')) {
                $this->logger->info(sprintf('%s: ignoring cancellation of transId=%s, order_id=%d has status %s', $context, $transId, $order->get_id(), $order->get_status()));
                return true;
            }
            // Failed, not cancelled: only a failed order can be paid again and the thank-you page flags it as unpaid.
            $order->update_status('failed', __('Comgate reported that the payment was cancelled.', 'lh-woo-comgate'));
        } elseif ($status === PaymentStatusCode::PENDING) {
            if (!$order->is_paid()) {
                $order->add_order_note(__('Comgate reported that the payment is pending.', 'lh-woo-comgate'));
            }
        } else {
            $this->logger->warning(sprintf('%s: unhandled status=%s transId=%s order_id=%d', $context, $status, $transId, $order->get_id()));
        }

        return true;
    }

    /**
     * Flags a payment confirmed on a transaction the order no longer uses.
     *
     * @param WC_Order $order The order the transaction was created for.
     * @param string $transId The older Comgate transaction identifier.
     * @param PaymentStatusResponse $paymentStatus Status fetched from the Comgate API.
     * @param bool $owned True when the order was found by its stored transaction, false when only by refId.
     * @return void
     */
    private function handle_stale_transaction(WC_Order $order, $transId, PaymentStatusResponse $paymentStatus, $owned) {
        $this->logger->info(sprintf(
            'stale transaction transId=%s for order_id=%d refId=%s status=%s payment_method=%s',
            $transId,
            $order->get_id(),
            $paymentStatus->getRefId(),
            $paymentStatus->getStatus(),
            $order->get_payment_method()
        ));

        if (!in_array($paymentStatus->getStatus(), [PaymentStatusCode::PAID, PaymentStatusCode::AUTHORIZED], true)) {
            return;
        }

        // refId is only the order number, so another shop on the same merchant account can collide with it.
        if (!$owned && $order->get_payment_method() !== $this->gateway->id) {
            return;
        }
        if ($this->find_payment_mismatch($order, $paymentStatus) !== null) {
            return;
        }

        $this->logger->warning(sprintf('older transaction transId=%s was paid for order_id=%d', $transId, $order->get_id()));
        $this->add_flag_note($order, $transId, sprintf(
            $order->is_paid()
                /* translators: %s: Comgate transaction ID */
                ? __('Comgate confirmed an older transaction %s although the order is already paid. The customer may have paid twice - check the Comgate portal and refund one payment.', 'lh-woo-comgate')
                /* translators: %s: Comgate transaction ID */
                : __('Comgate confirmed an older transaction %s that the order no longer uses. Check the payment in the Comgate portal and update the order manually.', 'lh-woo-comgate'),
            $transId
        ));
    }

    /**
     * Adds an order note once per transaction, since Comgate may deliver a notification repeatedly.
     *
     * @param WC_Order $order
     * @param string $transId
     * @param string $note
     * @return void
     */
    private function add_flag_note(WC_Order $order, $transId, $note) {
        $flagged = (array) $order->get_meta('_lh_woo_comgate_flagged');
        if (in_array($transId, $flagged, true)) {
            return;
        }

        $flagged[] = $transId;
        $order->update_meta_data('_lh_woo_comgate_flagged', $flagged);
        $order->save();
        $order->add_order_note($note);
    }

    /**
     * Compares a verified transaction with the order it is applied to.
     *
     * @param WC_Order $order WooCommerce order.
     * @param PaymentStatusResponse $paymentStatus Status fetched from the Comgate API.
     * @return string|null Mismatch code (reference, currency, amount, test), or null when the transaction fits the order.
     */
    private function find_payment_mismatch(WC_Order $order, PaymentStatusResponse $paymentStatus) {
        if ((string) $paymentStatus->getRefId() !== (string) $order->get_id()) {
            return 'reference';
        }
        if (strtoupper($paymentStatus->getCurrency()) !== strtoupper($order->get_currency())) {
            return 'currency';
        }
        if ($paymentStatus->getPrice()->get() !== $this->to_cents($order->get_total())) {
            return 'amount';
        }

        // Orders created before the mode was stored fall back to the current setting.
        $storedTest = $order->get_meta('_lh_woo_comgate_test');
        $expectTest = '' !== $storedTest ? 'yes' === $storedTest : 'yes' === $this->gateway->get_option('test_mode');
        // A test payment costs the payer nothing, so it must not complete a live order.
        if ($paymentStatus->isTest() && !$expectTest) {
            return 'test';
        }

        return null;
    }

    /**
     * @param string $mismatch Code returned by find_payment_mismatch().
     * @return string
     */
    private function get_mismatch_label($mismatch) {
        $labels = [
            'reference' => __('order number', 'lh-woo-comgate'),
            'currency' => __('currency', 'lh-woo-comgate'),
            'amount' => __('amount', 'lh-woo-comgate'),
            'test' => __('test payment on a live order', 'lh-woo-comgate'),
        ];

        return isset($labels[$mismatch]) ? $labels[$mismatch] : $mismatch;
    }

    /**
     * Converts a WooCommerce decimal amount to minor units.
     *
     * @param string|float $amount
     * @return int
     */
    private function to_cents($amount) {
        return (int) round((float) $amount * 100);
    }

    /**
     * Returns the transaction ID when it has the Comgate format, null otherwise.
     *
     * @param mixed $transId
     * @return string|null
     */
    private function normalize_trans_id($transId) {
        if (!is_string($transId)) {
            return null;
        }
        $transId = trim($transId);

        // Rejects line breaks and markup before the value reaches the log or the API.
        return preg_match('/^[A-Za-z0-9-]{1,64}$/', $transId) === 1 ? $transId : null;
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
            $this->logger->error('create_remote_payment: missing merchant credentials.');
            throw new \RuntimeException(__('Merchant credentials are not configured.', 'lh-woo-comgate'));
        }

        $testMode = 'yes' === $this->gateway->get_option('test_mode');

        if (!$this->clientFactory->isAvailable()) {
            $this->logger->error('create_remote_payment: Comgate SDK class missing.');
            throw new \RuntimeException(__('Comgate SDK for PHP is not installed.', 'lh-woo-comgate'));
        }

        $client = $this->clientFactory->create($merchantId, $secret);

        try {
            $payment = $this->build_sdk_payment($order, $testMode);
            $response = $client->createPayment($payment);
            if ($response->getCode() !== RequestCode::OK) {
                $this->logger->error('create_remote_payment: SDK error=' . $response->getMessage());
                throw new \RuntimeException('Comgate payment initiation failed: ' . $response->getMessage());
            }
        } catch (ApiException $exception) {
            $this->logger->error('create_remote_payment: SDK API exception=' . $exception->getMessage());
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
            ->setPrice(Money::ofCents($this->to_cents($order->get_total())))
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
