# EvolvePHP 2 Benchmarks

EvolvePHP performance work starts from measurements instead of guesses. This benchmark harness provides correctness checks, environment fingerprinting, local baseline output, and measured evidence for later optimization work.

This harness is benchmark infrastructure only. It does not optimize production framework code and it does not make a fastest-framework claim. There is no current fastest-framework claim and no current top-three performance claim.

## Install

From the repository root:

```powershell
composer update --working-dir=benchmarks --no-interaction
```

Benchmark-only dependencies are installed inside `benchmarks/vendor/` and locked in `benchmarks/composer.lock`.

## Protocol

The primary comparison lane is PHP 8.4 with OPcache enabled, JIT disabled, production-like framework configuration, and no debugging or profiling extension altering timings. PHP 8.5 results may be useful, but they must not be combined with PHP 8.4 results as if they came from the same environment.

one-off stopwatch results are not performance evidence. Warmup is required. Multiple iterations are required. The environment fingerprint must match before two baselines are treated as comparable. Shared GitHub-hosted runners are useful for smoke validation, but they are not authoritative sources for absolute wall-clock regression budgets. Future blocking budgets must come from a controlled or proven low-noise environment.

## Commands

Validate the benchmark Composer root:

```powershell
composer validate --working-dir=benchmarks --strict
```

Run correctness tests:

```powershell
php benchmarks\vendor\bin\phpunit --configuration benchmarks\phpunit.xml.dist
```

Run syntax checks:

```powershell
php benchmarks\bin\check-syntax.php
```

Capture the environment fingerprint:

```powershell
php benchmarks\bin\capture-environment.php --output results/local/environment.json
```

Run the fast benchmark smoke:

```powershell
php benchmarks\bin\benchmark-smoke.php
```

Run internal Core scenarios:

```powershell
php benchmarks\vendor\bin\phpbench run --config=benchmarks\phpbench.json --group=core --report=aggregate
```

Run representative HTTP scenarios:

```powershell
php benchmarks\vendor\bin\phpbench run --config=benchmarks\phpbench.json --group=http --report=aggregate
```

Run a local full benchmark pass and store XML:

```powershell
php benchmarks\vendor\bin\phpbench run --config=benchmarks\phpbench.json --report=aggregate --dump-file=benchmarks\results\local\phpbench.xml
```

Normalize an XML result:

```powershell
php benchmarks\bin\normalize-results.php --input benchmarks\results\local\phpbench.xml --output benchmarks\results\local\normalized-results.json
```

## Scenarios

Internal scenarios cover application boot/component preparation, service resolution for application, execution and transient lifetimes, execution orchestration with no sink versus a no-op sink, reset participant overhead, and persistent-style sequential execution evidence.

HTTP scenarios cover real `Route`, `RouteCollection`, `RouteMatcher`, `RoutingRequestHandler`, middleware dispatch, and full `HttpKernel` execution. Route tables cover 10, 100, and 1000 routes with first, middle, and last hit positions, plus miss and 405 paths. Middleware depths cover 0, 1, 5, 10, and 20 layers.

The persistent-style run is repeated sequential execution evidence only. It is not FrankenPHP certification, RoadRunner certification, or any other runtime-adapter claim.

## Results

Local results are written under `benchmarks/results/local/` and are ignored. Treat local output as:

```text
LOCAL / NON-CANONICAL BASELINE
```

A result qualifies as a reference baseline only when the documented PHP 8.4 protocol is run on the selected controlled environment with the exact source SHA, dependency lock state, and environment fingerprint recorded for comparison.

Baseline comparison must reject casual comparisons when the environment fingerprint differs. The normalized result schema includes scenario identifiers, sample counts, timing statistics, percentiles when enough samples exist, relative standard deviation, throughput where derivable, memory fields, environment fingerprint, source SHA, and schema version.

Cross-framework comparison, optimization work, and regression budgets are intentionally outside this initial harness. They should be introduced only after baseline measurements are reproducible and the relevant comparison methodology is defined.

## Cross-Framework Comparator Infrastructure

Benchmark and comparator infrastructure is EvolvePHP framework-development tooling. It is not required by applications built with EvolvePHP and is intentionally excluded from normal production application dependency graphs.

Phase 6.5C1 establishes comparator fixture correctness, dependency isolation and reproducibility. It does not establish that EvolvePHP is faster than Laravel, Symfony, Slim or Phalcon.

Authoritative cross-framework performance measurements belong to Phase 6.5C2 and require the controlled PHP 8.4 benchmark environment.

### Comparator Installation

Install the benchmark harness explicitly:

```powershell
composer install --working-dir=benchmarks --no-interaction
```

Install comparator fixtures only when doing framework-maintainer comparator work:

```powershell
composer install --working-dir=benchmarks/comparators/evolvephp --no-interaction
composer install --working-dir=benchmarks/comparators/laravel --no-interaction --ignore-platform-req=ext-fileinfo
composer install --working-dir=benchmarks/comparators/symfony --no-interaction
composer install --working-dir=benchmarks/comparators/slim --no-interaction
composer install --working-dir=benchmarks/comparators/phalcon --no-interaction
```

The Laravel lockfile was created on this local PHP CLI with `--ignore-platform-req=ext-fileinfo` because `ext-fileinfo` is not enabled here. The comparator workload does not use file inspection.

### Comparator Smoke

Validate comparator fixture correctness without running a controlled benchmark study:

```powershell
php benchmarks\bin\comparator-smoke.php
```

The smoke command loads `benchmarks/comparators/matrix.json`, validates fixture paths and lockfiles, exercises the common scenarios, reports Phalcon unavailable when the extension is missing, and exits non-zero if an available comparator is broken. It emits no rankings and makes no performance claims.

### Comparator Versions

The Phase 6.5C1 matrix records these selected versions and lockfile hashes:

| Comparator | Package | Version | Constraint | Lockfile SHA-256 |
| --- | --- | --- | --- | --- |
| EvolvePHP | `evolvephp/http` | `2.0.0-dev+9a0e741` | `^2.0@dev` | `f792575ec5491c8d3aa171ba5f7de3b38558bfbd82b977beea45e603fd79e491` |
| Laravel | `laravel/framework` | `13.29.0` | `13.29.0` | `33b4d04706fa39dffc1d71a7d2d03f09651555afead629e31f9229adcdc86354` |
| Symfony | `symfony/http-kernel` | `8.1.5` | `8.1.5` | `d93fdac19b2cdd5379e5700a8146bb705c9b516c9ec9a0709dcd785be9b1e1d6` |
| Slim | `slim/slim` | `4.15.2` | `4.15.2` | `87370678970fe51c62c6a4cd4e4ca7b3600b22c84a2a5b920e2b8527a2e089a7` |
| Phalcon | `ext-phalcon` | `5.20.3` expected | `suggest ext-phalcon 5.20.3` | `1e6b5f4b3d70a3e0d5a74eaa55dec95bde1e1d2b33e1c64dc4a737ad8cd01562` |

The Symfony fixture represents the Symfony 8.1 framework line using `symfony/event-dispatcher 8.1.5`, `symfony/http-foundation 8.1.5`, `symfony/http-kernel 8.1.5`, and `symfony/routing 8.1.5`.

### Common Scenarios

The matrix uses only these stable cross-framework scenario IDs:

- `application_boot`: application/bootstrap setup for the selected fixture model.
- `http_static`: `GET /benchmark`, routed through the normal framework path, HTTP 200, deterministic body.
- `http_parameterized`: `GET /benchmark/123`, with route parameter capture proven by the response and smoke metadata.
- `http_middleware`: `GET /benchmark-middleware`, with five ordered middleware/listener layers proven by smoke metadata.
- `http_not_found`: `GET /benchmark-missing`, a genuinely unmatched path using the normal not-found path.
- `http_repeated_warm`: repeated `GET /benchmark` requests against one pre-booted reusable app/kernel/container.

The timed workload for these scenarios must not perform database access, network calls, template rendering, filesystem I/O, session storage, external cache access, queues, or application business logic.

### Dependency Isolation

Each comparator owns an isolated Composer root:

```text
benchmarks/comparators/evolvephp/composer.json
benchmarks/comparators/evolvephp/composer.lock
benchmarks/comparators/laravel/composer.json
benchmarks/comparators/laravel/composer.lock
benchmarks/comparators/symfony/composer.json
benchmarks/comparators/symfony/composer.lock
benchmarks/comparators/slim/composer.json
benchmarks/comparators/slim/composer.lock
benchmarks/comparators/phalcon/composer.json
benchmarks/comparators/phalcon/composer.lock
```

Laravel, Symfony, Slim and Phalcon comparator dependencies are not installed into `benchmarks/composer.json`, any `packages/*/composer.json`, the root production dependency graph, or the application skeleton.

### Environment Identity vs Fixture Identity

Execution environment identity represents shared benchmark conditions: PHP version, SAPI, operating system, CPU, memory, OPcache, JIT, loaded extensions and benchmark execution tooling. It intentionally excludes comparator lockfile hashes.

Fixture identity represents comparator-specific state: comparator/framework, exact version, fixture version, configuration, Composer lock hash and implementation model.

Two comparators with different dependency lockfiles can share the same execution environment identity when they run under the same controlled PHP 8.4 benchmark environment.

### Phalcon Availability

Phalcon is extension-backed. The Phalcon fixture records two deterministic states:

- `available`: the `phalcon` extension is loaded, the actual extension version is recorded, and the real `Phalcon\Mvc\Micro` workload can execute.
- `unavailable`: matrix loading and comparator smoke still succeed, the reason is explicit, and no timing data is emitted.

Local development machines without the extension are allowed to report Phalcon unavailable. That is not performance evidence and not a fixture failure.

### Local Comparator Results

Any local comparator output is local and non-canonical. It may prove fixture correctness, availability handling, schema shape and dependency isolation, but it must not be used as an authoritative performance comparison. Phase 6.5C2 owns controlled PHP 8.4 cross-framework measurement, normalization, publication and remediation decisions.
