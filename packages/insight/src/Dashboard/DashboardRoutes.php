<?php

declare(strict_types=1);

namespace Evolve\Insight\Dashboard;

use Evolve\Http\Routing\Route;
use Evolve\Http\Routing\RouteCollection;
use Evolve\I18n\CatalogTranslator;
use Evolve\I18n\FilesystemMessageCatalog;
use Evolve\I18n\LocalizationContext;
use Evolve\I18n\LocalizationPolicy;
use Evolve\I18n\MessageSource;
use Evolve\I18n\PlaceholderMessageFormatter;
use Evolve\I18n\Translator;
use Evolve\Insight\Query\DiagnosticQueryService;
use Evolve\View\FilesystemViewPathResolver;
use Evolve\View\Native\NativePhpViewRenderer;
use Evolve\View\ViewRenderer;
use Evolve\View\ViewSource;
use Psr\Http\Message\ResponseFactoryInterface;

final readonly class DashboardRoutes
{
    public static function native(
        DiagnosticQueryService $queries,
        ResponseFactoryInterface $responses,
        LocalizationContext $context,
        DashboardExposure $exposure,
        string $prefix = '/__evolve/insight',
    ): RouteCollection {
        $root = dirname(__DIR__, 2) . '/resources';
        $renderer = new NativePhpViewRenderer(new FilesystemViewPathResolver([new ViewSource('insight', $root . '/views')]));
        $fallbackChain = $context->fallbackChain();
        if (!in_array('en', $fallbackChain, true)) {
            $fallbackChain[] = 'en';
        }
        $effectiveContext = new LocalizationContext($context->locale(), $fallbackChain, $context->timezone());
        $policy = new LocalizationPolicy($fallbackChain, 'en', [], $context->timezone());
        $translator = new CatalogTranslator(new FilesystemMessageCatalog([new MessageSource('insight', $root . '/messages')], $policy), new PlaceholderMessageFormatter());

        return self::compose($queries, $responses, $renderer, $translator, $effectiveContext, $exposure, $prefix);
    }

    public static function compose(
        DiagnosticQueryService $queries,
        ResponseFactoryInterface $responses,
        ViewRenderer $renderer,
        Translator $translator,
        LocalizationContext $context,
        DashboardExposure $exposure,
        string $prefix = '/__evolve/insight',
    ): RouteCollection {
        self::validatePrefix($prefix);
        return new RouteCollection([
            new Route(['GET'], $prefix, new DashboardHandler($queries, $responses, $renderer, $translator, $context, $prefix, false)),
            new Route(['GET'], $prefix . '/{execution}', new DashboardHandler($queries, $responses, $renderer, $translator, $context, $prefix, true)),
        ]);
    }

    private static function validatePrefix(string $prefix): void
    {
        if ($prefix === '/' || preg_match('#\A/(?:[A-Za-z0-9._~-]+)(?:/[A-Za-z0-9._~-]+)*\z#D', $prefix) !== 1) {
            throw new \InvalidArgumentException('Invalid dashboard route prefix.');
        }
    }
}
