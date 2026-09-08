<?php

declare(strict_types=1);

namespace Evolve\DevTools\Tests\Unit\Audit\Project;

use Evolve\DevTools\Audit\AuditFinding;
use Evolve\DevTools\Audit\AuditReport;
use Evolve\DevTools\Audit\AuditRunner;
use Evolve\DevTools\Audit\AuditSeverity;
use Evolve\DevTools\Audit\Project\ComposerProjectInspector;
use Evolve\DevTools\Audit\Project\PhpSourceCouplingInspector;
use PHPUnit\Framework\TestCase;

final class PhpSourceCouplingInspectorTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = $this->createProjectRoot('evolvephp-source-audit-test-');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectRoot);
    }

    public function testDiscoversPhpFilesRecursivelyWithDeterministicNormalizedInventory(): void
    {
        $this->write('vendor/ignored.php', '<?php $_GET["ignored"];');
        $this->write('.git/ignored.php', '<?php $_POST["ignored"];');
        $this->write('.hg/ignored.php', '<?php $_COOKIE["ignored"];');
        $this->write('.svn/ignored.php', '<?php $_SERVER["ignored"];');
        $this->write('notes.txt', '<?php $_ENV["ignored"];');
        $this->write('routes/web.php', '<?php return [];');
        $this->write('config/app.php', '<?php return [];');
        $this->write('tests/Feature/Smoke.php', '<?php final class Smoke {}');
        $this->write('src/Zeta.php', '<?php final class Zeta {}');
        $this->write('src/Alpha.php', '<?php final class Alpha {}');

        $inventory = $this->finding($this->inspect(), 'php_source.inventory');

        self::assertSame(AuditSeverity::Info, $inventory->severity());
        self::assertSame([
            'inspected_count' => 5,
            'inspected_paths' => [
                'config/app.php',
                'routes/web.php',
                'src/Alpha.php',
                'src/Zeta.php',
                'tests/Feature/Smoke.php',
            ],
            'complete' => true,
            'skipped' => [],
            'sort' => 'path ascending',
        ], $inventory->evidence());

        self::assertSame(['php_source.inventory'], $this->findingIdentifiers($this->inspect()));
    }

    public function testDetectsDirectSuperglobalAccessesWithWarningEvidence(): void
    {
        $this->write('src/Input.php', <<<'PHP'
<?php
$_GET['a'];
$_POST['b'];
$_REQUEST['c'];
$_COOKIE['d'];
$_FILES['e'];
$_SERVER['f'];
$_ENV['g'];
PHP);

        $this->assertFinding($this->inspect(), 'php_source.superglobal_access', AuditSeverity::Warning, [
            'occurrences' => [
                ['path' => 'src/Input.php', 'line' => 2, 'symbol' => '$_GET'],
                ['path' => 'src/Input.php', 'line' => 3, 'symbol' => '$_POST'],
                ['path' => 'src/Input.php', 'line' => 4, 'symbol' => '$_REQUEST'],
                ['path' => 'src/Input.php', 'line' => 5, 'symbol' => '$_COOKIE'],
                ['path' => 'src/Input.php', 'line' => 6, 'symbol' => '$_FILES'],
                ['path' => 'src/Input.php', 'line' => 7, 'symbol' => '$_SERVER'],
                ['path' => 'src/Input.php', 'line' => 8, 'symbol' => '$_ENV'],
            ],
            'claim' => 'direct lexical superglobal access evidence only',
        ]);
    }

    public function testDetectsSessionGlobalsAndGlobalStatementsAsRiskEvidence(): void
    {
        $this->write('src/State.php', <<<'PHP'
<?php
$_SESSION['id'];
$GLOBALS['container'];
global $foo, $bar;
PHP);

        $findings = $this->inspect();

        $this->assertFinding($findings, 'php_source.session_access', AuditSeverity::Risk, [
            'occurrences' => [
                ['path' => 'src/State.php', 'line' => 2, 'symbol' => '$_SESSION'],
            ],
            'claim' => 'direct native session state access evidence only',
        ]);
        $this->assertFinding($findings, 'php_source.globals_access', AuditSeverity::Risk, [
            'occurrences' => [
                ['path' => 'src/State.php', 'line' => 3, 'symbol' => '$GLOBALS'],
            ],
            'claim' => 'direct $GLOBALS access evidence only',
        ]);
        $this->assertFinding($findings, 'php_source.global_statement', AuditSeverity::Risk, [
            'occurrences' => [
                ['path' => 'src/State.php', 'line' => 4, 'kind' => 'global'],
            ],
            'claim' => 'lexical global statement evidence only',
        ]);
    }

    public function testDetectsDirectNativeSessionStartAsRiskEvidence(): void
    {
        $this->write('src/Session.php', <<<'PHP'
<?php
session_start();
PHP);

        $this->assertFinding($this->inspect(), 'php_source.native_session_start', AuditSeverity::Risk, [
            'occurrences' => [
                ['path' => 'src/Session.php', 'line' => 2, 'symbol' => 'session_start'],
            ],
            'claim' => 'direct named lexical native session_start invocation evidence only; not proof that the call executes or that session integration is unsafe',
        ]);
    }

    public function testDetectsCaseInsensitiveAndFullyQualifiedDirectNamedRuntimeCalls(): void
    {
        $this->write('src/Qualified.php', <<<'PHP'
<?php
SETLOCALE(LC_ALL, 'C');
\header('X-Test: yes');
\session_start();
PHP);

        $findings = $this->inspect();

        $this->assertFinding($findings, 'php_source.process_global_mutation', AuditSeverity::Risk, [
            'occurrences' => [
                ['path' => 'src/Qualified.php', 'line' => 2, 'symbol' => 'SETLOCALE'],
            ],
            'claim' => 'direct named lexical process-global mutation review evidence only; not proof that restoration is absent or that the target is persistently unsafe',
        ]);
        $this->assertFinding($findings, 'php_source.response_side_effect', AuditSeverity::Warning, [
            'occurrences' => [
                ['path' => 'src/Qualified.php', 'line' => 3, 'symbol' => '\\header'],
            ],
            'claim' => 'direct lexical response/output-side-effect review evidence only; not proof that a detected call violates adapter ownership',
        ]);
        $this->assertFinding($findings, 'php_source.native_session_start', AuditSeverity::Risk, [
            'occurrences' => [
                ['path' => 'src/Qualified.php', 'line' => 4, 'symbol' => '\\session_start'],
            ],
            'claim' => 'direct named lexical native session_start invocation evidence only; not proof that the call executes or that session integration is unsafe',
        ]);
    }

    public function testDetectsAcceptedRuntimeHazardCatalogue(): void
    {
        $this->write('src/Hazards.php', <<<'PHP'
<?php
setlocale(LC_ALL, 'C');
date_default_timezone_set('UTC');
header('X-Test: yes');
header_remove('X-Test');
setcookie('a', 'b');
setrawcookie('c', 'd');
ob_start();
ob_clean();
ob_flush();
ob_end_clean();
ob_end_flush();
ob_get_clean();
ob_get_flush();
ob_implicit_flush(true);
echo 'body';
print 'body';
register_shutdown_function('cleanup');
exit;
die('done');
eval('$a = 1;');
include 'a.php';
include_once 'b.php';
require 'c.php';
require_once 'd.php';
PHP);

        $findings = $this->inspect();

        $this->assertFinding($findings, 'php_source.process_global_mutation', AuditSeverity::Risk, [
            'occurrences' => [
                ['path' => 'src/Hazards.php', 'line' => 2, 'symbol' => 'setlocale'],
                ['path' => 'src/Hazards.php', 'line' => 3, 'symbol' => 'date_default_timezone_set'],
            ],
            'claim' => 'direct named lexical process-global mutation review evidence only; not proof that restoration is absent or that the target is persistently unsafe',
        ]);
        $this->assertFinding($findings, 'php_source.response_side_effect', AuditSeverity::Warning, [
            'occurrences' => [
                ['path' => 'src/Hazards.php', 'line' => 4, 'symbol' => 'header'],
                ['path' => 'src/Hazards.php', 'line' => 5, 'symbol' => 'header_remove'],
                ['path' => 'src/Hazards.php', 'line' => 6, 'symbol' => 'setcookie'],
                ['path' => 'src/Hazards.php', 'line' => 7, 'symbol' => 'setrawcookie'],
                ['path' => 'src/Hazards.php', 'line' => 8, 'symbol' => 'ob_start'],
                ['path' => 'src/Hazards.php', 'line' => 9, 'symbol' => 'ob_clean'],
                ['path' => 'src/Hazards.php', 'line' => 10, 'symbol' => 'ob_flush'],
                ['path' => 'src/Hazards.php', 'line' => 11, 'symbol' => 'ob_end_clean'],
                ['path' => 'src/Hazards.php', 'line' => 12, 'symbol' => 'ob_end_flush'],
                ['path' => 'src/Hazards.php', 'line' => 13, 'symbol' => 'ob_get_clean'],
                ['path' => 'src/Hazards.php', 'line' => 14, 'symbol' => 'ob_get_flush'],
                ['path' => 'src/Hazards.php', 'line' => 15, 'symbol' => 'ob_implicit_flush'],
                ['path' => 'src/Hazards.php', 'line' => 16, 'kind' => 'echo'],
                ['path' => 'src/Hazards.php', 'line' => 17, 'kind' => 'print'],
            ],
            'claim' => 'direct lexical response/output-side-effect review evidence only; not proof that a detected call violates adapter ownership',
        ]);
        $this->assertFinding($findings, 'php_source.process_lifetime_callback', AuditSeverity::Warning, [
            'occurrences' => [
                ['path' => 'src/Hazards.php', 'line' => 18, 'symbol' => 'register_shutdown_function'],
            ],
            'claim' => 'direct named lexical process-lifetime callback registration evidence only',
        ]);
        $this->assertFinding($findings, 'php_source.process_termination', AuditSeverity::Risk, [
            'occurrences' => [
                ['path' => 'src/Hazards.php', 'line' => 19, 'kind' => 'exit'],
                ['path' => 'src/Hazards.php', 'line' => 20, 'kind' => 'die'],
            ],
            'claim' => 'lexical process-termination construct evidence only',
        ]);
        $this->assertFinding($findings, 'php_source.eval', AuditSeverity::Risk, [
            'occurrences' => [
                ['path' => 'src/Hazards.php', 'line' => 21, 'kind' => 'eval'],
            ],
            'claim' => 'lexical eval construct evidence only',
        ]);
        $this->assertFinding($findings, 'php_source.include_require', AuditSeverity::Warning, [
            'occurrences' => [
                ['path' => 'src/Hazards.php', 'line' => 22, 'kind' => 'include'],
                ['path' => 'src/Hazards.php', 'line' => 23, 'kind' => 'include_once'],
                ['path' => 'src/Hazards.php', 'line' => 24, 'kind' => 'require'],
                ['path' => 'src/Hazards.php', 'line' => 25, 'kind' => 'require_once'],
            ],
            'claim' => 'lexical include/require construct evidence only; included paths are not resolved or inspected',
        ]);
    }

    public function testDoesNotInferRuntimeHazardsFromNonDirectCallsDeclarationsStringsOrFirstClassCallables(): void
    {
        $this->write('src/FalseRuntimeHazards.php', <<<'PHP'
<?php
$object->header();
$object?->setlocale();
Foo::header();
Foo::session_start();
Foo\setlocale();
namespace\setlocale();
function header() {}
use function Foo\setcookie;
$fn();
call_user_func('header');
call_user_func_array('setlocale', []);
Closure::fromCallable('session_start');
$literal = "header(";
// session_start(
setlocale(...);
\header(...);
PHP);

        self::assertSame(['php_source.inventory'], $this->findingIdentifiers($this->inspect()));
    }

    public function testDoesNotInferRuntimeHazardsFromClassConstructionAttributesOrByReferenceDeclarations(): void
    {
        $this->write('src/ContextFalsePositives.php', <<<'PHP'
<?php
new header();
new session_start();
new setlocale();
new \header();
new \session_start();
new \setlocale();

#[header()]
final class AttributeHeader {}

#[session_start()]
final class AttributeSession {}

#[\header()]
final class RootAttributeHeader {}

#[\session_start()]
final class RootAttributeSession {}

function &header() {}
function &session_start() {}
final class Methods
{
    public function &header() {}
}

header('X-Test: yes');
\header('X-Test: root');
setlocale(...$arguments);
setlocale(...);
$result = $mask & header('X-Bitwise: yes');
PHP);

        $findings = $this->inspect();

        self::assertSame([
            'php_source.inventory',
            'php_source.process_global_mutation',
            'php_source.response_side_effect',
        ], $this->findingIdentifiers($findings));
        $this->assertFinding($findings, 'php_source.process_global_mutation', AuditSeverity::Risk, [
            'occurrences' => [
                ['path' => 'src/ContextFalsePositives.php', 'line' => 30, 'symbol' => 'setlocale'],
            ],
            'claim' => 'direct named lexical process-global mutation review evidence only; not proof that restoration is absent or that the target is persistently unsafe',
        ]);
        $this->assertFinding($findings, 'php_source.response_side_effect', AuditSeverity::Warning, [
            'occurrences' => [
                ['path' => 'src/ContextFalsePositives.php', 'line' => 28, 'symbol' => 'header'],
                ['path' => 'src/ContextFalsePositives.php', 'line' => 29, 'symbol' => '\\header'],
                ['path' => 'src/ContextFalsePositives.php', 'line' => 32, 'symbol' => 'header'],
            ],
            'claim' => 'direct lexical response/output-side-effect review evidence only; not proof that a detected call violates adapter ownership',
        ]);
    }

    public function testDoesNotInferRuntimeHazardsFromSpacedByReferenceDeclarations(): void
    {
        $this->write('src/SpacedByReferenceDeclarations.php', <<<'PHP'
<?php
function & header() {}
function &  session_start() {}
function & /* comment */ setlocale() {}
function &
    header() {}
final class Methods
{
    public function & header() {}
}
$result = $mask & header('X-Test: yes');
PHP);

        $this->assertFinding($this->inspect(), 'php_source.response_side_effect', AuditSeverity::Warning, [
            'occurrences' => [
                ['path' => 'src/SpacedByReferenceDeclarations.php', 'line' => 11, 'symbol' => 'header'],
            ],
            'claim' => 'direct lexical response/output-side-effect review evidence only; not proof that a detected call violates adapter ownership',
        ]);
        self::assertSame([
            'php_source.inventory',
            'php_source.response_side_effect',
        ], $this->findingIdentifiers($this->inspect()));
    }

    public function testArgumentUnpackingInvocationIsNotTreatedAsFirstClassCallablePlaceholder(): void
    {
        $this->write('src/Unpack.php', <<<'PHP'
<?php
setlocale(...$arguments);
PHP);

        $this->assertFinding($this->inspect(), 'php_source.process_global_mutation', AuditSeverity::Risk, [
            'occurrences' => [
                ['path' => 'src/Unpack.php', 'line' => 2, 'symbol' => 'setlocale'],
            ],
            'claim' => 'direct named lexical process-global mutation review evidence only; not proof that restoration is absent or that the target is persistently unsafe',
        ]);
    }

    public function testDetectsStaticEvidenceWithoutStaticMethodFunctionOrConstantFalsePositives(): void
    {
        $this->write('src/StaticState.php', <<<'PHP'
<?php
static $cache;
final class Example
{
    public static $untyped;
    public static string $typed;
    protected static ?Service $nullable;
    private static Foo|Bar $union;
    public static array $arrayCache;
    public static Foo&Bar $intersection;
    public static (Foo&Bar)|Baz $dnf;
}
Foo::$state;
self::$state;
static::$state;
parent::$state;
$dynamic::$state;
Foo::method();
Foo::CONSTANT;
self::class;
static function () {};
static fn () => null;
PHP);

        $findings = $this->inspect();

        $this->assertFinding($findings, 'php_source.static_state_declaration', AuditSeverity::Warning, [
            'occurrences' => [
                ['path' => 'src/StaticState.php', 'line' => 2, 'kind' => 'static_variable'],
                ['path' => 'src/StaticState.php', 'line' => 5, 'kind' => 'static_variable'],
                ['path' => 'src/StaticState.php', 'line' => 6, 'kind' => 'static_variable'],
                ['path' => 'src/StaticState.php', 'line' => 7, 'kind' => 'static_variable'],
                ['path' => 'src/StaticState.php', 'line' => 8, 'kind' => 'static_variable'],
                ['path' => 'src/StaticState.php', 'line' => 9, 'kind' => 'static_variable'],
                ['path' => 'src/StaticState.php', 'line' => 10, 'kind' => 'static_variable'],
                ['path' => 'src/StaticState.php', 'line' => 11, 'kind' => 'static_variable'],
            ],
            'claim' => 'static declaration review evidence only; not proof of unsafe mutable execution state',
        ]);
        $this->assertFinding($findings, 'php_source.static_property_access', AuditSeverity::Warning, [
            'occurrences' => [
                ['path' => 'src/StaticState.php', 'line' => 13, 'symbol' => '$state'],
                ['path' => 'src/StaticState.php', 'line' => 14, 'symbol' => '$state'],
                ['path' => 'src/StaticState.php', 'line' => 15, 'symbol' => '$state'],
                ['path' => 'src/StaticState.php', 'line' => 16, 'symbol' => '$state'],
                ['path' => 'src/StaticState.php', 'line' => 17, 'symbol' => '$state'],
            ],
            'claim' => 'static property access review evidence only; not proof of unsafe mutable execution state',
        ]);
    }

    public function testIgnoresCommentsAndOrdinaryNonInterpolatingStrings(): void
    {
        $this->write('src/FalsePositive.php', <<<'PHP'
<?php
// $_SESSION
'$_SESSION';
'$GLOBALS';
'$_ENV';
'Foo::$state';
$text = <<<'TXT'
$_GET
TXT;
PHP);

        self::assertSame(['php_source.inventory'], $this->findingIdentifiers($this->inspect()));
    }

    public function testDetectsInterpolatedSuperglobalAndSessionVariablesUsingTokenizerEvidence(): void
    {
        $this->write('src/Interpolated.php', <<<'PHP'
<?php
"$_ENV";
"$_SESSION";
echo <<<TXT
$_SERVER
TXT;
PHP);

        $findings = $this->inspect();

        $this->assertFinding($findings, 'php_source.superglobal_access', AuditSeverity::Warning, [
            'occurrences' => [
                ['path' => 'src/Interpolated.php', 'line' => 2, 'symbol' => '$_ENV'],
                ['path' => 'src/Interpolated.php', 'line' => 5, 'symbol' => '$_SERVER'],
            ],
            'claim' => 'direct lexical superglobal access evidence only',
        ]);
        $this->assertFinding($findings, 'php_source.session_access', AuditSeverity::Risk, [
            'occurrences' => [
                ['path' => 'src/Interpolated.php', 'line' => 3, 'symbol' => '$_SESSION'],
            ],
            'claim' => 'direct native session state access evidence only',
        ]);
    }

    public function testEquivalentTreesProduceEquivalentSerializedEvidence(): void
    {
        $firstRoot = $this->createProjectRoot('evolvephp-source-audit-first-');
        $secondRoot = $this->createProjectRoot('evolvephp-source-audit-second-');

        try {
            $this->writeToRoot($firstRoot, 'src/B.php', '<?php header("X-B: b");');
            $this->writeToRoot($firstRoot, 'src/A.php', '<?php $_GET["a"]; session_start();');
            $this->writeToRoot($secondRoot, 'src/A.php', '<?php $_GET["a"]; session_start();');
            $this->writeToRoot($secondRoot, 'src/B.php', '<?php header("X-B: b");');

            $inspector = new PhpSourceCouplingInspector();

            self::assertSame(
                $this->serializeFindings($inspector->inspect($firstRoot)),
                $this->serializeFindings($inspector->inspect($secondRoot)),
            );
        } finally {
            $this->removeDirectory($firstRoot);
            $this->removeDirectory($secondRoot);
        }
    }

    public function testInspectionDoesNotExecuteOrModifyTargetPhpFiles(): void
    {
        $this->write('src/Trap.php', <<<'PHP'
<?php
header('X-Trap: bad');
include __DIR__ . '/included-trap.php';
eval('file_put_contents(__DIR__ . "/audit-eval-marker", "bad");');
file_put_contents(__DIR__ . '/../audit-executed-marker', 'bad');
PHP);
        $this->write('src/included-trap.php', <<<'PHP'
<?php
file_put_contents(__DIR__ . '/../audit-included-marker', 'bad');
PHP);

        $before = $this->targetInventory();
        $this->inspect();
        $after = $this->targetInventory();

        self::assertFileDoesNotExist($this->path('audit-executed-marker'));
        self::assertFileDoesNotExist($this->path('audit-eval-marker'));
        self::assertFileDoesNotExist($this->path('audit-included-marker'));
        self::assertSame($before, $after);
    }

    public function testEmptyProjectReportsDeterministicCleanInventoryOnly(): void
    {
        $this->write('README.md', 'No PHP here.');

        $this->assertFinding($this->inspect(), 'php_source.inventory', AuditSeverity::Info, [
            'inspected_count' => 0,
            'inspected_paths' => [],
            'complete' => true,
            'skipped' => [],
            'sort' => 'path ascending',
        ]);
        self::assertSame(['php_source.inventory'], $this->findingIdentifiers($this->inspect()));
    }

    public function testUnreadableSourceReportsIncompleteInventoryWithoutFalseCleanClaim(): void
    {
        $this->write('src/Readable.php', '<?php $_GET["ok"]; header("X-Ok: yes");');
        $this->write('src/Unreadable.php', '<?php $_POST["unknown"];');

        $inspector = new PhpSourceCouplingInspector(null, static function (string $path): string|false {
            return str_ends_with(str_replace('\\', '/', $path), 'src/Unreadable.php')
                ? false
                : file_get_contents($path);
        });

        $findings = $inspector->inspect($this->projectRoot);

        $this->assertFinding($findings, 'php_source.inventory', AuditSeverity::Warning, [
            'inspected_count' => 1,
            'inspected_paths' => ['src/Readable.php'],
            'complete' => false,
            'skipped' => [
                ['path' => 'src/Unreadable.php', 'reason' => 'unreadable'],
            ],
            'sort' => 'path ascending',
        ]);
        $this->assertFinding($findings, 'php_source.inspection_incomplete', AuditSeverity::Warning, [
            'skipped' => [
                ['path' => 'src/Unreadable.php', 'reason' => 'unreadable'],
            ],
            'claim' => 'source analysis incomplete; absence of coupling cannot be claimed for skipped files',
        ]);
        $this->assertFinding($findings, 'php_source.superglobal_access', AuditSeverity::Warning, [
            'occurrences' => [
                ['path' => 'src/Readable.php', 'line' => 1, 'symbol' => '$_GET'],
            ],
            'claim' => 'direct lexical superglobal access evidence only',
        ]);
        $this->assertFinding($findings, 'php_source.response_side_effect', AuditSeverity::Warning, [
            'occurrences' => [
                ['path' => 'src/Readable.php', 'line' => 1, 'symbol' => 'header'],
            ],
            'claim' => 'direct lexical response/output-side-effect review evidence only; not proof that a detected call violates adapter ownership',
        ]);
    }

    public function testPhpSourceInspectorComposesWithComposerInspectorThroughAuditRunner(): void
    {
        $this->write('composer.json', json_encode(['require' => ['php' => '^8.4']], JSON_THROW_ON_ERROR));
        $this->write('src/Input.php', '<?php $_SERVER["REQUEST_METHOD"];');

        $runner = new AuditRunner([
            new ComposerProjectInspector(),
            new PhpSourceCouplingInspector(),
        ]);

        self::assertSame([
            'composer_json.present',
            'composer.php_constraint',
            'composer.dependencies.runtime',
            'composer.dependencies.development',
            'composer.frameworks',
            'php_source.inventory',
            'php_source.superglobal_access',
        ], $this->findingIdentifiers($runner->inspect($this->projectRoot)));
    }

    /**
     * @return list<AuditFinding>
     */
    private function inspect(): array
    {
        return (new PhpSourceCouplingInspector())->inspect($this->projectRoot);
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
     * @param list<AuditFinding>|AuditReport $findings
     */
    private function finding(array|AuditReport $findings, string $identifier): AuditFinding
    {
        foreach ($findings as $finding) {
            if ($finding->identifier() === $identifier) {
                return $finding;
            }
        }

        self::fail('Missing finding: ' . $identifier);
    }

    /**
     * @param list<AuditFinding>|AuditReport $findings
     * @return list<string>
     */
    private function findingIdentifiers(array|AuditReport $findings): array
    {
        return array_map(
            static fn(AuditFinding $finding): string => $finding->identifier(),
            is_array($findings) ? $findings : $findings->findings(),
        );
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

    private function path(string $relativePath): string
    {
        return $this->projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
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
