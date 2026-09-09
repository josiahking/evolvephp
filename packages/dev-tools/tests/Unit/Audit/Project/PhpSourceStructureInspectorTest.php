<?php

declare(strict_types=1);

namespace Evolve\DevTools\Tests\Unit\Audit\Project;

use Evolve\DevTools\Audit\AuditFinding;
use Evolve\DevTools\Audit\AuditSeverity;
use Evolve\DevTools\Audit\Project\PhpSourceStructureInspector;
use PHPUnit\Framework\TestCase;

final class PhpSourceStructureInspectorTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = $this->createProjectRoot('evolvephp-source-structure-test-');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectRoot);
    }

    public function testReportsLexicalStructureSignalsDeterministicallyWithoutExecution(): void
    {
        $this->write('vendor/Ignored.php', '<?php namespace Vendor; class Ignored {}');
        $this->write('src/Zeta.php', <<<'PHP'
<?php
namespace App\Zeta;
interface Contract {}
PHP);
        $this->write('src/Alpha.php', <<<'PHP'
<?php
namespace App\Billing;
final class Invoice {}
trait UsesMoney {}
enum Status {}
function helper() {}
$anonymous = new class {};
$closure = function () {};
$arrow = fn () => null;
// class Commented {}
$string = 'interface StringOnly {}';
PHP);
        $this->write('src/Bracketed.php', <<<'PHP'
<?php
namespace App\Billing\Service {
    final class Processor {}
}
namespace {
    function global_helper() {}
}
PHP);
        $this->write('src/PlainGlobal.php', '<?php final class GlobalThing {}');

        $before = $this->targetInventory();
        $findings = $this->inspect();

        self::assertSame($before, $this->targetInventory());
        $this->assertFinding($findings, 'php_source.structure', AuditSeverity::Info, [
            'inspected_count' => 4,
            'inspected_paths' => [
                'src/Alpha.php',
                'src/Bracketed.php',
                'src/PlainGlobal.php',
                'src/Zeta.php',
            ],
            'complete' => true,
            'skipped' => [],
            'declarations' => [
                ['path' => 'src/Alpha.php', 'line' => 3, 'kind' => 'class', 'name' => 'Invoice', 'namespace' => 'App\\Billing'],
                ['path' => 'src/Alpha.php', 'line' => 4, 'kind' => 'trait', 'name' => 'UsesMoney', 'namespace' => 'App\\Billing'],
                ['path' => 'src/Alpha.php', 'line' => 5, 'kind' => 'enum', 'name' => 'Status', 'namespace' => 'App\\Billing'],
                ['path' => 'src/Alpha.php', 'line' => 6, 'kind' => 'function', 'name' => 'helper', 'namespace' => 'App\\Billing'],
                ['path' => 'src/Bracketed.php', 'line' => 3, 'kind' => 'class', 'name' => 'Processor', 'namespace' => 'App\\Billing\\Service'],
                ['path' => 'src/Bracketed.php', 'line' => 6, 'kind' => 'function', 'name' => 'global_helper', 'namespace' => '<global>'],
                ['path' => 'src/PlainGlobal.php', 'line' => 1, 'kind' => 'class', 'name' => 'GlobalThing', 'namespace' => '<global>'],
                ['path' => 'src/Zeta.php', 'line' => 3, 'kind' => 'interface', 'name' => 'Contract', 'namespace' => 'App\\Zeta'],
            ],
            'namespace_declarations' => [
                ['path' => 'src/Alpha.php', 'line' => 2, 'namespace' => 'App\\Billing'],
                ['path' => 'src/Bracketed.php', 'line' => 2, 'namespace' => 'App\\Billing\\Service'],
                ['path' => 'src/Bracketed.php', 'line' => 5, 'namespace' => '<global>'],
                ['path' => 'src/Zeta.php', 'line' => 2, 'namespace' => 'App\\Zeta'],
            ],
            'files_with_multiple_namespaces' => ['src/Bracketed.php'],
            'sort' => 'path, line, kind, name ascending',
            'claim' => 'lexical PHP namespace and named-declaration evidence only; source is not executed',
        ]);
        $this->assertFinding($findings, 'modernization.source_signals', AuditSeverity::Warning, [
            'complete' => true,
            'namespace_candidates' => [
                [
                    'kind' => 'namespace_group',
                    'namespace' => 'App\\Billing',
                    'declaration_count' => 4,
                    'declaration_kinds' => ['class' => 1, 'enum' => 1, 'function' => 1, 'trait' => 1],
                    'paths' => ['src/Alpha.php'],
                ],
                [
                    'kind' => 'namespace_group',
                    'namespace' => 'App\\Billing\\Service',
                    'declaration_count' => 1,
                    'declaration_kinds' => ['class' => 1],
                    'paths' => ['src/Bracketed.php'],
                ],
                [
                    'kind' => 'namespace_group',
                    'namespace' => 'App\\Zeta',
                    'declaration_count' => 1,
                    'declaration_kinds' => ['interface' => 1],
                    'paths' => ['src/Zeta.php'],
                ],
            ],
            'review_signals' => [
                ['kind' => 'global_named_declaration', 'path' => 'src/Bracketed.php', 'line' => 6, 'declaration_kind' => 'function', 'name' => 'global_helper'],
                ['kind' => 'multiple_namespace_declarations', 'path' => 'src/Bracketed.php', 'namespace_count' => 2],
                ['kind' => 'global_named_declaration', 'path' => 'src/PlainGlobal.php', 'line' => 1, 'declaration_kind' => 'class', 'name' => 'GlobalThing'],
            ],
            'claim' => 'lexical source-structure review signals only; not proof of module or capability boundaries, runtime execution, migration feasibility, Bridge compatibility, or migration readiness',
        ]);
    }

    public function testCandidateRequiresNamedDeclarationsAndUnreadableSourcesKeepInspectionIncomplete(): void
    {
        $this->write('src/NamespaceOnly.php', '<?php namespace App\Empty;');
        $this->write('src/Readable.php', '<?php namespace App\Readable; final class Ok {}');
        $this->write('src/Unreadable.php', '<?php namespace App\Skipped; final class Missing {}');

        $inspector = new PhpSourceStructureInspector(null, static function (string $path): string|false {
            return str_ends_with(str_replace('\\', '/', $path), 'src/Unreadable.php')
                ? false
                : file_get_contents($path);
        });

        $findings = $inspector->inspect($this->projectRoot);

        $this->assertFinding($findings, 'php_source.structure', AuditSeverity::Warning, [
            'inspected_count' => 2,
            'inspected_paths' => ['src/NamespaceOnly.php', 'src/Readable.php'],
            'complete' => false,
            'skipped' => [
                ['path' => 'src/Unreadable.php', 'reason' => 'unreadable'],
            ],
            'declarations' => [
                ['path' => 'src/Readable.php', 'line' => 1, 'kind' => 'class', 'name' => 'Ok', 'namespace' => 'App\\Readable'],
            ],
            'namespace_declarations' => [
                ['path' => 'src/NamespaceOnly.php', 'line' => 1, 'namespace' => 'App\\Empty'],
                ['path' => 'src/Readable.php', 'line' => 1, 'namespace' => 'App\\Readable'],
            ],
            'files_with_multiple_namespaces' => [],
            'sort' => 'path, line, kind, name ascending',
            'claim' => 'lexical PHP namespace and named-declaration evidence only; source is not executed',
        ]);
        $this->assertFinding($findings, 'php_source.structure_incomplete', AuditSeverity::Warning, [
            'skipped' => [
                ['path' => 'src/Unreadable.php', 'reason' => 'unreadable'],
            ],
            'claim' => 'source-structure analysis incomplete; absence of declarations cannot be claimed for skipped files',
        ]);
        $this->assertFinding($findings, 'modernization.source_signals', AuditSeverity::Warning, [
            'complete' => false,
            'namespace_candidates' => [
                [
                    'kind' => 'namespace_group',
                    'namespace' => 'App\\Readable',
                    'declaration_count' => 1,
                    'declaration_kinds' => ['class' => 1],
                    'paths' => ['src/Readable.php'],
                ],
            ],
            'review_signals' => [],
            'claim' => 'lexical source-structure review signals only; not proof of module or capability boundaries, runtime execution, migration feasibility, Bridge compatibility, or migration readiness',
        ]);
    }

    public function testAnonymousClassMethodsAreExcludedFromNamedFunctionDeclarations(): void
    {
        $this->write('src/Anonymous.php', <<<'PHP'
<?php
$object = new /* ignored */ class {
    public function hidden() {}
};
function visible() {}
PHP);

        $this->assertFinding($this->inspect(), 'php_source.structure', AuditSeverity::Info, [
            'inspected_count' => 1,
            'inspected_paths' => ['src/Anonymous.php'],
            'complete' => true,
            'skipped' => [],
            'declarations' => [
                ['path' => 'src/Anonymous.php', 'line' => 5, 'kind' => 'function', 'name' => 'visible', 'namespace' => '<global>'],
            ],
            'namespace_declarations' => [],
            'files_with_multiple_namespaces' => [],
            'sort' => 'path, line, kind, name ascending',
            'claim' => 'lexical PHP namespace and named-declaration evidence only; source is not executed',
        ]);
    }

    public function testReadonlyAnonymousClassMethodsAreExcludedFromNamedFunctionDeclarations(): void
    {
        $this->write('src/ReadonlyAnonymous.php', <<<'PHP'
<?php
$object = new readonly class {
    public function hidden(): void {}
};
function visible(): void {}
PHP);

        $this->assertFinding($this->inspect(), 'php_source.structure', AuditSeverity::Info, [
            'inspected_count' => 1,
            'inspected_paths' => ['src/ReadonlyAnonymous.php'],
            'complete' => true,
            'skipped' => [],
            'declarations' => [
                ['path' => 'src/ReadonlyAnonymous.php', 'line' => 5, 'kind' => 'function', 'name' => 'visible', 'namespace' => '<global>'],
            ],
            'namespace_declarations' => [],
            'files_with_multiple_namespaces' => [],
            'sort' => 'path, line, kind, name ascending',
            'claim' => 'lexical PHP namespace and named-declaration evidence only; source is not executed',
        ]);
    }

    public function testAttributedAnonymousClassMethodsAreExcludedFromNamedFunctionDeclarations(): void
    {
        $this->write('src/AttributedAnonymous.php', <<<'PHP'
<?php
$object = new #[ExampleAttribute] class {
    public function hidden(): void {}
};
function visible(): void {}
PHP);

        $this->assertFinding($this->inspect(), 'php_source.structure', AuditSeverity::Info, [
            'inspected_count' => 1,
            'inspected_paths' => ['src/AttributedAnonymous.php'],
            'complete' => true,
            'skipped' => [],
            'declarations' => [
                ['path' => 'src/AttributedAnonymous.php', 'line' => 5, 'kind' => 'function', 'name' => 'visible', 'namespace' => '<global>'],
            ],
            'namespace_declarations' => [],
            'files_with_multiple_namespaces' => [],
            'sort' => 'path, line, kind, name ascending',
            'claim' => 'lexical PHP namespace and named-declaration evidence only; source is not executed',
        ]);
    }

    public function testAnonymousClassConstructorClosureDoesNotStartClassBodyScope(): void
    {
        $this->write('src/AnonymousConstructorClosure.php', <<<'PHP'
<?php
$object = new class(function (): void {}) {
    public function hidden(): void {}
};
function visible(): void {}
PHP);

        $this->assertFinding($this->inspect(), 'php_source.structure', AuditSeverity::Info, [
            'inspected_count' => 1,
            'inspected_paths' => ['src/AnonymousConstructorClosure.php'],
            'complete' => true,
            'skipped' => [],
            'declarations' => [
                ['path' => 'src/AnonymousConstructorClosure.php', 'line' => 5, 'kind' => 'function', 'name' => 'visible', 'namespace' => '<global>'],
            ],
            'namespace_declarations' => [],
            'files_with_multiple_namespaces' => [],
            'sort' => 'path, line, kind, name ascending',
            'claim' => 'lexical PHP namespace and named-declaration evidence only; source is not executed',
        ]);
    }

    public function testAnonymousClassConstructorClosureSemicolonDoesNotAbortClassBodySearch(): void
    {
        $this->write('src/AnonymousConstructorReturn.php', <<<'PHP'
<?php
$object = new class(function () {
    return 1;
}) {
    public function hidden(): void {}
};
function visible(): void {}
PHP);

        $this->assertFinding($this->inspect(), 'php_source.structure', AuditSeverity::Info, [
            'inspected_count' => 1,
            'inspected_paths' => ['src/AnonymousConstructorReturn.php'],
            'complete' => true,
            'skipped' => [],
            'declarations' => [
                ['path' => 'src/AnonymousConstructorReturn.php', 'line' => 7, 'kind' => 'function', 'name' => 'visible', 'namespace' => '<global>'],
            ],
            'namespace_declarations' => [],
            'files_with_multiple_namespaces' => [],
            'sort' => 'path, line, kind, name ascending',
            'claim' => 'lexical PHP namespace and named-declaration evidence only; source is not executed',
        ]);
    }

    public function testNestedAnonymousClassConstructorArgumentMethodsAreExcluded(): void
    {
        $this->write('src/NestedAnonymousConstructor.php', <<<'PHP'
<?php
$object = new class(new class {
    public function inner(): void
    {
        return;
    }
}) {
    public function outer(): void {}
};
function visible(): void {}
PHP);

        $this->assertFinding($this->inspect(), 'php_source.structure', AuditSeverity::Info, [
            'inspected_count' => 1,
            'inspected_paths' => ['src/NestedAnonymousConstructor.php'],
            'complete' => true,
            'skipped' => [],
            'declarations' => [
                ['path' => 'src/NestedAnonymousConstructor.php', 'line' => 10, 'kind' => 'function', 'name' => 'visible', 'namespace' => '<global>'],
            ],
            'namespace_declarations' => [],
            'files_with_multiple_namespaces' => [],
            'sort' => 'path, line, kind, name ascending',
            'claim' => 'lexical PHP namespace and named-declaration evidence only; source is not executed',
        ]);
    }

    public function testNestedAttributeAnonymousClassMethodsAreExcludedFromNamedFunctionDeclarations(): void
    {
        $this->write('src/NestedAttributeAnonymous.php', <<<'PHP'
<?php
$object = new #[ExampleAttribute(['nested' => [1, 2]])] class {
    public function hidden(): void {}
};
function visible(): void {}
PHP);

        $this->assertFinding($this->inspect(), 'php_source.structure', AuditSeverity::Info, [
            'inspected_count' => 1,
            'inspected_paths' => ['src/NestedAttributeAnonymous.php'],
            'complete' => true,
            'skipped' => [],
            'declarations' => [
                ['path' => 'src/NestedAttributeAnonymous.php', 'line' => 5, 'kind' => 'function', 'name' => 'visible', 'namespace' => '<global>'],
            ],
            'namespace_declarations' => [],
            'files_with_multiple_namespaces' => [],
            'sort' => 'path, line, kind, name ascending',
            'claim' => 'lexical PHP namespace and named-declaration evidence only; source is not executed',
        ]);
    }

    public function testNamedClassAndClassConstantAreNotMisclassifiedAsAnonymousClass(): void
    {
        $this->write('src/NamedAndConstant.php', <<<'PHP'
<?php
readonly class NamedReadonly
{
    public function method(): void {}
}
final class Named
{
    public function method(): void {}
}
$name = Foo::class;
PHP);

        $this->assertFinding($this->inspect(), 'php_source.structure', AuditSeverity::Info, [
            'inspected_count' => 1,
            'inspected_paths' => ['src/NamedAndConstant.php'],
            'complete' => true,
            'skipped' => [],
            'declarations' => [
                ['path' => 'src/NamedAndConstant.php', 'line' => 2, 'kind' => 'class', 'name' => 'NamedReadonly', 'namespace' => '<global>'],
                ['path' => 'src/NamedAndConstant.php', 'line' => 6, 'kind' => 'class', 'name' => 'Named', 'namespace' => '<global>'],
            ],
            'namespace_declarations' => [],
            'files_with_multiple_namespaces' => [],
            'sort' => 'path, line, kind, name ascending',
            'claim' => 'lexical PHP namespace and named-declaration evidence only; source is not executed',
        ]);
    }

    public function testByReferenceNamedFunctionIsReportedWithoutClassifyingClosures(): void
    {
        $this->write('src/Reference.php', <<<'PHP'
<?php
namespace App\Functions;
function &reference_result() {}
$closure = function &() {};
PHP);

        $this->assertFinding($this->inspect(), 'php_source.structure', AuditSeverity::Info, [
            'inspected_count' => 1,
            'inspected_paths' => ['src/Reference.php'],
            'complete' => true,
            'skipped' => [],
            'declarations' => [
                ['path' => 'src/Reference.php', 'line' => 3, 'kind' => 'function', 'name' => 'reference_result', 'namespace' => 'App\\Functions'],
            ],
            'namespace_declarations' => [
                ['path' => 'src/Reference.php', 'line' => 2, 'namespace' => 'App\\Functions'],
            ],
            'files_with_multiple_namespaces' => [],
            'sort' => 'path, line, kind, name ascending',
            'claim' => 'lexical PHP namespace and named-declaration evidence only; source is not executed',
        ]);
    }

    public function testMultipleNamespaceReviewSignalUsesActualNamespaceCount(): void
    {
        $this->write('src/Namespaces.php', <<<'PHP'
<?php
namespace App\One;
function one() {}
namespace App\Two;
function two() {}
namespace App\Three;
function three() {}
PHP);

        $this->assertFinding($this->inspect(), 'modernization.source_signals', AuditSeverity::Warning, [
            'complete' => true,
            'namespace_candidates' => [
                [
                    'kind' => 'namespace_group',
                    'namespace' => 'App\\One',
                    'declaration_count' => 1,
                    'declaration_kinds' => ['function' => 1],
                    'paths' => ['src/Namespaces.php'],
                ],
                [
                    'kind' => 'namespace_group',
                    'namespace' => 'App\\Three',
                    'declaration_count' => 1,
                    'declaration_kinds' => ['function' => 1],
                    'paths' => ['src/Namespaces.php'],
                ],
                [
                    'kind' => 'namespace_group',
                    'namespace' => 'App\\Two',
                    'declaration_count' => 1,
                    'declaration_kinds' => ['function' => 1],
                    'paths' => ['src/Namespaces.php'],
                ],
            ],
            'review_signals' => [
                ['kind' => 'multiple_namespace_declarations', 'path' => 'src/Namespaces.php', 'namespace_count' => 3],
            ],
            'claim' => 'lexical source-structure review signals only; not proof of module or capability boundaries, runtime execution, migration feasibility, Bridge compatibility, or migration readiness',
        ]);
    }

    public function testSourceModernizationSignalsAreInfoWhenNoSignalsExist(): void
    {
        $this->write('src/NamespaceOnly.php', '<?php namespace App\Empty;');

        $this->assertFinding($this->inspect(), 'modernization.source_signals', AuditSeverity::Info, [
            'complete' => true,
            'namespace_candidates' => [],
            'review_signals' => [],
            'claim' => 'lexical source-structure review signals only; not proof of module or capability boundaries, runtime execution, migration feasibility, Bridge compatibility, or migration readiness',
        ]);
    }

    public function testEquivalentTreesProduceEquivalentSerializedEvidence(): void
    {
        $firstRoot = $this->createProjectRoot('evolvephp-source-structure-first-');
        $secondRoot = $this->createProjectRoot('evolvephp-source-structure-second-');

        try {
            $this->writeToRoot($firstRoot, 'src/B.php', '<?php namespace App\B; final class B {}');
            $this->writeToRoot($firstRoot, 'src/A.php', '<?php namespace App\A; function a() {}');
            $this->writeToRoot($secondRoot, 'src/A.php', '<?php namespace App\A; function a() {}');
            $this->writeToRoot($secondRoot, 'src/B.php', '<?php namespace App\B; final class B {}');

            $inspector = new PhpSourceStructureInspector();

            self::assertSame(
                $this->serializeFindings($inspector->inspect($firstRoot)),
                $this->serializeFindings($inspector->inspect($secondRoot)),
            );
        } finally {
            $this->removeDirectory($firstRoot);
            $this->removeDirectory($secondRoot);
        }
    }

    /**
     * @return list<AuditFinding>
     */
    private function inspect(): array
    {
        return (new PhpSourceStructureInspector())->inspect($this->projectRoot);
    }

    /**
     * @param list<AuditFinding> $findings
     * @param array<string, mixed> $evidence
     */
    private function assertFinding(array $findings, string $identifier, AuditSeverity $severity, array $evidence): void
    {
        $finding = $this->finding($findings, $identifier);

        self::assertSame($severity, $finding->severity());
        self::assertSame($evidence, $finding->evidence());
    }

    /**
     * @param list<AuditFinding> $findings
     */
    private function finding(array $findings, string $identifier): AuditFinding
    {
        foreach ($findings as $finding) {
            if ($finding->identifier() === $identifier) {
                return $finding;
            }
        }

        self::fail('Missing finding: ' . $identifier);
    }

    /**
     * @param list<AuditFinding> $findings
     * @return list<array{identifier: string, severity: string, message: string, evidence: array<string, mixed>}>
     */
    private function serializeFindings(array $findings): array
    {
        return array_map(
            static fn(AuditFinding $finding): array => [
                'identifier' => $finding->identifier(),
                'severity' => $finding->severity()->value,
                'message' => $finding->message(),
                'evidence' => $finding->evidence(),
            ],
            $findings,
        );
    }

    /**
     * @return array<string, array{size: int, hash: string}>
     */
    private function targetInventory(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectRoot, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($this->projectRoot) + 1));
                $files[$relativePath] = [
                    'size' => $file->getSize(),
                    'hash' => hash_file('sha256', $file->getPathname()),
                ];
            }
        }

        ksort($files);

        return $files;
    }

    private function write(string $relativePath, string $content): void
    {
        $this->writeToRoot($this->projectRoot, $relativePath, $content);
    }

    private function writeToRoot(string $root, string $relativePath, string $content): void
    {
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $directory = dirname($path);

        if (! is_dir($directory)) {
            self::assertTrue(mkdir($directory, 0777, true));
        }

        file_put_contents($path, $content);
    }

    private function createProjectRoot(string $prefix): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(8));

        self::assertTrue(mkdir($path, 0777, true));

        return realpath($path) ?: $path;
    }

    private function removeDirectory(string $path): void
    {
        $real = realpath($path);

        if ($real === false) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            $entryPath = $entry->getPathname();

            if ($entry->isDir() && ! $entry->isLink()) {
                rmdir($entryPath);
                continue;
            }

            unlink($entryPath);
        }

        rmdir($real);
    }
}
