<?php

namespace LHWooComgate\Base;
use \LHWooComgate\Base\BaseController;

/**
 * Description of Enqueue
 *
 * @author Lubos Hilgert <luboshilgert@gmail.com>
 */
class Enqueue extends BaseController {

    public function register() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue'));
    }

    public function enqueue() {
        // The styles only shape the gateway's icons, which appear on checkout and the order-pay page.
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }

        $style_path = $this->plugin_path . 'assets/lh-styles.css';
        $style_ver  = file_exists($style_path) ? filemtime($style_path) : false;
        wp_enqueue_style('lh-woo-comgate', $this->plugin_url . 'assets/lh-styles.css', array(), $style_ver);
    }

}
