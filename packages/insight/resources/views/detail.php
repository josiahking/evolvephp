<?php

declare(strict_types=1);

/** @var \Evolve\View\Native\NativeViewContext $view */
$labels = $view->data('labels');
$snapshot = $view->data('snapshot');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $view->text($labels['title']) ?></title>
<style>
body{font:16px/1.5 system-ui,sans-serif;max-width:70rem;margin:2rem auto;padding:0 1rem;color:#17212b;background:#f7f9fb}
section{background:#fff;border:1px solid #d8e0e8;border-radius:.5rem;padding:1rem;margin:1rem 0;overflow-wrap:anywhere}
dt{font-weight:bold}dd{margin:0 0 .6rem}li{margin:.6rem 0}a{color:#064f8c}
</style>
</head>
<body>
<main>
<p><a href="<?= $view->text($view->data('prefix')) ?>"><?= $view->text($labels['back']) ?></a></p>
<h1><?= $view->text($labels['title']) ?></h1>
<section>
<dl>
<dt><?= $view->text($labels['execution']) ?></dt><dd><?= $view->text($snapshot->executionIdentifier()) ?></dd>
<dt><?= $view->text($labels['execution_kind']) ?></dt><dd><?= $view->text($snapshot->executionKind()) ?></dd>
<dt><?= $view->text($labels['dropped_observations']) ?></dt><dd><?= $view->text($snapshot->droppedObservationCount()) ?></dd>
<dt><?= $view->text($labels['dropped_entries']) ?></dt><dd><?= $view->text($snapshot->droppedDiagnosticEntryCount()) ?></dd>
</dl>
</section>
<section>
<h2><?= $view->text($labels['observations']) ?></h2>
<ul>
<?php foreach ($snapshot->observations() as $observation): ?>
<li><?= $view->text($observation->type()) ?>
<dl>
<dt><?= $view->text($labels['outcome']) ?></dt><dd><?= $view->text($observation->outcome() ?? '') ?></dd>
<dt><?= $view->text($labels['error_type']) ?></dt><dd><?= $view->text($observation->errorType() ?? '') ?></dd>
<dt><?= $view->text($labels['reuse_decision']) ?></dt><dd><?= $view->text($observation->reuseDecision() ?? '') ?></dd>
</dl>
</li>
<?php endforeach; ?>
</ul>
</section>
<section>
<h2><?= $view->text($labels['entries']) ?></h2>
<ul>
<?php foreach ($snapshot->diagnosticEntries() as $entry): ?>
<li><?= $view->text($entry->category()) ?> / <?= $view->text($entry->name()) ?>
<h3><?= $view->text($labels['attributes']) ?></h3>
<dl>
<?php foreach ($entry->attributes() as $attribute): ?>
<dt><?= $view->text($attribute->name()) ?></dt>
<dd><?= $view->text($attribute->value() === null ? '' : (is_bool($attribute->value()) ? ($attribute->value() ? 'true' : 'false') : $attribute->value())) ?></dd>
<?php endforeach; ?>
</dl>
</li>
<?php endforeach; ?>
</ul>
</section>
</main>
</body>
</html>
