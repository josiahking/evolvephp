# EvolvePHP 2 Performance Reference

This report records the initial benchmark reference used for EvolvePHP 2 performance-budget evaluation. It separates regression policy from cross-framework comparison evidence: the regression budget is calibrated from EvolvePHP-only controlled runs, while the comparison table is a per-scenario reference from the accepted controlled comparator matrix.

## Methodology

All accepted timing evidence uses PHP 8.4.25 with OPcache enabled for CLI and JIT disabled. Each scenario contains 100 measured samples. Warm HTTP scenarios use prepared framework instances and configured in-process subject warmups. The repeated-warm scenario reports per-request latency from batches of 25 requests. Application boot is intentionally cold inside each measured worker, with one separate discarded boot worker before every measured worker to prime host-level paths without warming the measured process in-process.

The canonical execution-environment fingerprint for the EvolvePHP regression calibration is:

```text
9c06a992d7f01cb7096a60b893f33a34aff7b2a86fba157c8b879d9ac55457a2
```

The regression calibration source SHA is:

```text
c62cecb16cb4fcdc93bfbb0188a4b63d8cf704ce
```

The accepted cross-framework comparator source ref is:

```text
debfb4228c4d652a5f6d0bdc4ff0f3a9c0a6c1c2
```

These are separate provenance identities and should not be merged.

## Regression Calibration

| Scenario | Run 1 p50 us | Run 2 p50 us | Run 3 p50 us | Reference p50 us | Cross-run range |
| --- | ---: | ---: | ---: | ---: | ---: |
| application_boot | 1068.25 | 1042.40 | 1044.05 | 1044.05 | 2.4799% |
| http_static | 30.90 | 30.80 | 30.70 | 30.80 | 0.6515% |
| http_parameterized | 34.40 | 34.40 | 34.50 | 34.40 | 0.2907% |
| http_middleware | 37.20 | 37.20 | 37.30 | 37.20 | 0.2688% |
| http_not_found | 23.90 | 23.80 | 23.80 | 23.80 | 0.4202% |
| http_repeated_warm | 26.662 | 26.652 | 26.540 | 26.652 | 0.4597% |

Warm HTTP scenarios receive a 5 percent actionable regression budget above the accepted reference median p50. That margin is intentionally larger than the measured cross-run noise of the warm scenarios. It is an engineering policy margin informed by controlled evidence, not a statistical-certainty claim.

| Scenario | Reference p50 us | Observed maximum p50 us | Blocking threshold p50 us |
| --- | ---: | ---: | ---: |
| http_static | 30.80 | 30.90 | 32.34 |
| http_parameterized | 34.40 | 34.50 | 36.12 |
| http_middleware | 37.20 | 37.30 | 39.06 |
| http_not_found | 23.80 | 23.90 | 24.99 |
| http_repeated_warm | 26.652 | 26.662 | 27.9846 |

`application_boot` remains monitor-only in this first policy. Its p50 and p99 showed higher volatility, and its within-run relative standard deviation was 29.7693%, 18.6663%, and 25.8726% across the three calibration runs. The current reference p50 is 1044.05 us, the observed maximum p50 is 1068.25 us, and the provisional 10 percent observation boundary is 1148.455 us. Exceeding that boundary asks for repeat investigation, but it is not a blocking timing failure in this policy.

## Cross-Framework Reference

The table below is comparison evidence from the accepted controlled comparator matrix. It is not the regression threshold source for EvolvePHP.

| Scenario | EvolvePHP p50 us | Laravel p50 us | Phalcon p50 us | Slim p50 us | Symfony p50 us |
| --- | ---: | ---: | ---: | ---: | ---: |
| application_boot | 1163.65 | 1144.90 | 264.05 | 699.05 | 579.60 |
| http_static | 30.80 | 117.40 | 29.60 | 25.40 | 93.60 |
| http_parameterized | 34.40 | 126.50 | 33.45 | 29.20 | 103.45 |
| http_middleware | 37.40 | 189.25 | 31.50 | 27.85 | 98.80 |
| http_not_found | 24.00 | 47.60 | 30.10 | 40.30 | 71.20 |
| http_repeated_warm | 26.526 | 110.660 | 25.992 | 21.554 | 87.372 |

The warm HTTP p50 results are competitive in this controlled matrix. Cold boot is the visible comparative gap and should be profiled before remediation decisions are made. The current data does not prove that framework architecture is the definitive cause of cold-boot cost.

## CI And Regression Policy

Ordinary GitHub-hosted CI validates benchmark tooling and policy shape. It should not run the canonical 100-sample timing suite, install every comparator fixture root merely to generate timing, or fail on absolute p50, p95, p99, or wall-clock values from shared runners.

Controlled candidate evidence can be evaluated with `benchmarks/bin/performance-budget.php` when it was produced under the canonical environment and protocol. Evidence with mismatched environment fingerprint, matrix identity, EvolvePHP comparator lock identity, fixture identity, sample protocol, availability state, source cleanliness, or normalized result shape is classified as incomparable rather than as a timing regression.

## Limitations

This reference does not commit raw 100-sample process records, command streams, or disposable candidate directories. It does not define a blocking memory budget because the accepted calibration bundle did not establish a cross-run memory noise floor. It does not certify FrankenPHP, RoadRunner, or another persistent-runtime adapter.

This is a non-ranking report. It does not make general cross-framework speed claims or provide an overall ranking.
