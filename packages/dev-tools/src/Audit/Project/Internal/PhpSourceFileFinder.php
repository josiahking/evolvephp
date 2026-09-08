<?php

declare(strict_types=1);

namespace Evolve\DevTools\Audit\Project\Internal;

/**
 * @experimental
 *
 * @internal
 */
final readonly class PhpSourceFileFinder
{
    /**
     * @return array{files: list<array{path: string, absolute_path: string}>, skipped: list<array{path: string, reason: string}>}
     */
    public function find(string $projectRoot): array
    {
        $root = rtrim($projectRoot, DIRECTORY_SEPARATOR);
        $files = [];
        $skipped = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                function (\SplFileInfo $current) use ($root, &$skipped): bool {
                    $relativePath = $this->relativePath($root, $current->getPathname());

                    if ($current->isDir()) {
                        if ($current->isLink()) {
                            $skipped[] = ['path' => $relativePath, 'reason' => 'symlink'];

                            return false;
                        }

                        return ! in_array($relativePath . '/', ['vendor/', '.git/', '.hg/', '.svn/'], true);
                    }

                    return true;
                },
            ),
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile() || $file->isLink()) {
                if ($file instanceof \SplFileInfo && $file->isLink() && str_ends_with(strtolower($file->getFilename()), '.php')) {
                    $skipped[] = ['path' => $this->relativePath($root, $file->getPathname()), 'reason' => 'symlink'];
                }

                continue;
            }

            if (strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $files[] = [
                'path' => $this->relativePath($root, $file->getPathname()),
                'absolute_path' => $file->getPathname(),
            ];
        }

        usort($files, static fn(array $left, array $right): int => $left['path'] <=> $right['path']);
        usort($skipped, static fn(array $left, array $right): int => [$left['path'], $left['reason']] <=> [$right['path'], $right['reason']]);

        return ['files' => $files, 'skipped' => $skipped];
    }

    private function relativePath(string $root, string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen($root) + 1));
    }
}
