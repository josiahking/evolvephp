<?php

declare(strict_types=1);

/** @var \Evolve\View\Native\NativeViewContext $view */
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Insight</title></head>
<body><main><p><?= $view->text($view->data('message')) ?></p><p><a href="<?= $view->text($view->data('prefix')) ?>">Insight</a></p></main></body>
</html>
