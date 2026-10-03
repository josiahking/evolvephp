# EvolvePHP View

Experimental server-rendered view contracts and trusted native PHP renderer for EvolvePHP 2. `evolvephp/view` depends on only PHP `^8.4` and has no third-party runtime package. `ViewRenderer::render(string $view, array $data = []): string` is the engine-neutral boundary. Optional `evolvephp/view-twig` and `evolvephp/view-blade` adapters are available for explicit installation and selection; neither is a default skeleton dependency or an Insight prerequisite. Localization is deferred.

EvolvePHP 2 is pre-release, and this package is not yet independently published. The canonical source is the EvolvePHP monorepo at https://github.com/josiahking/evolvephp.

License: BSD-3-Clause. See [`LICENSE.md`](LICENSE.md).

Logical names are extensionless, lower-case, slash-separated segments such as `home/index`, optionally prefixed by one lower-case namespace such as `billing::invoice/show`. Segments use `a-z`, `0-9`, `_` and `-`. Absolute paths, `.` and `..`, empty segments, backslashes, whitespace, NUL and `.php` suffixes are rejected before filesystem lookup.

Register existing absolute roots explicitly with `ViewSource` in override order:

```php
use Evolve\View\FilesystemViewPathResolver;
use Evolve\View\Native\NativePhpViewRenderer;
use Evolve\View\ViewSource;

$renderer = new NativePhpViewRenderer(new FilesystemViewPathResolver([
    new ViewSource('billing', '/app/views/billing'),
    new ViewSource('billing', '/modules/billing/views'),
    new ViewSource(null, '/app/views'),
]));
$html = $renderer->render('billing::invoice/show', ['invoice' => $invoice]);
```

The first confined existing `.php` file wins. A namespaced lookup never falls back to global sources. There is no module/plugin or Composer discovery. Canonical paths are checked against canonical roots, including symlink escapes. Positive path results are cached and revalidated; misses and render data are not cached.

Native templates are trusted application or module PHP code, not sandboxed code. They receive `$view`, a `NativeViewContext`, and read data explicitly with `$view->data('key')`; data is never extracted into local variables. Use `echo $view->text($value)` for UTF-8 HTML escaping (`ENT_QUOTES | ENT_SUBSTITUTE`). `echo $view->raw(new TrustedHtml($markup))` intentionally emits reviewed markup. `TrustedHtml` is a trust marker, not a sanitizer. Direct PHP `echo` bypasses the helper; arbitrary PHP syntax has no compiler-enforced autoescaping.

Templates can render `$view->partial('name', ['key' => $value])`, select one `$view->layout('layouts/main')`, and set `$view->section('title', 'Title')`. A layout reads `$view->body()` and `$view->sectionContent('title')`. String section content is escaped; `TrustedHtml` marks intentional raw section markup. Each top-level render gets its own session, with a maximum nested depth of 32. Failed renders discard session state and restore output buffers. Shared data supplied at renderer construction is caller-owned; render data overrides it. No request, container or execution context is captured automatically.

This package has no HTTP or Core integration and does not imply production web-runtime readiness or an independent package publication beyond the repository's current status.
