<?php
/**
 * Routing starts
 * require core config files the application needs to run
 */

require __DIR__.'/vendor/autoload.php';
require __DIR__.'/configs/application.config.php';
require __DIR__.'/configs/user.config.php';
require __DIR__.'/configs/ini.config.php';

if(DEBUG === TRUE){
    $_SERVER['APPLICATION_ENV'] = "development";
}
else{
    $_SERVER['APPLICATION_ENV'] = "production";
}

use EvolvePhpComponent\error\controllers\ErrorController;
/**
 * Start routing
 * Replace route that doesn't match the allowed characters
 * Clean the route for irregularities
 * Check if route matches defined pattern and assign them to the right component
 */

$route = preg_replace('/[^a-zA-Z0-9\/&=-]/', '', $_SERVER['PATH_INFO'] ?? isset($_GET['route']) ? $_GET['route'] : "" );
$route = rtrim(ltrim($route,'/'), '/');
$explodeRoute = explode('/', $route);
foreach ($explodeRoute as $erKey => $erVal){
    if($erVal == ""){
        $defaultPageClass = DEFAULT_ROUTE[0];
        $defaultPageMethod = DEFAULT_ROUTE[1];
        $defaultPageClassObj = new $defaultPageClass;
        $defaultPageClassObj->$defaultPageMethod();
        return;
    }
    else{
        if (preg_match('/[^a-zA-Z0-9-]/', $erVal)) {
            if (is_readable(COMPONENTS.$erVal.DS.'route.php')) {
                include_once COMPONENTS.$erVal.DS.'route.php';
            } else {
                ErrorController::pageNotFound();
            }
            return;
        }
    }
}
ErrorController::pageNotFound();