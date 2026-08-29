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
