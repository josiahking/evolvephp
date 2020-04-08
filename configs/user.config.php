<?php

/**
 * User custom config
 * Define all your global variable/const here
 */
//DO NOT REMOVE THE BELOW CONFIGS
define('DEBUG', TRUE);
define('BASE_ERROR_PAGE_404',BASE_URL."error/404");
define('DEFAULT_ROUTE',['EvolvePhpComponent\site\controllers\SiteController','home']);
define('SESSION_NAME',"EvolvePhp");//set session name
define('SESSION_EXPIRE',120);//in minutes
define('SESSION_SAVE_PATH',"");//default path is used is not set
//ADD YOURS BELOW THIS COMMENT
define('SITE_NAME', '');
define('SITE_SHORT_NAME', '');
define('SITE_EMAIL', '');
define('PUBLIC_KEY', "");
define('PUBLIC_KEY_HASH',hash('sha256',PUBLIC_KEY));