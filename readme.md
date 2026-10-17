# LH WooCommerce Comgate Gateway

This plugin adds a Comgate card payment option to WooCommerce. It integrates with the official [comgate/sdk-php](https://github.com/comgate-payments/sdk-php) library.

## Installation

1. Download `lh-woo-comgate.zip` from the latest [GitHub release](https://github.com/luboshilgert/lh-woo-comgate/releases/latest) and upload it in **Plugins → Add New → Upload Plugin**. The ZIP already contains `vendor/`.
2. Activate the **LH Woo Comgate** plugin in WordPress.
3. Navigate to **WooCommerce → Settings → Payments** and enable **Comgate**.
4. Enter your Comgate merchant ID and secret, adjust the remaining settings, and save the configuration.

New versions are offered in **Dashboard → Updates** like any other plugin (Plugin Update Checker against GitHub Releases).

### Installing from source

1. Copy the plugin folder to your WordPress installation under `wp-content/plugins/`.
2. Run `composer install` inside the plugin directory to download the official Comgate SDK ([`comgate/sdk`](https://packagist.org/packages/comgate/sdk) on Packagist) and the update checker. Package archives come from GitHub, which rate-limits anonymous requests; if Composer reports that the API limit is exhausted, supply a personal access token (no scopes required) as described in [Composer's authentication guide](https://getcomposer.org/doc/articles/authentication-for-private-packages.md#github-oauth).
3. Continue with steps 2-4 above.

### Composer FAQ

- **What does `composer install` do?** The command reads your `composer.json`/`composer.lock` files and downloads the listed dependencies into the local `vendor/` directory. It never uploads your code or credentials anywhere; Composer simply performs HTTP requests to fetch package archives. When the GitHub API rate limit is hit, providing a temporary personal access token only allows Composer to authenticate those download requests so it can finish retrieving the SDK.

## Logging

Errors are written to the WooCommerce logs (**WooCommerce → Status → Logs**, source `lh-woo-comgate`). To also log the full Comgate API communication, enable **Debug log** in the gateway settings. The API secret is masked, but the records contain customer names, e-mails and payment details, so keep it off on a live shop unless you are debugging.

## Callback URLs

The plugin automatically registers the following endpoints that must be accessible from the Comgate platform:

- Return URL: `<site-url>/wc-api/lh_woo_comgate_return`
- Notification URL: `<site-url>/wc-api/lh_woo_comgate_notify`

Ensure these URLs are configured in your Comgate merchant portal if required.
