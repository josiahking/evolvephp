# Benchmark Results

Committed files in this directory describe the result protocol only.

Local and disposable output belongs under ignored paths:

- `results/local/`
- `results/tmp/`
- `profiles/`
- `.phpbench/`

A reference baseline should be recorded only after the documented protocol is run in a controlled, low-noise PHP 8.4 environment with the matching OPcache/JIT policy and environment fingerprint.

Controlled comparator execution writes this local structure when `benchmarks/bin/comparator-run.php` is used:

```text
results/local/comparator-candidate/
    manifest.json
    raw/
        <comparator>-<scenario>.json
    normalized/
        <comparator>-<scenario>.json
```

`manifest.json` is the evidence index. It records source SHA and dirty state, matrix hash, execution order, subprocess command lines, fixture identity, comparator lock hash, execution environment identity/fingerprint, worker runtime identity, availability, raw result hashes, per-sample raw hashes, normalized result hashes, boot protocol metadata, and actual worker-order provenance.

Each controlled comparator run requires a fresh output directory. The path must be absent or empty before execution starts; existing files or directories cause the runner to fail before new evidence is written, and stale output is never deleted implicitly.

If preflight rejects the controlled lane on a fresh path, the output directory contains `preflight.json` and `manifest.json` only. Raw and normalized timing directories are not written for a rejected controlled run.

Accepted comparator runs use one subprocess per measured sample. The aggregate raw scenario file embeds independently hashable raw sample records for every accepted sample. The raw files preserve batch durations for repeated-warm scenarios, while normalized files report per-operation latency and throughput using the recorded `operations_per_sample`.

For `application_boot`, the manifest records one separate discarded application_boot worker before each measured boot worker, rotating round-robin scheduling across selected comparators, zero in-process warmups in measured boot workers, retained measured samples, and p50 as the primary central statistic. p50 is the primary central comparison statistic for application_boot. The discarded workers are excluded from statistics and are recorded separately from `raw_samples`, so normalized sample counts and statistics include only measured workers.

The `application_boot` normalized output keeps p50 available while mean, p95, p99 and relative standard deviation remain visible as tail and noise evidence. No measured sample is removed as an outlier. The measured process is fresh and performs its first framework construction inside the measured subject; only host-level paths may have been primed by the separate discarded process.

Candidate output from a dirty or uncommitted worktree is not canonical reference evidence. Canonical reference evidence must be regenerated from the exact committed implementation ref in the controlled PHP 8.4.25 lane described in the benchmark README.
