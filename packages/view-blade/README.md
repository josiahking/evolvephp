# EvolvePHP View Blade

Optional Blade view renderer adapter for EvolvePHP 2. `evolvephp/view-blade` requires PHP `^8.4` and depends on `evolvephp/view` and the standalone Illuminate Container, Events, Filesystem, and View components. It does not boot Laravel, is not installed by the application skeleton by default, and is not required by Insight. The native PHP renderer remains the zero-third-party-dependency default.

EvolvePHP 2 is pre-release, and this package is not yet independently published. The canonical source is the EvolvePHP monorepo at https://github.com/josiahking/evolvephp.

License: BSD-3-Clause. See [`LICENSE.md`](LICENSE.md).

Install and select the adapter explicitly. Supply an existing writable absolute compilation cache directory:

```php
use Evolve\View\Blade\BladeViewPathResolver;
use Evolve\View\Blade\BladeViewRenderer;
use Evolve\View\ViewSource;

$renderer = new BladeViewRenderer(new BladeViewPathResolver([
    new ViewSource('billing', '/app/views/billing'),
    new ViewSource(null, '/app/views'),
]), '/app/cache/blade');
$html = $renderer->render('billing::invoice/show', ['invoice' => $invoice]);
```

The physical file for `billing::invoice/show` is `invoice/show.blade.php` beneath the billing root. Blade `@include('billing::invoice/item')` and `@extends('layouts/main')` use extensionless Evolve logical names. Namespace lookup is exact, and the first confined existing file wins. Symlink escapes are rejected. The renderer does not discover module, plugin, or Composer views automatically.

Blade `{{ $value }}` escapes HTML and `{!! $value !!}` emits explicit raw output. Shared data can be supplied at construction, and per-render data overrides it, including explicit `null`. The owned View factory and compiler are available through `factory()` and `compiler()` for explicit customization. Localization remains deferred.
