<?php
/**
 * ErrorController
 * Default route error handler
 */
namespace EvolvePhpComponent\site\controllers;

use EvolvePhpCore\View;

if (!defined('ACCESS_ALLOWED')) {
    die('No direct access allowed.');
}

class SiteController
{

    public function __construct()
    {
        
    }

    public function home()
    {
        View::loadView('default','home.php');
    }
}
