<?php

declare(strict_types=1);

use Deptrac\Deptrac\Contract\Config\Collector\DirectoryConfig;
use Deptrac\Deptrac\Contract\Config\DeptracConfig;
use Deptrac\Deptrac\Contract\Config\Layer;
use Deptrac\Deptrac\Contract\Config\Ruleset;

return static function (DeptracConfig $config): void {
    $config
        ->paths(
            '../packages/contracts/src',
            '../packages/core/src',
            '../packages/http/src',
            '../packages/module/src',
            '../packages/plugin/src',
            '../packages/testing/src',
        )
        ->cacheFile(__DIR__ . '/.deptrac.cache')
        ->layers(
            $contracts = Layer::withName('Contracts')->collectors(
                DirectoryConfig::create('../packages/contracts/src/.*'),
            ),
            $core = Layer::withName('Core')->collectors(
                DirectoryConfig::create('../packages/core/src/.*'),
            ),
            $http = Layer::withName('Http')->collectors(
                DirectoryConfig::create('../packages/http/src/.*'),
            ),
            $module = Layer::withName('Module')->collectors(
                DirectoryConfig::create('../packages/module/src/.*'),
            ),
            $plugin = Layer::withName('Plugin')->collectors(
                DirectoryConfig::create('../packages/plugin/src/.*'),
            ),
            $testing = Layer::withName('Testing')->collectors(
                DirectoryConfig::create('../packages/testing/src/.*'),
            ),
        )
        ->rulesets(
            Ruleset::forLayer($contracts),
            Ruleset::forLayer($core)->accesses($contracts),
            Ruleset::forLayer($http)->accesses($contracts, $core),
            Ruleset::forLayer($module)->accesses($contracts),
            Ruleset::forLayer($plugin)->accesses($contracts),
            Ruleset::forLayer($testing)->accesses(
                $contracts,
                $core,
                $http,
                $module,
                $plugin,
            ),
        );
};
