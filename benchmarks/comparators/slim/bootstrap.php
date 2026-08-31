<?php

declare(strict_types=1);

$autoload = __DIR__ . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'SlimComparatorFixture.php';

return new Benchmark\Slim\SlimComparatorFixture();
