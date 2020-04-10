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
use EvolvePhpCore\Loader;
/**
 * View
 * Renders view templates and layouts
 * Using view config
 * @extends ApplicationAbstract
 */
class View extends ApplicationAbstract
{
    
    public static function loadView(string $layout = "default",string $viewFilePath,$data = null)
    {
        $viewConfig = Loader::loadFile(BASE_DIR.'configs/view.config', true);
        if(!is_array($viewConfig)){
            ExceptionFactory::getInstance()->triggerException("View config file is not found","invalid_argument");
        }
        $layoutConfig = $viewConfig['view_layout'];
        if(!array_key_exists($layout, $layoutConfig)){
            ExceptionFactory::getInstance()->triggerException("Layout config is not found","domain");
        }
        foreach ($layoutConfig[$layout] as $file){
            if(stristr($file,'%placeholder%')){
                $replacePlaceholder = str_replace('%placeholder%', '', $file);
                $path = $replacePlaceholder.$viewFilePath;
                if(stristr($path,'%layout%')){
                    $path = str_replace('%layout%/', '', $path);
                    if (file_exists(LAYOUTS_DIR.$path)) {
                        include_once LAYOUTS_DIR.$path;
                    } else {
                        //throw new \DomainException("The view file path, was not found on this server");
                        ExceptionFactory::getInstance()->triggerException("The view file path, was not found on this server","domain");
                    }
                }
                elseif(stristr($path,'%component%')){
                    $path = str_replace('%component%/', '', $path);
                    if (file_exists(COMPONENTS_DIR.$path)) {
                        include_once COMPONENTS_DIR.$path;
                    } else {
                        //throw new \DomainException("The view file path, was not found on this server");
                        ExceptionFactory::getInstance()->triggerException("The view file path, was not found on this server","domain");
                    }
                }
                else{
                    if (file_exists(BASE_DIR.$path)) {
                        include_once BASE_DIR.$path;
                    } else {
                        //throw new \DomainException("The view file path, was not found on this server");
                        ExceptionFactory::getInstance()->triggerException("The view file path, was not found on this server","domain");
                    }
                }
            }
            else{
                if(stristr($file,'%layout%')){
                    $file = str_replace('%layout%/', '', $file);
                    if (file_exists(LAYOUTS_DIR.$file)) {
                        include_once LAYOUTS_DIR.$file;
                    } else {
                        //throw new \DomainException("The view file path, was not found on this server");
                        ExceptionFactory::getInstance()->triggerException("The view file path, was not found on this server","domain");
                    }
                }
                elseif(stristr($file,'%component%')){
                    $file = str_replace('%component%/', '', $file);
                    if (file_exists(COMPONENTS_DIR.$file)) {
                        include_once COMPONENTS_DIR.$file;
                    } else {
                        //throw new \DomainException("The view file path, was not found on this server");
                        ExceptionFactory::getInstance()->triggerException("The view file path, was not found on this server","domain");
                    }
                }
                else{
                    if (file_exists(BASE_DIR.$file)) {
                        include_once BASE_DIR.$file;
                    } else {
                        //throw new \DomainException("The view file path, was not found on this server");
                        ExceptionFactory::getInstance()->triggerException("The view file path, was not found on this server","domain");
                    }
                }
            }
        }
        self::sessionHandler()->unsetFlashMessage();
    }
}
