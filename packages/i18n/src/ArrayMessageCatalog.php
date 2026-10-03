<?php

declare(strict_types=1);

namespace Evolve\I18n;

use Evolve\I18n\Exception\InvalidCatalog;

final readonly class ArrayMessageCatalog implements MessageCatalog
{
    /** @var list<array{namespace: ?string, locale: string, messages: array<string, string>}> */
    private array $sources;

    /** @param array<array-key, mixed> $sources */
    public function __construct(array $sources)
    {
        /** @var list<array{namespace: ?string, locale: string, messages: array<string, string>}> $validated */
        $validated = [];
        foreach ($sources as $source) {
            if (!is_array($source) || !isset($source['locale'], $source['messages']) || !array_key_exists('namespace', $source)
                || ($source['namespace'] !== null && (!is_string($source['namespace']) || !MessageName::validNamespace($source['namespace'])))
                || !is_string($source['locale']) || !is_array($source['messages'])) {
                throw new InvalidCatalog('Invalid in-memory message source.');
            }
            $messages = [];
            foreach ($source['messages'] as $key => $message) {
                if (!is_string($key) || !MessageName::validKey($key) || !is_string($message)) {
                    throw new InvalidCatalog('Invalid message catalog entry.');
                }
                $messages[$key] = $message;
            }
            $validated[] = ['namespace' => $source['namespace'], 'locale' => Locale::normalize($source['locale']), 'messages' => $messages];
        }
        $this->sources = $validated;
    }

    public function get(MessageName $name, string $locale): ?string
    {
        $locale = Locale::normalize($locale);
        foreach ($this->sources as $source) {
            if ($source['namespace'] === $name->namespace() && $source['locale'] === $locale && array_key_exists($name->key(), $source['messages'])) {
                return $source['messages'][$name->key()];
            }
        }
        return null;
    }
}
