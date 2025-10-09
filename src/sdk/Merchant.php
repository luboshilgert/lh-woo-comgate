<?php

namespace Comgate;

/**
 * Holds authentication details for the Comgate API.
 */
class Merchant {
    /** @var string */
    private $merchantId;

    /** @var string */
    private $secret;

    /** @var bool */
    private $testMode;

    /**
     * @param string $merchantId Merchant identifier assigned by Comgate.
     * @param string $secret Secret used to sign API requests.
     * @param bool   $testMode Whether test mode should be enabled.
     */
    public function __construct($merchantId, $secret, $testMode = false) {
        $this->merchantId = $merchantId;
        $this->secret = $secret;
        $this->testMode = (bool) $testMode;
    }

    /**
     * Returns the merchant identifier.
     *
     * @return string
     */
    public function getMerchantId() {
        return $this->merchantId;
    }

    /**
     * Returns the API secret.
     *
     * @return string
     */
    public function getSecret() {
        return $this->secret;
    }

    /**
     * Indicates whether requests should target the testing environment.
     *
     * @return bool
     */
    public function isTestMode() {
        return $this->testMode;
    }
}
