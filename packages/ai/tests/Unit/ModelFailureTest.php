<?php

declare(strict_types=1);

namespace Evolve\Ai\Tests\Unit;

use Evolve\Ai\ModelFailureCategory;
use Evolve\Ai\ModelInvocationException;
use PHPUnit\Framework\TestCase;

final class ModelFailureTest extends TestCase
{
    public function test_portable_failure_is_redacted(): void
    {
        $error = new ModelInvocationException(ModelFailureCategory::Authentication);
        self::assertSame(ModelFailureCategory::Authentication, $error->category);
        self::assertStringNotContainsString('secret', $error->getMessage());
        self::assertSame('Model invocation failed: authentication.', $error->getMessage());
    }
    public function test_all_failure_categories_have_bounded_messages(): void
    {
        foreach (ModelFailureCategory::cases() as $category) {
            $error = new ModelInvocationException($category);
            self::assertLessThan(80, strlen($error->getMessage()));
            self::assertSame('Model invocation failed: ' . $category->value . '.', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
    }
}
