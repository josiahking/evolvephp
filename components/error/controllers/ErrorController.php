<?php
/**
 * ErrorController
 * Default route error handler
 */
namespace EvolvePhpComponent\error\controllers;

use EvolvePhpCore\View;
//use core\AppController;
//use core\AppLogger;
//use core\AppView;

if (!defined('ACCESS_ALLOWED')) {
    die('No direct access allowed.');
}

class ErrorController
{

    public function __construct()
    {
        
    }

    public static function pageNotFound()
    {
        http_response_code(404);
        View::loadView('error','404.php');
    }

    public function error(string $msg,int $code)
    {
        //http_response_code($code);
    }
}
