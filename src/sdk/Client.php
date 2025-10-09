<?php

namespace Comgate;

use Comgate\Entity\Payment;
use Comgate\Response\PaymentResponse;

if (!defined('LHWOO_COMGATE_SDK_STUB')) {
    /**
     * Flag used to detect the bundled lightweight SDK implementation.
     */
    define('LHWOO_COMGATE_SDK_STUB', true);
}

/**
 * Minimal HTTP client compatible with the Comgate PHP SDK interface.
 */
class Client {
    private const BASE_URL = 'https://payments.comgate.cz/v1.0/';
    private const BASE_URL_TEST = 'https://payments.comgate.cz/v1.0/';

    /** @var Merchant */
    private $merchant;

    /**
     * @param Merchant $merchant Configuration for the current merchant account.
     */
    public function __construct(Merchant $merchant) {
        $this->merchant = $merchant;
    }

    /**
     * Initiates a new payment.
     *
     * @param Payment $payment Payment configuration payload.
     * @return PaymentResponse
     */
    public function createPayment(Payment $payment) {
        $endpoint = ($this->merchant->isTestMode() ? self::BASE_URL_TEST : self::BASE_URL) . 'create';

        $body = array_merge(
            [
                'merchant' => $this->merchant->getMerchantId(),
                'secret' => $this->merchant->getSecret(),
                'test' => $this->merchant->isTestMode() ? '1' : '0',
            ],
            $payment->toArray()
        );

        if (!function_exists('wp_remote_post')) {
            throw new \RuntimeException('WordPress HTTP API is not available.');
        }

        $response = wp_remote_post($endpoint, [
            'timeout' => 20,
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            return new PaymentResponse([
                'code' => '1',
                'message' => $response->get_error_message(),
            ]);
        }

        $body = wp_remote_retrieve_body($response);
        parse_str((string) $body, $parsed);

        if (!is_array($parsed)) {
            $parsed = [
                'code' => '1',
                'message' => 'Unexpected response format from Comgate.',
            ];
        }

        if (!isset($parsed['code'])) {
            $parsed['code'] = '1';
        }

        return new PaymentResponse(array_map('strval', $parsed));
    }
}
