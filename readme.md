# LH WooCommerce Comgate Gateway

This plugin adds a Comgate card payment option to WooCommerce. It integrates with the official [comgate/sdk-php](https://github.com/comgate-payments/sdk-php) library.

## Installation

1. Copy the plugin folder to your WordPress installation under `wp-content/plugins/`.
2. Run `composer install` inside the plugin directory to download the official Comgate SDK dependency. The repository registers the Comgate PHP SDK directly from GitHub (the package is not currently published on Packagist), so Composer must be able to access `https://github.com/comgate-payments/sdk-php`. GitHub enforces an hourly rate limit for unauthenticated requests; if Composer reports that the API limit is exhausted, create a temporary personal access token (no scopes required for public repositories) and supply it to Composer as described in [Composer's authentication guide](https://getcomposer.org/doc/articles/authentication-for-private-packages.md#github-oauth).
3. Activate the **LH Woo Comgate** plugin in WordPress.
4. Navigate to **WooCommerce → Settings → Payments** and enable **Comgate**.
5. Enter your Comgate merchant ID and secret, adjust the remaining settings, and save the configuration.

### Composer FAQ

- **Why not `composer require comgate/sdk`?** The official PHP SDK is published under the package name [`comgate/sdk-php`](https://github.com/comgate-payments/sdk-php). The shorter `comgate/sdk` identifier does not exist on Packagist or GitHub, so running `composer require comgate/sdk` results in an "Unable to find package" error. The repository already defines the correct dependency in `composer.json`, so running `composer install` downloads the SDK specified there.
- **What does `composer install` do?** The command reads your `composer.json`/`composer.lock` files and downloads the listed dependencies into the local `vendor/` directory. It never uploads your code or credentials anywhere; Composer simply performs HTTP requests to fetch package archives. When the GitHub API rate limit is hit, providing a temporary personal access token only allows Composer to authenticate those download requests so it can finish retrieving the SDK.

## Callback URLs

The plugin automatically registers the following endpoints that must be accessible from the Comgate platform:

- Return URL: `<site-url>/wc-api/lh_woo_comgate_return`
- Notification URL: `<site-url>/wc-api/lh_woo_comgate_notify`

Ensure these URLs are configured in your Comgate merchant portal if required.
