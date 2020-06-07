<?php

/**
 * Application default config
 * Do not modify this file, use the user.config.php for all your user custom config
 * Before modifying this file, ensure you know what you are doing
 */
if(isset($_SERVER['REQUEST_SCHEME'])){
    define('BASE_URL',preg_match('/https|HTTPS/',$_SERVER['REQUEST_SCHEME']) == 1 ? "https://".$_SERVER['HTTP_HOST'].'/' : "http://".$_SERVER['HTTP_HOST'].'/');
}
elseif(isset($_SERVER['HTTPS'])){
    define('BASE_URL',$_SERVER['HTTPS'] == "on" ? "https://".$_SERVER['HTTP_HOST'].'/' : "http://".$_SERVER['HTTP_HOST'].'/');
}
else{
    define('BASE_URL',"http://".$_SERVER['HTTP_HOST'].'/');
}
define('ACCESS_ALLOWED', TRUE);
define('DS', DIRECTORY_SEPARATOR);
define('BASE_DIR',__DIR__.DS.'..'.DS);
define('VENDOR_DIR', BASE_DIR.'vendor'.DS);
define('COMPONENTS_DIR',BASE_DIR.'components'.DS);
define('HELPERS_DIR',BASE_DIR.'helpers'.DS);
define('CONFIGS_DIR', __DIR__.DS);
define('PUBLIC_DIR',BASE_DIR.'public'.DS);
define('CAPTCHA_IMG_DIR',PUBLIC_DIR.'captcha'.DS);
define('ASSETS_DIR',PUBLIC_DIR.'assets'.DS);
define('LAYOUTS_DIR',PUBLIC_DIR.'layouts'.DS);
define('ASSETS',BASE_URL.'public/assets/');
define('ASSETS_FONTS',ASSETS.'fonts/');
define('ASSETS_JS',ASSETS.'js/');
define('ASSETS_CSS',ASSETS.'css/');
define('ASSETS_IMG',ASSETS.'images/');