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
        add_action('admin_enqueue_scripts', array($this, 'enqueueAdmin'));
    }

    public function enqueue() {
        $style_path = $this->plugin_path . 'assets/lh-styles.css';
        $style_ver  = file_exists($style_path) ? filemtime($style_path) : false;
        wp_enqueue_style('mystyles', $this->plugin_url . 'assets/lh-styles.css', array(), $style_ver);
    }
    
    public function enqueueAdmin(){
        wp_enqueue_style('mystyles', $this->plugin_url.'assets/lh-admin-styles.css');
    }

}
