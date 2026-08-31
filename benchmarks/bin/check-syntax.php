<?php

declare(strict_types=1);

$benchmarkRoot = dirname(__DIR__);
$paths = [
    $benchmarkRoot . DIRECTORY_SEPARATOR . 'bin',
    $benchmarkRoot . DIRECTORY_SEPARATOR . 'src',
    $benchmarkRoot . DIRECTORY_SEPARATOR . 'benchmarks',
    $benchmarkRoot . DIRECTORY_SEPARATOR . 'tests',
    $benchmarkRoot . DIRECTORY_SEPARATOR . 'comparators',
];
$failures = [];

foreach ($paths as $path) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        if (in_array('vendor', explode(DIRECTORY_SEPARATOR, $file->getPathname()), true)) {
            continue;
        }

        $command = [PHP_BINARY, '-l', $file->getPathname()];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $benchmarkRoot);

        if (!is_resource($process)) {
            $failures[] = $file->getPathname() . ': unable to start PHP lint process';
            continue;
        }

        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $failures[] = $file->getPathname() . ': ' . trim((string) $output . (string) $error);
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Benchmark PHP syntax OK' . PHP_EOL;
