<?php

/**
 * Application default config
 * Do not modify this file, use the user.config.php for all your user custom config
 * Before modifying this file, ensure you know what you are doing
 */
define('BASE_URL',preg_match('/https|HTTPS/',$_SERVER['SERVER_PROTOCOL']) == 1 ? "https://".$_SERVER['HTTP_HOST'].'/' : "http://".$_SERVER['HTTP_HOST'].'/');
define('ACCESS_ALLOWED', TRUE);
define('DS', DIRECTORY_SEPARATOR);
define('BASE_DIR',__DIR__.DS.'..'.DS);
define('VENDOR', BASE_DIR.'vendor'.DS);
define('COMPONENTS',BASE_DIR.'components'.DS);
define('HELPERS',BASE_DIR.'helpers'.DS);
define('PUBLIC_DIR',BASE_DIR.'public'.DS);
define('CAPTCHA_IMG_DIR',PUBLIC_DIR.'captcha'.DS);
define('PUBLIC_ASSETS',PUBLIC_DIR.'assets'.DS);
define('ASSETS',BASE_URL.'assets/');
define('ASSETS_FONTS',ASSETS.'fonts/');
define('ASSETS_JS',ASSETS.'js/');
define('ASSETS_CSS',ASSETS.'css/');
define('ASSETS_IMG',ASSETS.'images/');