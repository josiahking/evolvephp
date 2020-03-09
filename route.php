<?php

require_once 'vendor/autoload.php';

require 'configs/Define.php';
require BASE_DIR . 'configs/CoreAppLoader.php';

use core\AppRouter;
use components\error\controllers\ErrorController;
//init router
$router = new AppRouter();

//start routing
$url = @preg_replace('/[^a-zA-Z0-9\/&=-]/', '', $_GET['url']);
$url = rtrim($url, '/');
$exUrl = explode('/', $url);
$numExUrl = count($exUrl);

//routing begins
if ($exUrl[0] == '') {
    $error = new ErrorController();
    $error->pageNotFound();
} else if ($numExUrl == 1) {
    if (preg_match('/[a-zA-Z]/', $exUrl[0])) {
        if (is_readable('components/' . $exUrl[0] . '/route.php')) {
            include 'components/' . $exUrl[0] . '/route.php';
        } else {
            $error = new ErrorController();
            $error->pageNotFound();
        }
    } else {
        $error = new ErrorController();
        $error->pageNotFound();
    }
} elseif ($numExUrl == 2) {
    if (preg_match('/[a-zA-Z]/', $exUrl[0]) && preg_match('/[a-zA-Z0-9-]/', $exUrl[1])) {
        if (is_readable('components/' . $exUrl[0] . '/route.php')) {
            include 'components/' . $exUrl[0] . '/route.php';
        } else {
            $error = new ErrorController();
            $error->pageNotFound();
        }
    } else {
        $error = new ErrorController();
        $error->pageNotFound();
    }
} else if ($numExUrl == 3) {
    if (preg_match('/[a-zA-Z]/', $exUrl[0]) && preg_match('/[a-zA-Z0-9-]/', $exUrl[1]) && preg_match('/[a-zA-Z0-9]/', $exUrl[2])) {
        if (file_exists('components/' . $exUrl[0] . '/route.php')) {
            include 'components/' . $exUrl[0] . '/route.php';
        } else {
            $error = new ErrorController();
            $error->pageNotFound();
        }
    } else {
        $error = new ErrorController();
        $error->pageNotFound();
    }
} else if ($numExUrl == 4) {
    if (preg_match('/[a-zA-Z]/', $exUrl[0]) && preg_match('/[a-zA-Z]/', $exUrl[1]) && preg_match('/[a-zA-Z0-9]/', $exUrl[2]) && preg_match('/[a-zA-Z0-9-]/', $exUrl[3])) {
        if (file_exists('components/' . $exUrl[0] . '/route.php')) {
            include 'components/' . $exUrl[0] . '/route.php';
        } else {
            $error = new ErrorController();
            $error->pageNotFound();
        }
    } else {
        $error = new ErrorController();
        $error->pageNotFound();
    }
} else if ($numExUrl == 5) {
    if (preg_match('/[a-zA-Z]/', $exUrl[0]) && preg_match('/[a-zA-Z]/', $exUrl[1]) && preg_match('/[a-zA-Z0-9]/', $exUrl[2]) && preg_match('/[a-zA-Z0-9-]/', $exUrl[3]) && preg_match('/[a-zA-Z0-9-]/', $exUrl[4])) {
        if (file_exists('components/' . $exUrl[0] . '/route.php')) {
            include 'components/' . $exUrl[0] . '/route.php';
        } else {
            $error = new ErrorController();
            $error->pageNotFound();
        }
    } else {
        $error = new ErrorController();
        $error->pageNotFound();
    }
} else {
    $error = new ErrorController();
    $error->pageNotFound();
}