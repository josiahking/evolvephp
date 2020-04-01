<?php
/**
 * ErrorController
 * Default route error handler
 */
namespace EvolvePhpComponent\error\controllers;

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
        echo 'error page';
//        $log = new AppLogger();
//        $logFile = $log->log404Error();
//        $logFile->info($_REQUEST);
//        $this->sessionHandler()->setSession('pageTitle', '404 Error | Page not found!');
//        AppView::errorView('error/views/404');
    }

    public function logErrorToFile(string $msg,int $code)
    {
        http_response_code($code);
//        $log = new AppLogger();
//        $logFile = $log->logFile();
//        $logFile->error($msg);
    }
}
