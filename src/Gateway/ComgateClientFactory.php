<?php

namespace LHWooComgate\Gateway;

use Comgate\SDK\Client;
use Comgate\SDK\Comgate;
use Psr\Log\LoggerInterface;

/**
 * Builds Comgate SDK clients for the current plugin setup.
 */
class ComgateClientFactory {
    /** @var LoggerInterface */
    private $logger;

    /**
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger) {
        $this->logger = $logger;
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
            ->setLogger($this->logger);

        return $sdk->createClient();
    }
}
