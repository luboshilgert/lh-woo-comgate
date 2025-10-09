<?php

/**
 * Registers the lightweight Comgate SDK fallback when the official library is unavailable.
 */
if (class_exists('\\Comgate\\Client', false)) {
    return;
}

$files = [
    __DIR__ . '/Merchant.php',
    __DIR__ . '/Entity/Payment.php',
    __DIR__ . '/Response/PaymentResponse.php',
    __DIR__ . '/Client.php',
];

foreach ($files as $file) {
    if (file_exists($file)) {
        require_once $file;
    }
}
