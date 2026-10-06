<?php

declare(strict_types=1);

/** @var \Evolve\View\Native\NativeViewContext $view */
$labels = $view->data('labels');
$filters = $view->data('filters');
$prefix = $view->data('prefix');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $view->text($labels['title']) ?></title>
<style>
body{font:16px/1.5 system-ui,sans-serif;max-width:70rem;margin:2rem auto;padding:0 1rem;color:#17212b;background:#f7f9fb}
h1{margin-bottom:1rem}form,table{background:#fff;border:1px solid #d8e0e8;border-radius:.5rem;padding:1rem}
form{display:flex;flex-wrap:wrap;gap:.75rem;align-items:end}label{display:grid;gap:.25rem}input{font:inherit;padding:.4rem}
button,a{color:#064f8c}button{font:inherit;padding:.45rem .8rem}table{width:100%;border-collapse:collapse;margin-top:1rem}
th,td{text-align:left;border-bottom:1px solid #d8e0e8;padding:.55rem;overflow-wrap:anywhere}
</style>
</head>
<body>
<main>
<h1><?= $view->text($labels['title']) ?></h1>
<form method="get" action="<?= $view->text($prefix) ?>">
<label><?= $view->text($labels['page_size']) ?><input name="page_size" type="number" min="1" max="100" value="<?= $view->text($filters['page_size']) ?>"></label>
<label><?= $view->text($labels['execution_kind']) ?><input name="execution_kind" value="<?= $view->text($filters['execution_kind']) ?>"></label>
<label><?= $view->text($labels['category']) ?><input name="category" value="<?= $view->text($filters['category']) ?>"></label>
<label><?= $view->text($labels['name']) ?><input name="name" value="<?= $view->text($filters['name']) ?>"></label>
<button type="submit"><?= $view->text($labels['apply']) ?></button>
</form>
<table>
<thead><tr><th><?= $view->text($labels['execution']) ?></th><th><?= $view->text($labels['execution_kind']) ?></th><th><?= $view->text($labels['observations']) ?></th><th><?= $view->text($labels['entries']) ?></th><th><?= $view->text($labels['dropped_observations']) ?></th><th><?= $view->text($labels['dropped_entries']) ?></th></tr></thead>
<tbody>
<?php foreach ($view->data('items') as $item): ?>
<tr>
<td><a href="<?= $view->text($prefix . '/' . rawurlencode($item->executionIdentifier())) ?>"><?= $view->text($item->executionIdentifier()) ?></a></td>
<td><?= $view->text($item->executionKind()) ?></td>
<td><?= $view->text($item->observationCount()) ?></td>
<td><?= $view->text($item->diagnosticEntryCount()) ?></td>
<td><?= $view->text($item->droppedObservationCount()) ?></td>
<td><?= $view->text($item->droppedDiagnosticEntryCount()) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php if ($view->data('items') === []): ?><p><?= $view->text($labels['empty']) ?></p><?php endif; ?>
<?php if ($view->data('nextUrl') !== null): ?><p><a href="<?= $view->text($view->data('nextUrl')) ?>"><?= $view->text($labels['next']) ?></a></p><?php endif; ?>
</main>
</body>
</html>
