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

namespace EvolvePhpHelper;

/**
 *This class get the time a user visit a webpage and set an expiry  time for when the session ends 
 */

class FormData 
{
    /* This set the current time */ 
    public function setTime()
    {
       $current = Carbon::now();
       return $current;    
    }
    
    /* Set an expiry time for 24 hours */
    public function SetExpiry(){
    
    $expires = formatDate()->addHours(24);

    return $expires;
}
