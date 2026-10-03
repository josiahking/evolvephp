<?php

declare(strict_types=1);

namespace Evolve\I18n\Tests\Unit;

use Evolve\I18n\ArrayMessageCatalog;
use Evolve\I18n\Exception\InvalidCatalog;
use Evolve\I18n\Exception\InvalidMessageName;
use Evolve\I18n\Exception\InvalidMessageSource;
use Evolve\I18n\FilesystemMessageCatalog;
use Evolve\I18n\LocalizationPolicy;
use Evolve\I18n\MessageName;
use Evolve\I18n\MessageSource;
use PHPUnit\Framework\TestCase;

final class CatalogTest extends TestCase
{
    public function test_message_identity_is_strict_and_namespaced(): void
    {
        self::assertSame('invoice.paid', (new MessageName('billing::invoice.paid'))->key());
        self::assertSame('billing', (new MessageName('billing::invoice.paid'))->namespace());
        self::assertNull((new MessageName('auth.login.title'))->namespace());
        foreach (['', 'billing::', 'a..b', '../x', 'a/b', 'a b', 'a::b::c', 'Bad::key'] as $invalid) {
            try {
                new MessageName($invalid);
                self::fail('Expected invalid name.');
            } catch (InvalidMessageName $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function test_array_catalog_preserves_namespace_isolation_and_locale_first_lookup(): void
    {
        $catalog = new ArrayMessageCatalog([
            ['namespace' => 'billing', 'locale' => 'en', 'messages' => ['invoice.paid' => 'Application English']],
            ['namespace' => 'billing', 'locale' => 'en-NG', 'messages' => ['invoice.paid' => 'Module Nigerian']],
            ['namespace' => null, 'locale' => 'en-NG', 'messages' => ['invoice.paid' => 'Global']],
        ]);
        $name = new MessageName('billing::invoice.paid');
        self::assertSame('Module Nigerian', $catalog->get($name, 'en-NG'));
        self::assertSame('Application English', $catalog->get($name, 'en'));
        self::assertNull($catalog->get(new MessageName('other::invoice.paid'), 'en-NG'));
    }

    public function test_filesystem_catalogs_are_confined_and_locale_first(): void
    {
        $base = sys_get_temp_dir() . '/evolve-i18n-' . bin2hex(random_bytes(8));
        mkdir($base);
        mkdir($base . '/app');
        mkdir($base . '/module');
        mkdir($base . '/outside');
        file_put_contents($base . '/app/en.php', '<?php return ["invoice.paid" => "Application English"];');
        file_put_contents($base . '/module/en-NG.php', '<?php return ["invoice.paid" => "Module Nigerian"];');
        file_put_contents($base . '/outside/en.php', '<?php return ["invoice.paid" => "Outside"];');
        try {
            $policy = new LocalizationPolicy(['en', 'en-NG'], 'en', [], 'UTC');
            $catalog = new FilesystemMessageCatalog([new MessageSource('billing', $base . '/app'), new MessageSource('billing', $base . '/module')], $policy);
            self::assertSame('Module Nigerian', $catalog->get(new MessageName('billing::invoice.paid'), 'en-NG'));
            self::assertNull($catalog->get(new MessageName('invoice.paid'), 'en'));
            self::assertNull($catalog->get(new MessageName('billing::missing'), 'en'));
            self::assertNull($catalog->get(new MessageName('billing::invoice.paid'), 'fr'));
            file_put_contents($base . '/app/en-NG.php', '<?php return ["invoice.paid" => 123];');
            $invalid = new FilesystemMessageCatalog([new MessageSource('billing', $base . '/app')], $policy);
            try {
                $invalid->get(new MessageName('billing::invoice.paid'), 'en-NG');
                self::fail('Expected invalid catalog.');
            } catch (InvalidCatalog $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
            unlink($base . '/app/en-NG.php');
            if (@symlink($base . '/outside/en.php', $base . '/app/en-NG.php')) {
                $escaped = new FilesystemMessageCatalog([new MessageSource('billing', $base . '/app')], $policy);
                $this->expectException(InvalidCatalog::class);
                $escaped->get(new MessageName('billing::invoice.paid'), 'en-NG');
            }
        } finally {
            foreach (['app/en.php', 'app/en-NG.php', 'module/en-NG.php', 'outside/en.php'] as $file) {
                if (file_exists($base . '/' . $file) || is_link($base . '/' . $file)) {
                    unlink($base . '/' . $file);
                }
            }
            rmdir($base . '/app');
            rmdir($base . '/module');
            rmdir($base . '/outside');
            rmdir($base);
        }
    }

    public function test_source_requires_an_absolute_existing_root(): void
    {
        $this->expectException(InvalidMessageSource::class);
        new MessageSource(null, 'relative/path');
    }
}
