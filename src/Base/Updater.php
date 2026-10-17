<?php

namespace LHWooComgate\Base;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * Offers plugin updates from GitHub Releases in Dashboard > Updates.
 */
class Updater extends BaseController {

    const REPOSITORY = 'https://github.com/luboshilgert/lh-woo-comgate/';

    /**
     * @return void
     */
    public function register() {
        if (!class_exists(PucFactory::class)) {
            return;
        }

        $checker = PucFactory::buildUpdateChecker(
            self::REPOSITORY,
            $this->plugin_path . 'lh-woo-comgate.php',
            'lh-woo-comgate'
        );

        $checker->setBranch('main');

        // The release ZIP carries vendor/ and the right root folder; the generated source archive has neither.
        $api = $checker->getVcsApi();
        if ($api && method_exists($api, 'enableReleaseAssets')) {
            $api->enableReleaseAssets();
        }
    }
}
