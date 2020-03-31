<?php
/**
 * INI settings before application runs
 * Before modifying this file, ensure you know what you are doing
 */
//debelopment mode ini
ini_set('error_reporting',E_ALL);//E_ERROR || E_STRICT
ini_set("log_errors", 1);
ini_set("error_log", __DIR__."/logs/php-error.log");
//application ini
if (ini_get('session.use_only_cookies') != 1) {
    ini_set('session.use_only_cookies', 1);
}
$timeZone = date_default_timezone_get();
if($timeZone != 'Africa/Lagos'){
    date_default_timezone_set('Africa/Lagos');
}
//set session max time in seconds
if(ini_get("session.gc_maxlifetime") != 7200){
    ini_set("session.gc_maxlifetime", 7200);
}
