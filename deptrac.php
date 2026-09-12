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
            'packages/bridge-laravel/src',
            'packages/bridge-symfony/src',
            'packages/bridge-remote/src',
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
            $bridgeLaravel = Layer::withName('BridgeLaravel')->collectors(
                DirectoryConfig::create('packages/bridge-laravel/src/.*'),
            ),
            $bridgeSymfony = Layer::withName('BridgeSymfony')->collectors(
                DirectoryConfig::create('packages/bridge-symfony/src/.*'),
            ),
            $bridgeRemote = Layer::withName('BridgeRemote')->collectors(
                DirectoryConfig::create('packages/bridge-remote/src/.*'),
            ),
            $laravelHost = Layer::withName('LaravelHost')->collectors(
                ClassLikeConfig::create('^Illuminate\\(Contracts\\Auth|Http)\\.*'),
            ),
            $symfonyHost = Layer::withName('SymfonyHost')->collectors(
                ClassLikeConfig::create('^Symfony\\Component\\(HttpFoundation|Security\\Core)\\.*'),
            ),
            $psrContainer = Layer::withName('PsrContainer')->collectors(
                ClassLikeConfig::create('^Psr\\Container\\.*'),
            ),
            $psrHttpMessage = Layer::withName('PsrHttpMessage')->collectors(
                ClassLikeConfig::create('^Psr\\Http\\Message\\.*'),
            ),
            $psrHttpClient = Layer::withName('PsrHttpClient')->collectors(
                ClassLikeConfig::create('^Psr\\Http\\Client\\.*'),
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
            Ruleset::forLayer($bridgeLaravel)->accesses($bridgeContracts, $bridgePsr, $psrHttpMessage, $laravelHost),
            Ruleset::forLayer($bridgeSymfony)->accesses($bridgeContracts, $bridgePsr, $psrHttpMessage, $symfonyHost),
            Ruleset::forLayer($bridgeRemote)->accesses($bridgeContracts, $bridgePsr, $psrHttpMessage, $psrHttpClient, $psrHttpServer),
            Ruleset::forLayer($laravelHost),
            Ruleset::forLayer($symfonyHost),
            Ruleset::forLayer($psrContainer),
            Ruleset::forLayer($psrHttpMessage),
            Ruleset::forLayer($psrHttpClient),
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
