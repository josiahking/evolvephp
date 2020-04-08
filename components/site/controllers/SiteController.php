<?php
/**
 * ErrorController
 * Default route error handler
 */
namespace EvolvePhpComponent\site\controllers;

use EvolvePhpCore\View;
use EvolvePhpCore\ApplicationAbstract;

if (!defined('ACCESS_ALLOWED')) {
    die('No direct access allowed.');
}

class SiteController extends ApplicationAbstract
{

    public function home()
    {
        $this->getInstance()->sessionHandler()->setSession('page_title', SITE_NAME.' - Page not found');
        View::loadView('default','home.php',[]);
    }
}
