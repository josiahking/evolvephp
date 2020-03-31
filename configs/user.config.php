<?php

/**
 * User custom config
 * Define all your global variable/const here
 */
define('BASE_ERROR_PAGE_404',BASE_URL."error/404");
define('DEFAULT_ROUTE',['EvolvePhpComponent\error\controllers\ErrorController','pageNotFound']);
define('SITE_NAME', '');
define('SITE_SHORT_NAME', '');
define('SITE_EMAIL', '');
define('PUBLIC_KEY', "");
define('PUBLIC_KEY_HASH',hash('sha256',PUBLIC_KEY));