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
     * invoke url object
     * @param string $url
     * @param bool $absolute
     * @return string
     */
    public function __invoke(string $url,bool $absolute = false,bool $return = false) {
        if($absolute & $return == false){
            $this->goto($url);
        }
        if($absolute & $return){
            return $url;
        }
        return $this->returnFromBase($url);
    }

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
    
    public function getCurrentUrl(): string
    {
        $uri = $_SERVER['REQUEST_URI'];
        $protocol = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off') || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $url = $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        return $url;
    }
}
