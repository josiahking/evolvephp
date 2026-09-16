<?php

declare(strict_types=1);

namespace Evolve\Insight\Tests\Unit\Capture;

use Evolve\Insight\Capture\DiagnosticAttribute;
use Evolve\Insight\Capture\DiagnosticCaptureFilter;
use Evolve\Insight\Capture\DiagnosticDataClassification;
use Evolve\Insight\Capture\DiagnosticEntry;
use PHPUnit\Framework\TestCase;

final class DiagnosticCaptureFilterTest extends TestCase
{
    public function testNoConfiguredDisablesAllowsEntry(): void
    {
        self::assertTrue((new DiagnosticCaptureFilter())->allows($this->entry('http', 'request')));
    }

    public function testExactDisabledCategoryRejectsEntry(): void
    {
        $filter = new DiagnosticCaptureFilter(disabledCategories: array('http'));

        self::assertFalse($filter->allows($this->entry('http', 'request')));
        self::assertTrue($filter->allows($this->entry('database', 'request')));
    }

    public function testExactDisabledNameRejectsEntry(): void
    {
        $filter = new DiagnosticCaptureFilter(disabledNames: array('request'));

        self::assertFalse($filter->allows($this->entry('http', 'request')));
        self::assertTrue($filter->allows($this->entry('http', 'query')));
    }

    public function testFilterDoesNotMutateCandidate(): void
    {
        $entry = $this->entry('http', 'request');

        self::assertFalse((new DiagnosticCaptureFilter(disabledCategories: array('http')))->allows($entry));

        self::assertSame('http', $entry->category());
        self::assertSame('request', $entry->name());
        self::assertSame('users.show', $entry->attributes()[0]->value());
    }

    private function entry(string $category, string $name): DiagnosticEntry
    {
        return new DiagnosticEntry(
            'execution-1',
            $category,
            $name,
            array(new DiagnosticAttribute('route', DiagnosticDataClassification::PublicOperationalMetadata, 'users.show')),
        );
    }
}
