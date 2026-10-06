<?php

declare(strict_types=1);

namespace Evolve\Insight\Dashboard;

use Evolve\Http\Routing\RouteMatch;
use Evolve\I18n\LocalizationContext;
use Evolve\I18n\Translator;
use Evolve\Insight\Access\DiagnosticAccessDenied;
use Evolve\Insight\Query\DiagnosticQueryCursorUnavailable;
use Evolve\Insight\Query\DiagnosticQueryService;
use Evolve\View\ViewRenderer;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class DashboardHandler implements RequestHandlerInterface
{
    public function __construct(
        private DiagnosticQueryService $queries,
        private ResponseFactoryInterface $responses,
        private ViewRenderer $renderer,
        private Translator $translator,
        private LocalizationContext $context,
        private string $prefix,
        private bool $detail,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->detail ? $this->detail($request) : $this->list($request);
    }

    private function list(ServerRequestInterface $request): ResponseInterface
    {
        $parameters = $request->getQueryParams();
        try {
            $query = (new DashboardQueryParser())->parse($parameters);
        } catch (\InvalidArgumentException) {
            return $this->error(400, 'invalid_request');
        }

        try {
            $page = $this->queries->query($query);
        } catch (DiagnosticAccessDenied) {
            return $this->error(403, 'forbidden');
        } catch (DiagnosticQueryCursorUnavailable) {
            return $this->error(400, 'invalid_request');
        }

        $filters = [
            'page_size' => (string) $query->pageSize(),
            'execution_kind' => $query->executionKind() ?? '',
            'category' => $query->diagnosticCategory() ?? '',
            'name' => $query->diagnosticName() ?? '',
        ];
        $nextUrl = null;
        if ($page->nextCursor() !== null) {
            $active = array_filter($filters, static fn(string $value): bool => $value !== '');
            $active['cursor'] = $page->nextCursor();
            $nextUrl = $this->prefix . '?' . http_build_query($active, '', '&', PHP_QUERY_RFC3986);
        }
        $body = $this->renderer->render('insight::list', [
            'prefix' => $this->prefix,
            'items' => $page->items(),
            'filters' => $filters,
            'nextUrl' => $nextUrl,
            'labels' => $this->labels(),
        ]);
        return $this->html(200, $body);
    }

    private function detail(ServerRequestInterface $request): ResponseInterface
    {
        $match = $request->getAttribute(RouteMatch::class);
        if (!$match instanceof RouteMatch || !isset($match->parameters()['execution'])) {
            return $this->error(400, 'invalid_request');
        }
        $identifier = rawurldecode($match->parameters()['execution']);
        if ($identifier === '' || strlen($identifier) > 512 || preg_match('/[\x00-\x1F\x7F]/', $identifier) === 1) {
            return $this->error(400, 'invalid_request');
        }

        try {
            $snapshot = $this->queries->find($identifier);
        } catch (DiagnosticAccessDenied) {
            return $this->error(403, 'forbidden');
        }
        if ($snapshot === null) {
            return $this->error(404, 'not_found');
        }
        return $this->html(200, $this->renderer->render('insight::detail', [
            'prefix' => $this->prefix,
            'snapshot' => $snapshot,
            'labels' => $this->labels(),
        ]));
    }

    private function error(int $status, string $key): ResponseInterface
    {
        return $this->html($status, $this->renderer->render('insight::error', [
            'message' => $this->translator->translate('insight::' . $key, $this->context),
            'prefix' => $this->prefix,
        ]));
    }

    /** @return array<string, string> */
    private function labels(): array
    {
        $labels = [];
        foreach (['title', 'filter', 'page_size', 'execution_kind', 'category', 'name', 'apply', 'execution', 'observations', 'entries', 'dropped_observations', 'dropped_entries', 'next', 'back', 'outcome', 'error_type', 'reuse_decision', 'attributes', 'empty'] as $key) {
            $labels[$key] = $this->translator->translate('insight::' . $key, $this->context);
        }
        return $labels;
    }

    private function html(int $status, string $body): ResponseInterface
    {
        $response = $this->responses->createResponse($status)
            ->withHeader('Content-Type', 'text/html; charset=UTF-8')
            ->withHeader('Cache-Control', 'no-store');
        $response->getBody()->write($body);
        return $response;
    }
}
