<?php

/**
 * @see       https://github.com/josiahking/evolvephp for read me and documentation
 * @copyright https://github.com/josiahking/evolvephp/blob/master/COPYRIGHT.md
 * @license   https://github.com/josiahking/evolvephp/blob/master/LICENSE.md
 * @package EvolvePHP
 * @author 
 * @link Documentation on this file
 * @since Version 1.0
 * @filesource
 */

namespace EvolvePhpCore;
/**
 * LogFactory Class
 *
 * This class factory provides access to using multiple logger
 */

class LogFactory 
{
    
    /**
     * providers
     * log providers or handlers
     * @var array
     */
    public $providers = [
        'default_file' => 'EvolvePhpCore\log\Apachelog4phpFileLogger',
    ];
    
    /**
     * getInstance
     * get instance of this class
     * @return \static
     */
    public static function getInstance()
    {
        return new static();
    }    

    /**
     * create 
     * Create log handler/provider object using specified provider
     * @param string $provider
     * @return LogWriterInterface
     */
    public static function create(string $provider) : log\LogWriterInterface
    {
        foreach(self::getInstance()->providers as $k_log => $v_log){
            if($k_log == $provider){
                return new $v_log;
            }
        }
        throw new InvalidArgumentException("Log provider class is not found");
    }
    
}