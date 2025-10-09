<?php

namespace Comgate\Response;

/**
 * Wraps a response returned by the Comgate API.
 */
class PaymentResponse {
    /** @var array<string, string> */
    private $data;

    /**
     * @param array<string, string> $data Response data.
     */
    public function __construct(array $data) {
        $this->data = $data;
    }

    /**
     * Indicates whether the API responded with a success code.
     *
     * @return bool
     */
    public function isOk() {
        return isset($this->data['code']) && (int) $this->data['code'] === 0;
    }

    /**
     * Returns the redirect URL provided by Comgate.
     *
     * @return string|null
     */
    public function getRedirectUrl() {
        return isset($this->data['redirect']) ? $this->data['redirect'] : null;
    }

    /**
     * Returns the transaction identifier assigned by Comgate.
     *
     * @return string|null
     */
    public function getTransactionId() {
        return isset($this->data['transId']) ? $this->data['transId'] : null;
    }

    /**
     * Provides the raw response array.
     *
     * @return array<string, string>
     */
    public function getData() {
        return $this->data;
    }
}
