<?php

namespace LHWooComgate\Gateway;

use Comgate\SDK\Client;
use Comgate\SDK\Comgate;
use Comgate\SDK\Logging\FileLogger;

/**
 * Builds Comgate SDK clients for the current plugin setup.
 */
class ComgateClientFactory {
    /** @var string */
    private $logPath;

    /**
     * @param string $pluginRoot
     */
    public function __construct($pluginRoot) {
        $root = rtrim((string) $pluginRoot, '/\\');
        $this->logPath = $root . '/comgate.log';
    }

    /**
     * @return bool
     */
    public function isAvailable() {
        return class_exists(Client::class) && class_exists(Comgate::class);
    }

    /**
     * Build a Comgate client that matches the current WordPress environment.
     *
     * @param string $merchantId
     * @param string $secret
     * @return Client
     * @throws \RuntimeException When Comgate credentials are missing or the SDK is unavailable.
     */
    public function create($merchantId, $secret) {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Comgate SDK is not available. Install comgate/sdk through Composer.');
        }

        if ('' === trim((string) $merchantId) || '' === trim((string) $secret)) {
            throw new \RuntimeException(__('Merchant credentials are not configured.', 'lh-woo-comgate'));
        }

        $sdk = Comgate::defaults()
            ->setMerchant($merchantId)
            ->setSecret($secret)
            ->setLogger(new FileLogger($this->logPath));

        return $sdk->createClient();
    }
}
