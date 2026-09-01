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

`manifest.json` is the evidence index. It records source SHA and dirty state, matrix hash, execution order, subprocess command lines, fixture identity, comparator lock hash, execution environment identity/fingerprint, worker runtime identity, availability, raw result hashes, per-sample raw hashes, and normalized result hashes.

Each controlled comparator run requires a fresh output directory. The path must be absent or empty before execution starts; existing files or directories cause the runner to fail before new evidence is written, and stale output is never deleted implicitly.

If preflight rejects the controlled lane on a fresh path, the output directory contains `preflight.json` and `manifest.json` only. Raw and normalized timing directories are not written for a rejected controlled run.

Accepted comparator runs use one subprocess per measured sample. The aggregate raw scenario file embeds independently hashable raw sample records for every accepted sample. The raw files preserve batch durations for repeated-warm scenarios, while normalized files report per-operation latency and throughput using the recorded `operations_per_sample`.

Candidate output from a dirty or uncommitted worktree is not canonical reference evidence. Canonical reference evidence must be regenerated from the exact committed implementation ref in the controlled PHP 8.4.25 lane described in the benchmark README.
