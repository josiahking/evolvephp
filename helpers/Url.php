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
 * Url Helper
 *
 * This class helps perform simple routing url for the framework
 */
class Url {
    
    /**
     * baseUrl
     * get the base url set from the application config
     * @return string
     * @throws \DomainException
     */
    public function baseUrl() :string
    {
        if(!empty(BASE_URL)){
            return BASE_URL;
        }
        throw new \DomainException('The base URL for this application is not set in the application config');
    }
    
    /**
     * returnFromBase
     * returns the full url 
     * @param string $url
     * @return string
     */
    public function returnFromBase(string $url) : string
    {
        return $this->baseUrl().$url;
    }
    
    /**
     * goto
     * redirect to the specificed url/location
     * @param string $url
     */
    public function goto(string $url)
    {
        header('Location: '.$url);
        exit();
    }
    
    /**
     * returnPrevious
     * returns the previous page or the referring webpage if available
     * returns baseUrl if no previous is found
     * @return type
     */
    public function returnPrevious()
    {
        if(isset($_SERVER['HTTP_REFERER'])){
            return $_SERVER['HTTP_REFERER'];
        }
        else{
            return $this->baseUrl();
        }
    }
    
    
}
