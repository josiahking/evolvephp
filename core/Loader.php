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
 * Router Class
 *
 * This class can serve as an autoloader should the need arise
 * Loads files as required
 */
class Loader {
    
    public static $instance = null;
    
    
    public static function getInstance(){
        return self::$instance ?? self::$instance = new static();
    }
    
    public static function loadFile(string $path,bool $return = false) 
    {
        if($path != ''){
            $file_name = "";
            $class_name = ltrim($path, '\\');
            if ($lastNsPos = strripos($path, '\\')) {
                $namespace = substr($path, 0, $lastNsPos);
                $class_name = substr($path, $lastNsPos + 1);
                $file_name = str_replace('\\', DIRECTORY_SEPARATOR, $namespace) . DIRECTORY_SEPARATOR;
            }
            $file_name .= str_replace(' ', DIRECTORY_SEPARATOR, $class_name) . ".php";

            if($return){
                return require $file_name;
            }
            require_once $file_name;
        }
        else{
            throw new InvalidArgumentException('The path to the file is empty therefore can not be autoloaded');
        }
    }
    
    public static function loaderFactory(string $path, string $class)
    {
        self::loadFile($path);
        if(!class_exists($class)){
            throw new InvalidArgumentException("Class not found. It might also be that the file is not properly loaded.");
        }
        return new $class();
    }


    public static function loaderProcessor(string $path,string $class,string $method = "",array $param = null,$callback = null)
    {
        self::loadFile($path);
        if(!class_exists($class)){
            throw new InvalidArgumentException("Class not found. It might also be that the file is not properly loaded.");
        }
        $obj = new $class();
        if(!empty($method)){
            if(method_exists($obj, $method)){
                if(!empty($param)){
                    if(is_array($param)){
                        $args = implode(',',$param);
                        $controller->$method($args);
                    }
                    else{
                        $controller->$method($param);
                    }
                }
                else{
                    $controller->$method();
                }
            }
        }
        if(!empty($callback)){
            if(is_array($callback)){
                if(count($callback > 4)){
                    throw new InvalidArgumentException("Callback argument exceeds expected number. The argument should be similar to ".__METHOD__);
                }
                if(count($callback) == 1){
                    $callback[0]();
                }
                elseif(count($callback) == 2){
                    self::loaderProcessor($callback[0], $callback[1]);
                }
                elseif(count($callback) == 3){
                    self::loaderProcessor($callback[0], $callback[1],$callback[2]);
                }
                else{
                    self::loaderProcessor($callback[0], $callback[1],$callback[2], $callback[3]);
                }
            }
            else{
                if(is_callable($callback)){
                    $callback();
                }
            }
        }
    }
}
