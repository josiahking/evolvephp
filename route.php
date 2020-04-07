<?php
/**
 * Routing starts
 * require core config files the application needs to run
 */

require __DIR__.'/vendor/autoload.php';
require __DIR__.'/configs/application.config.php';
require __DIR__.'/configs/user.config.php';
require __DIR__.'/configs/ini.config.php';
require __DIR__.'/core/CoreFileLoader.php';

$ex = new EvolvePhpCore\ExceptionFactory();

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

$route = preg_replace('/[^a-zA-Z0-9\/&=-]/', '', $_SERVER['PATH_INFO'] ?? (isset($_GET['route']) ? $_GET['route'] : "" ));
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
        $defaultPageClass = DEFAULT_ROUTE[0];
        $defaultPageClassObj = new $defaultPageClass;
        if (preg_match('/[a-zA-Z0-9-]/', $erVal)) {
            if (is_readable(COMPONENTS_DIR.$erVal.DS.'route.php')) {
                include_once COMPONENTS_DIR.$erVal.DS.'route.php';
            } elseif(method_exists($defaultPageClassObj, $erVal)) {
                $defaultPageClassObj->$erVal();
            } else {
                ErrorController::pageNotFound();
            }
            return;
        }
    }
    break;
}
ErrorController::pageNotFound();