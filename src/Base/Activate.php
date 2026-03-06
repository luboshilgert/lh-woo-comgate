<?php
namespace LHWooComgate\Base;
use LHWooComgate\Base\Database;

class Activate{
    
    public static function activate(){
        $database = new Database();
        $database->createTables();
        
    }
    
}
