<?php

declare(strict_types=1);

use Deptrac\Deptrac\Contract\Config\Collector\DirectoryConfig;
use Deptrac\Deptrac\Contract\Config\Collector\ClassLikeConfig;
use Deptrac\Deptrac\Contract\Config\DeptracConfig;
use Deptrac\Deptrac\Contract\Config\Layer;
use Deptrac\Deptrac\Contract\Config\Ruleset;

return static function (DeptracConfig $config): void {
    $config
        ->paths(
            'packages/contracts/src',
            'packages/bridge-contracts/src',
            'packages/bridge-psr/src',
            'packages/core/src',
            'packages/dev-tools/src',
            'packages/http/src',
            'packages/module/src',
            'packages/plugin/src',
            'packages/testing/src',
        )
        ->cacheFile(__DIR__ . '/.deptrac.cache')
        ->layers(
            $contracts = Layer::withName('Contracts')->collectors(
                DirectoryConfig::create('packages/contracts/src/.*'),
            ),
            $bridgeContracts = Layer::withName('BridgeContracts')->collectors(
                DirectoryConfig::create('packages/bridge-contracts/src/.*'),
            ),
            $bridgePsr = Layer::withName('BridgePsr')->collectors(
                DirectoryConfig::create('packages/bridge-psr/src/.*'),
            ),
            $psrContainer = Layer::withName('PsrContainer')->collectors(
                ClassLikeConfig::create('^Psr\\Container\\.*'),
            ),
            $psrHttpMessage = Layer::withName('PsrHttpMessage')->collectors(
                ClassLikeConfig::create('^Psr\\Http\\Message\\.*'),
            ),
            $psrHttpServer = Layer::withName('PsrHttpServer')->collectors(
                ClassLikeConfig::create('^Psr\\Http\\Server\\.*'),
            ),
            $core = Layer::withName('Core')->collectors(
                DirectoryConfig::create('packages/core/src/.*'),
            ),
            $devTools = Layer::withName('DevTools')->collectors(
                DirectoryConfig::create('packages/dev-tools/src/.*'),
            ),
            $http = Layer::withName('Http')->collectors(
                DirectoryConfig::create('packages/http/src/.*'),
            ),
            $module = Layer::withName('Module')->collectors(
                DirectoryConfig::create('packages/module/src/.*'),
            ),
            $plugin = Layer::withName('Plugin')->collectors(
                DirectoryConfig::create('packages/plugin/src/.*'),
            ),
            $testing = Layer::withName('Testing')->collectors(
                DirectoryConfig::create('packages/testing/src/.*'),
            ),
        )
        ->rulesets(
            Ruleset::forLayer($contracts)->accesses($psrContainer),
            Ruleset::forLayer($bridgeContracts)->accesses($contracts),
            Ruleset::forLayer($bridgePsr)->accesses($bridgeContracts, $core, $http, $psrHttpMessage),
            Ruleset::forLayer($psrContainer),
            Ruleset::forLayer($psrHttpMessage),
            Ruleset::forLayer($psrHttpServer),
            Ruleset::forLayer($core)->accesses($contracts, $psrContainer),
            Ruleset::forLayer($devTools)->accesses($contracts, $core, $module, $plugin),
            Ruleset::forLayer($http)->accesses($contracts, $core, $psrHttpMessage, $psrHttpServer),
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
