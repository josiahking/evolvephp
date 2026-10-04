<?php

declare(strict_types=1);

namespace Evolve\I18n;

use Evolve\I18n\Exception\MessageNotFound;

final readonly class CatalogTranslator implements Translator
{
    public function __construct(private MessageCatalog $catalog, private MessageFormatter $formatter) {}

    public function translate(string $message, LocalizationContext $context, array $parameters = []): string
    {
        $name = new MessageName($message);
        foreach ($context->fallbackChain() as $locale) {
            $template = $this->catalog->get($name, $locale);
            if ($template !== null) {
                return $this->formatter->format($template, $context->forLocale($locale), $parameters);
            }
        }
        throw new MessageNotFound('Message was not found in the configured locale chain.');
    }
}
