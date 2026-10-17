<?php

namespace LHWooComgate;

/**
 * Description of Init
 *
 * @author Lubos Hilgert <luboshilgert@gmail.com>
 */
final class Init {
    /**
     * Seznam vsech trid
     * @return type
     */
    public static function get_services(){   
        return [
        Pages\Client::class,
        Base\Compatibility::class,
        Base\Enqueue::class,
        Base\SettingsLink::class,
        Base\Updater::class
        ];
    }
/**
 * Loop skrze classy a inicializace
 */
    public static function register_services(){
        foreach(self::get_services() as $class){
            $service = self::instantiate($class);
            if(method_exists($service, 'register')){
                $service->register();
            }
        }
    } 
    
    private static function instantiate($class){
        $service = new $class;
        return $service;
    }
}
