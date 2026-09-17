# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A WooCommerce payment gateway plugin (`LH Woo Comgate`) that adds Comgate card payments (incl. Apple Pay / Google Pay) to a WordPress shop. It wraps the official [`comgate/sdk-php`](https://github.com/comgate-payments/sdk-php) library. The plugin lives inside a full WordPress site tree; the repository root is the plugin directory itself.

## Commands

- `composer install` — download the Comgate SDK into `vendor/` (gitignored). The SDK is pulled from GitHub via a `vcs` repository in `composer.json` (package `comgate/sdk`, `dev-master`), **not** Packagist. If the GitHub API rate limit is hit, supply a personal access token to Composer (see `readme.md`).
- `composer dump-autoload` — regenerate the PSR-4 autoloader after adding classes under `src/`.

There is no build step, linter config, or test suite in this repo.

## Architecture

**Bootstrap chain:** `lh-woo-comgate.php` (WP plugin header) loads `vendor/autoload.php`, registers activation/deactivation hooks, then calls `Init::register_services()`.

**Service registry (`src/Init.php`):** `get_services()` returns a hardcoded list of classes; `register_services()` instantiates each and calls its `->register()` method if present. To wire up new WordPress hooks, add a class to this list — do not add `add_action`/`add_filter` calls in the bootstrap file. Registered services: `Pages\Admin`, `Pages\Client`, `Base\Enqueue`, `Base\SettingsLink`.

**PSR-4 namespace:** `LHWooComgate\` → `./src`.

**Gateway composition (`src/Gateway/`):** the WooCommerce gateway is split into collaborators rather than one monolithic class:
- `ComgateGateway extends WC_Payment_Gateway` — the WooCommerce-facing entry point (id `lh_woo_comgate`). Constructs and delegates to the two helpers below; registers the option-save, return, and notify hooks.
- `ComgateGatewayAdmin` — owns `get_form_fields()` (settings shown in WooCommerce → Settings → Payments).
- `ComgateGatewayPayment` — all payment logic: `process_payment()`, the synchronous `handle_return()`, and the asynchronous `handle_notify()`. Builds the SDK `Payment` entity and maps Comgate status codes to WooCommerce order states.
- `ComgateClientFactory` — builds a configured `Comgate\SDK\Client` from merchant credentials; `isAvailable()` guards against the SDK being absent. Hands the SDK a `ComgateLogger`.
- `ComgateLogger` - PSR-3 logger that forwards plugin and SDK records to `wc_get_logger()` (source `lh-woo-comgate`, WooCommerce → Status → Logs) and masks the API secret, which Comgate echoes back in status responses and notifications - literally by the configured value (any dump format) and by a `secret=` / `"secret":` pattern for other values. The gateway builds it and injects it into `ComgateGatewayPayment`. Debug, info and notice records are kept only with the gateway's **Debug log** setting on (default off), since they carry customer names and e-mails; warnings and errors are always logged. Its PSR-3 methods are deliberately untyped so any psr/log 1.x-3.x loaded by another plugin still satisfies the interface.

**Gateway registration flow:** `Pages\Client::register()` hooks `woocommerce_payment_gateways` to append `ComgateGateway`, and shows an admin notice when the Comgate SDK is missing (`has_official_comgate_sdk()` checks class existence, `Composer\InstalledVersions`, and the `vendor/` filesystem as fallbacks). The gateway only depends on `WC_Payment_Gateway` existing at runtime.

**Callback endpoints (registered via `woocommerce_api_*`):**
- Return URL: `<site>/wc-api/lh_woo_comgate_return` — user redirected back here after payment; verifies status and redirects to the order-received page.
- Notify URL: `<site>/wc-api/lh_woo_comgate_notify` — server-to-server status notification; responds with HTTP status codes Comgate expects (`200 OK`, `400`, `404`, `502`).

**Order ↔ transaction mapping:** the Comgate transaction id is stored on the order as meta `_lh_woo_comgate_trans_id`. Lookups use `find_order_by_transaction()` (by that meta) with a fallback to `find_order_by_reference()` (Comgate `refId` = WooCommerce order id) — this fallback exists to detect and ignore **stale transactions** for an order that has since been paid via a newer transaction.

## Conventions

- Currency is hardcoded to CZK and the preselected payment method to card (`PaymentMethodCode::CARD_CARD_CZ_COMGATE`) in `ComgateGatewayPayment::build_sdk_payment()`. The payer can still switch to any other method enabled for the merchant on the Comgate page (bank buttons, QR, deferred payments).
- User-facing strings use the `lh-woo-comgate` text domain via `__()` / `esc_html__()` etc.
- Diagnostics go through `ComgateLogger`, never `error_log()` or a file in the plugin directory: the plugin root is web-accessible, and the old `comgate.log` exposed the API secret. `Pages\Client::remove_legacy_log()` deletes that file on `init` where an older version left it, and an admin notice asks for manual deletion and a new secret if it cannot.
- `Base\Database`, `Utils\Utils`, `Pages\Admin`, and the `templates/` files are present but currently empty placeholders. Note `uninstall.php` calls `Database::deleteTables()`, which does not yet exist — implement it before relying on uninstall cleanup.
