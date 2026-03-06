<?php

namespace LHWooComgate\Base;

require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

class Database {

    private $db;
    private $prefix;

    public function __construct() {
        global $wpdb;
        $this->db = $wpdb;
        $this->prefix = $this->db->prefix;
    }
    
    public function createTables(){
        
    }
    

}
