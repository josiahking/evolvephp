<?php

declare(strict_types=1);

namespace Evolve\View;

use Evolve\View\Exception\ViewNotFound;

/** @experimental */
final class FilesystemViewPathResolver implements ViewPathResolver
{
    /** @var list<ViewSource> */
    private array $sources;

    /** @var array<string, string> */
    private array $paths = [];

    /** @param list<ViewSource> $sources */
    public function __construct(array $sources)
    {
        $this->sources = $sources;
    }

    public function resolve(ViewName $name): string
    {
        $key = (string) $name;
        foreach ($this->sources as $source) {
            if ($source->namespace() !== $name->namespace()) {
                continue;
            }
            $candidate = $source->root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $name->path()) . '.php';
            $canonical = realpath($candidate);
            if ($canonical !== false && is_file($canonical) && self::confined($canonical, $source->root())) {
                if (($this->paths[$key] ?? null) === $canonical) {
                    return $canonical;
                }
                return $this->paths[$key] = $canonical;
            }
        }
        unset($this->paths[$key]);
        throw new ViewNotFound('View not found: ' . $key);
    }

    private static function confined(string $path, string $root): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower(str_replace('\\', '/', $path));
            $root = strtolower(str_replace('\\', '/', $root));
        }
        return str_starts_with($path, rtrim($root, '/\\') . (PHP_OS_FAMILY === 'Windows' ? '/' : DIRECTORY_SEPARATOR));
    }
}
