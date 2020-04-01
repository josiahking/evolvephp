<?php
/**
 * Application starts here
 */
/**
 * Allow request coming from the same origin (CORS)
 */
if (isset($_SERVER['HTTP_ORIGIN'])) {
    // Decide if the origin in $_SERVER['HTTP_ORIGIN'] is one
    // you want to allow, and if so:
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");//
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');    // cache for 1 day
}

// Access-Control headers are received during OPTIONS requests from an external client
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {

    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    }

    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
        header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
    }
    exit(0);
}
/**
 * Check if php version is compatible or supported
 */
if (version_compare(phpversion(), '7.1.0', '<') === true) {
    $response = '<div style="font:12px/1.35em arial, helvetica, sans-serif;">';
    $response .= '<div style="margin:0 0 25px 0; border-bottom:1px solid #ccc;">';
    $response .= '<h3 style="margin:0; font-size:1.7em; font-weight:normal; text-transform:none; text-align:left; color:#2f2f2f;">';
    $response .= 'Whoops, it looks like you have an invalid PHP version.</h3></div><p>This application supports PHP 5.4.0 or newer.</p>';
    $response .= '</div>';
    exit(0);
}
//include router
require __DIR__.'/route.php';
