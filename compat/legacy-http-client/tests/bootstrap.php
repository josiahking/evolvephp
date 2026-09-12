<?php

declare(strict_types=1);

spl_autoload_register(static function ($class) {
    $prefix = 'Evolve\\Bridge\\LegacyHttp\\';

    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require $path;
    }
});
