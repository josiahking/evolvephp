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
use EvolvePhpCore\ExceptionFactory;
use EvolvePhpCore\Session;
/**
 * ApplicationAbstract
 *
 * This is the base abstract class for the entire evolvephp  framework package
 * Most core class and all components class controllers should use as parent by extending it
 */

class ApplicationAbstract 
{
    public $session = null;
    
    public static function sessionHandler() : \EvolvePhpCore\Session
    {
        $session = new Session();
        self::getInstance()->session = $session;
        return $session;
    }
    
    public static function getInstance()
    {
        return new static();
    }
    
}
