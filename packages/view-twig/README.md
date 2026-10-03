# EvolvePHP View Twig

Optional Twig view renderer adapter for EvolvePHP 2. `evolvephp/view-twig` requires PHP `^8.4` and depends on `evolvephp/view` and `twig/twig` 3. It is not installed by the application skeleton by default and is not required by Insight. The native PHP renderer remains the zero-third-party-dependency default.

EvolvePHP 2 is pre-release, and this package is not yet independently published. The canonical source is the EvolvePHP monorepo at https://github.com/josiahking/evolvephp.

License: BSD-3-Clause. See [`LICENSE.md`](LICENSE.md).

Install and select the adapter explicitly. Register existing absolute source roots in override order:

```php
use Evolve\View\Twig\TwigViewPathResolver;
use Evolve\View\Twig\TwigViewRenderer;
use Evolve\View\ViewSource;

$renderer = new TwigViewRenderer(new TwigViewPathResolver([
    new ViewSource('billing', '/app/views/billing'),
    new ViewSource(null, '/app/views'),
]));
$html = $renderer->render('billing::invoice/show', ['invoice' => $invoice]);
```

The physical file for `billing::invoice/show` is `invoice/show.html.twig` beneath the billing root. Twig `{% include 'billing::invoice/item' %}` and `{% extends 'layouts/main' %}` use the same extensionless Evolve logical names. Namespace lookup is exact, and the first confined existing file wins. Symlink escapes are rejected. The renderer does not discover module, plugin, or Composer views automatically.

Twig HTML autoescaping is enabled by default. Twig's `|raw` is an explicit engine-native escape bypass; use it only for reviewed markup. Shared data can be supplied at construction, and per-render data overrides it, including explicit `null`. The owned Twig environment is available through `environment()` for explicit customization. Localization remains deferred.
