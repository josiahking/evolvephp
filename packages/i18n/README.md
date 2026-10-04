# EvolvePHP I18n

Explicit internationalization and localization foundation for EvolvePHP 2. `evolvephp/i18n` requires PHP `^8.4` and `evolvephp/core` `^2.0`. EvolvePHP 2 is pre-release; this package is not yet independently published. Its canonical source is the [EvolvePHP monorepo](https://github.com/josiahking/evolvephp).

Create a `LocalizationPolicy` with supported locales, a default locale, ordered fallbacks and a default timezone. Locale identifiers accept hyphens or underscores and use bounded ASCII BCP-47-like casing; this is not full BCP 47 canonicalization. The policy chooses the exact supported locale, supported parents, configured fallbacks and default without duplicates. With no requested locale it chooses the default before configured fallbacks. Bind `ExecutionContext` values through `ExecutionLocalizationContextFactory`, or create a context directly from policy. Neither operation changes process-global locale or timezone, and there is no automatic HTTP locale negotiation.

Register trusted PHP-array catalogs explicitly with `MessageSource` and `FilesystemMessageCatalog`. Each root contains `<normalized-locale>.php` returning `array<string, string>`. Sources are checked by canonical path, and lookup is locale-first and source-order within an exact namespace. Namespaced messages never consult global catalogs. `ArrayMessageCatalog` provides the same namespace and locale lookup for applications and tests. There is no Composer, module or plugin discovery; modules and plugins register their own namespaced sources explicitly.

`CatalogTranslator` accepts a `MessageCatalog` and `MessageFormatter`; each `translate()` call receives an explicit `LocalizationContext`. Missing messages throw `MessageNotFound`. `PlaceholderMessageFormatter` works without Intl, interpolates named scalar or `Stringable` values and ignores unused parameters. Translation output is plain text: applications pass the translator and context as explicit View data, while the chosen template engine handles HTML escaping. Validation-specific presentation remains future consumer work.

`ext-intl` is optional. Applications may explicitly inject `IntlMessageFormatter` for ICU plural/select syntax and use `IntlLocaleFormatter` for number, currency, date/time and ICU measurement units. These implementations throw `IntlUnavailable` when the extension is absent; unsupported ICU operations fail explicitly. Intl is never selected just because it is installed.

License: BSD-3-Clause. See [`LICENSE.md`](LICENSE.md).
