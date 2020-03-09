<?php

/* 
 * Description The is the main file that all request pass through
 */
if (isset($_SERVER['HTTP_ORIGIN'])) {
    // Decide if the origin in $_SERVER['HTTP_ORIGIN'] is one
    // you want to allow, and if so:
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");//
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');    // cache for 1 day
}

// Access-Control headers are received during OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {

    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    }

    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
        header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
    }
    exit(0);
}

if (version_compare(phpversion(), '7.0.0', '<')===true) {
    echo  '<div style="font:12px/1.35em arial, helvetica, sans-serif;">
<div style="margin:0 0 25px 0; border-bottom:1px solid #ccc;">
<h3 style="margin:0; font-size:1.7em; font-weight:normal; text-transform:none; text-align:left; color:#2f2f2f;">
Whoops, it looks like you have an invalid PHP version.</h3></div><p>This application supports PHP 5.4.0 or newer.</p></div>';
    exit;
}
ini_set('error_reporting',E_ALL);//E_ERROR || E_STRICT
ini_set("log_errors", 1);
ini_set("error_log", "./logs/php-error.log");
if (ini_get('session.use_only_cookies') != 1) {
    ini_set('session.use_only_cookies', 1);
}
$timeZone = date_default_timezone_get();
if($timeZone != 'Africa/Lagos'){
    date_default_timezone_set('Africa/Lagos');
}
//set session max time
if(ini_get("session.gc_maxlifetime") != 7200){
    ini_set("session.gc_maxlifetime", 7200);
}
require 'route.php';
