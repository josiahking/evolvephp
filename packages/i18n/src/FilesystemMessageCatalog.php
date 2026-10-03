<?php

declare(strict_types=1);

namespace Evolve\I18n;

use Evolve\I18n\Exception\InvalidCatalog;
use InvalidArgumentException;
use Throwable;

final class FilesystemMessageCatalog implements MessageCatalog
{
    /** @var list<MessageSource> */
    private array $sources;
    /** @var array<string, array<string, string>> */
    private array $cache = [];

    /** @param array<array-key, mixed> $sources */
    public function __construct(array $sources, private readonly LocalizationPolicy $policy)
    {
        $validated = [];
        foreach ($sources as $source) {
            if (!$source instanceof MessageSource) {
                throw new InvalidArgumentException('Sources must be MessageSource instances.');
            }
            $validated[] = $source;
        }
        $this->sources = $validated;
    }

    public function get(MessageName $name, string $locale): ?string
    {
        $locale = Locale::normalize($locale);
        if (!in_array($locale, $this->policy->supportedLocales(), true)) {
            return null;
        }
        foreach ($this->sources as $index => $source) {
            if ($source->namespace() !== $name->namespace()) {
                continue;
            }
            $cacheKey = $index . ':' . $locale;
            if (!isset($this->cache[$cacheKey])) {
                $file = $source->root() . DIRECTORY_SEPARATOR . $locale . '.php';
                if (!file_exists($file) && !is_link($file)) {
                    continue;
                }
                $canonical = realpath($file);
                $root = $source->root();
                if ($canonical === false || !str_starts_with($canonical, $root . DIRECTORY_SEPARATOR) || !is_file($canonical)) {
                    throw new InvalidCatalog('Message catalog path is outside the registered source.');
                }
                try {
                    $messages = (static fn(): mixed => require $canonical)();
                } catch (Throwable $exception) {
                    throw new InvalidCatalog('Message catalog could not be loaded.', 0, $exception);
                }
                if (!is_array($messages)) {
                    throw new InvalidCatalog('Message catalog must return a string map.');
                }
                foreach ($messages as $key => $value) {
                    if (!is_string($key) || !MessageName::validKey($key) || !is_string($value)) {
                        throw new InvalidCatalog('Message catalog must contain valid string keys and messages.');
                    }
                }
                $this->cache[$cacheKey] = $messages;
            }
            if (array_key_exists($name->key(), $this->cache[$cacheKey])) {
                return $this->cache[$cacheKey][$name->key()];
            }
        }
        return null;
    }
}
