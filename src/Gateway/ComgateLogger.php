<?php

namespace LHWooComgate\Gateway;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Forwards plugin and Comgate SDK log records to the WooCommerce logger.
 */
class ComgateLogger implements LoggerInterface {
    const SOURCE = 'lh-woo-comgate';

    /** @var bool */
    private $debug;

    /** @var string */
    private $secret;

    /**
     * @param bool $debug Whether to keep debug, info and notice records too, not only warnings and errors.
     * @param string $secret Configured API secret, masked wherever it appears.
     */
    public function __construct($debug, $secret = '') {
        $this->debug = (bool) $debug;
        $this->secret = trim((string) $secret);
    }

    /**
     * @param mixed $level
     * @param string|\Stringable $message
     * @param array<mixed> $context
     */
    public function log($level, $message, array $context = []): void {
        if (!function_exists('wc_get_logger')) {
            return;
        }

        // Debug records carry full API responses with customer names and e-mails.
        if (!$this->debug && in_array($level, [LogLevel::DEBUG, LogLevel::INFO, LogLevel::NOTICE], true)) {
            return;
        }

        wc_get_logger()->log($level, $this->redact((string) $message), ['source' => self::SOURCE]);
    }

    /**
     * Masks the API secret, which Comgate echoes back in status responses and notifications.
     *
     * @param string $message
     * @return string
     */
    public function redact($message) {
        $message = (string) $message;

        // The literal match covers any dump format; the pattern catches a secret that differs from the configured one.
        if ('' !== $this->secret) {
            $message = str_replace([$this->secret, rawurlencode($this->secret)], '***', $message);
        }

        return (string) preg_replace('/(secret(?:["\'\]]|%5D)?\s*(?:=>|[=:])\s*["\']?)[^&"\'\s]+/i', '$1***', $message);
    }

    // PSR-3 methods stay untyped, like the SDK's FileLogger, so they satisfy psr/log 1.x to 3.x loaded by another plugin.

    public function emergency($message, array $context = []): void {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert($message, array $context = []): void {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical($message, array $context = []): void {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error($message, array $context = []): void {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning($message, array $context = []): void {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice($message, array $context = []): void {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info($message, array $context = []): void {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug($message, array $context = []): void {
        $this->log(LogLevel::DEBUG, $message, $context);
    }
}
