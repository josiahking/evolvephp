# EvolvePHP 2 Benchmarks

EvolvePHP performance work starts from measurements instead of guesses. This benchmark harness provides correctness checks, environment fingerprinting, local baseline output, measured evidence for later optimization work, and benchmark-only budget evaluation for controlled EvolvePHP evidence.

This harness is benchmark infrastructure only. It does not optimize production framework code and it does not make a fastest-framework claim. There is no current fastest-framework claim and no current top-three performance claim.

## Install

From the repository root:

```powershell
composer update --working-dir=benchmarks --no-interaction
```

Benchmark-only dependencies are installed inside `benchmarks/vendor/` and locked in `benchmarks/composer.lock`.

## Protocol

The primary comparison lane is PHP 8.4 with OPcache enabled, JIT disabled, production-like framework configuration, and no debugging or profiling extension altering timings. PHP 8.5 results may be useful, but they must not be combined with PHP 8.4 results as if they came from the same environment.

one-off stopwatch results are not performance evidence. Warmup is required. Multiple iterations are required. The environment fingerprint must match before two baselines are treated as comparable. Shared GitHub-hosted runners are useful for smoke validation and benchmark policy checks, but they are not authoritative sources for absolute wall-clock regression budgets.

The initial performance budget uses p50 as the blocking metric. p95, p99, mean, relative standard deviation, throughput, and memory remain diagnostic evidence. No blocking memory budget is active because the accepted calibration did not establish a cross-run memory noise floor.

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

Validate the tracked performance budget and compact reference summary without running a benchmark:

```powershell
php benchmarks\bin\performance-budget.php --budget benchmarks\budgets\performance-budget.json --validate-reference
```

Evaluate a controlled EvolvePHP comparator candidate directory:

```powershell
php benchmarks\bin\performance-budget.php --budget benchmarks\budgets\performance-budget.json --candidate benchmarks\results\local\comparator-candidate
```

Budget evaluation states:

- `pass`: p50 is within the accepted observed calibration envelope.
- `warn`: p50 is outside the observed calibration envelope but not a blocking regression under the scenario policy.
- `fail`: a blocking warm HTTP p50 threshold was exceeded.
- `incomparable`: identity, protocol, availability, source-cleanliness, sample-count, or normalized-result requirements do not allow timing comparison.

Exit code `0` means pass or non-blocking warning. Exit code `1` means a blocking regression. Exit code `2` means incomparable evidence or invalid policy/reference data.

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

Run the controlled comparator preflight:

```powershell
php benchmarks\bin\comparator-preflight.php
```

Run controlled comparator execution:

```powershell
php benchmarks\bin\comparator-run.php --output=benchmarks\results\local\comparator-candidate --samples=100 --warmups=5 --request-count=25
```

Each controlled comparator run requires a fresh output directory. The output path must be absent or empty before execution starts; the runner fails before writing new evidence when the path already contains files or directories.

The runner performs the controlled-lane preflight before any measured worker is started. If PHP version, OPcache CLI state, JIT state, Phalcon extension version, or the required extension lane does not match, the command writes machine-readable preflight evidence and exits non-zero without writing raw or normalized timing artifacts.

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

Cross-framework comparison, optimization work, and regression budgets require reproducible controlled evidence and matching comparison identity. The benchmark-only budget policy lives under `benchmarks/budgets/performance-budget.json`, and the compact reference artifacts live under `benchmarks/results/reference/`.

Warm HTTP p50 scenarios have blocking thresholds derived from the accepted EvolvePHP-only controlled calibration. `application_boot` is currently monitor-only because its accepted calibration showed high within-run relative standard deviation and volatile tail timing. It can warn when it exceeds the observed envelope or the provisional observation boundary, but it does not produce a blocking timing failure in this policy version.

## Cross-Framework Comparator Infrastructure

Benchmark and comparator infrastructure is EvolvePHP framework-development tooling. It is not required by applications built with EvolvePHP and is intentionally excluded from normal production application dependency graphs.

The comparator foundation establishes fixture correctness, dependency isolation and reproducibility. It does not establish that EvolvePHP is faster than Laravel, Symfony, Slim or Phalcon.

Authoritative cross-framework performance measurements require the documented controlled PHP 8.4 benchmark environment.

### Comparator Installation

Install the benchmark harness explicitly:

```powershell
composer install --working-dir=benchmarks --no-interaction
```

Install comparator fixtures only when doing controlled comparator work:

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

Smoke output is correctness output only. It is deliberately separate from controlled comparator execution and must not be treated as timed evidence.

### Controlled Comparator Execution

Controlled comparator execution uses `benchmarks/bin/comparator-run.php`. The parent runner loads the matrix, verifies preflight, selects one comparator or the full matrix, selects accepted scenarios, and launches a subprocess per measured sample. Each sample subprocess loads only the minimal worker harness and the selected comparator fixture root, executes bounded warmups, records one measured sample, and returns raw JSON to the parent runner.

The runner writes raw result files, normalized result files, and `manifest.json` under the selected output directory. The manifest records the exact command for every subprocess, execution order, source SHA and dirty state, matrix SHA-256, fixture identity, comparator lock SHA-256, implementation model, execution environment identity/fingerprint, worker runtime identity, availability state, raw result path/hash, per-sample raw hashes, and normalized result path/hash.

Broken available comparators produce a non-zero runner exit. Unavailable comparators, including Phalcon without the required extension, are recorded as unavailable with an explicit reason and no fabricated timing samples.

The process-isolation model is subprocess per measured sample. Laravel, Symfony, Slim, Phalcon, and EvolvePHP comparator dependencies are not loaded into one long-lived measurement process. Accepted samples must report the same worker runtime identity as the accepted preflight lane before they are normalized. Comparator evidence uses the full execution environment identity for shared machine/tooling conditions and uses worker runtime identity only to prove child-process conformance.

`application_boot` is intentionally cold inside each measured worker: it receives zero in-process subject warmups, so the measured boot is the first framework/application construction in that fresh measured PHP process. Immediately before every measured `application_boot` sample, the runner starts one separate discarded `application_boot` worker for the same comparator. That discarded process can prime host-level filesystem and code paths, but it cannot warm the measured process in-process and its timing is excluded from statistics.

Application boot samples use deterministic rotating round-robin scheduling across the selected comparators. For selected comparators `[A, B, C]`, sample round 1 runs `A, B, C`; sample round 2 runs `B, C, A`; sample round 3 runs `C, A, B`; and the sequence repeats. Every comparator slot runs discarded worker, then measured worker. The manifest records the actual worker-order provenance with discarded/measured roles, comparator ID, scenario ID, sample index, process ID, worker runtime identity hash, exit code and availability state.

For `application_boot`, p50 is the primary central comparison statistic. Mean, p95, p99 and relative standard deviation remain visible as tail and noise evidence. Every measured sample is retained; no arbitrary outlier trimming or post-hoc sample deletion is permitted. Warm HTTP and repeated-warm scenarios keep the configured in-process subject warmups because their timing boundary starts after framework preparation, and they do not run discarded boot workers.

### Comparator Versions

The comparator matrix records these selected versions and lockfile hashes:

| Comparator | Package | Version | Constraint | Lockfile SHA-256 |
| --- | --- | --- | --- | --- |
| EvolvePHP | `evolvephp/http` | `2.0.x-dev` | `^2.0@dev` | `f792575ec5491c8d3aa171ba5f7de3b38558bfbd82b977beea45e603fd79e491` |
| Laravel | `laravel/framework` | `13.29.0` | `13.29.0` | `33b4d04706fa39dffc1d71a7d2d03f09651555afead629e31f9229adcdc86354` |
| Symfony | `symfony/http-kernel` | `8.1.5` | `8.1.5` | `d93fdac19b2cdd5379e5700a8146bb705c9b516c9ec9a0709dcd785be9b1e1d6` |
| Slim | `slim/slim` | `4.15.2` | `4.15.2` | `87370678970fe51c62c6a4cd4e4ca7b3600b22c84a2a5b920e2b8527a2e089a7` |
| Phalcon | `ext-phalcon` | `5.20.3` expected | `suggest ext-phalcon 5.20.3` | `1e6b5f4b3d70a3e0d5a74eaa55dec95bde1e1d2b33e1c64dc4a737ad8cd01562` |

The Symfony fixture represents the Symfony 8.1 framework line using `symfony/event-dispatcher 8.1.5`, `symfony/http-foundation 8.1.5`, `symfony/http-kernel 8.1.5`, and `symfony/routing 8.1.5`.

### Common Scenarios

The matrix uses only these stable cross-framework scenario IDs:

- `application_boot`: application/bootstrap setup for the selected fixture model. Framework construction is inside the measured subject.
- `http_static`: `GET /benchmark`, routed through the normal framework path, HTTP 200, deterministic body. Application/router/kernel preparation is outside the measured subject.
- `http_parameterized`: `GET /benchmark/123`, with route parameter capture proven by the response and smoke metadata. Application/router/kernel preparation is outside the measured subject.
- `http_middleware`: `GET /benchmark-middleware`, with five ordered middleware/listener layers proven by smoke metadata. Application/router/kernel preparation is outside the measured subject.
- `http_not_found`: `GET /benchmark-missing`, a genuinely unmatched path using the normal not-found path. Application/router/kernel preparation is outside the measured subject.
- `http_repeated_warm`: repeated `GET /benchmark` requests against one pre-booted reusable app/kernel/container. One prepared framework instance is reused for the configured request count.

The timed workload for these scenarios must not perform database access, network calls, template rendering, filesystem I/O, session storage, external cache access, queues, or application business logic.

The runner stores warmup counts and sample counts in the manifest. Percentiles in normalized output follow the schema rules: p95 requires at least 20 samples and p99 requires at least 100 samples, otherwise the percentile status is `insufficient_samples`.

For `http_repeated_warm`, one raw sample records the batch duration for the configured request count. Normalized latency and throughput are reported per request through `operations_per_sample`; the raw batch durations remain in raw evidence.

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

Measurement workers do not load `benchmarks/vendor/autoload.php`. They load only minimal benchmark harness files needed by the worker and the selected comparator's own autoload root.

### Environment Identity vs Fixture Identity

Execution environment identity represents shared benchmark conditions: PHP version, SAPI, operating system, CPU, memory, Composer version, PHPBench version, OPcache, JIT, loaded extensions and benchmark execution tooling. It intentionally excludes comparator lockfile hashes.

Fixture identity represents comparator-specific state: comparator/framework, exact version, fixture version, configuration, Composer lock hash and implementation model.

Two comparators with different dependency lockfiles can share the same execution environment identity when they run under the same controlled PHP 8.4 benchmark environment.

The EvolvePHP comparator framework identity uses the stable development line `2.0.x-dev`. The exact measured source SHA and dirty state are recorded separately in evidence metadata and the manifest.

### Phalcon Availability

Phalcon is extension-backed. The Phalcon fixture records two deterministic states:

- `available`: the `phalcon` extension is loaded, the actual extension version is recorded, and the real `Phalcon\Mvc\Micro` workload can execute.
- `unavailable`: matrix loading and comparator smoke still succeed, the reason is explicit, and no timing data is emitted.

Local development machines without the extension are allowed to report Phalcon unavailable. That is not performance evidence and not a fixture failure.

The middleware scenario uses Phalcon Micro before handlers to represent five framework event layers before the final route handler.

### Local Comparator Results

Any local comparator output is local and non-canonical. It may prove fixture correctness, availability handling, schema shape and dependency isolation, but it must not be used as an authoritative performance comparison. Controlled cross-framework measurement, normalization, publication and remediation decisions must use the documented PHP 8.4 benchmark protocol.

### Candidate and Reference Evidence

Candidate evidence is useful while developing or reviewing the harness. Store it under ignored local paths such as `benchmarks/results/local/comparator-candidate/`. Candidate evidence must be labelled local or candidate unless it is generated from the committed implementation reference in the controlled lane.

Canonical reference evidence requires PHP exactly 8.4.25, OPcache enabled for CLI, JIT disabled, the same php.ini/configuration and extension set for all comparator processes, and ext-phalcon 5.20.3 loaded when the five-framework lane is claimed. Shared GitHub-hosted runner wall-clock timing is not authoritative comparator evidence.

The tracked reference directory contains compact, intentional artifacts:

- `benchmarks/results/reference/performance-summary.json`
- `benchmarks/results/reference/performance-report.md`

Raw 100-sample process records, command streams, and disposable candidate directories remain local or externally archived. They should not be committed to the repository.

The budget evaluator rejects blind timing comparisons. It requires matching execution-environment fingerprint, comparator identity, scenario identity, matrix hash, EvolvePHP comparator lock hash, fixture identity hash, sample protocol, repeated-warm operations protocol, availability state, clean candidate evidence, valid source SHA, and well-formed normalized results.

The public reporting policy is a non-ranking policy. Per-scenario evidence and limitations may be published, but broad claims such as fastest framework, top-three placement, composite rankings, or one framework generally beating another belong to later accepted performance-budget work.
