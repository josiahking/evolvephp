# EvolvePHP documentation website

This directory contains the current EvolvePHP 2 Next developer manual. MkDocs 1.6.1 builds it with the bundled theme and search. The repository root remains a PHP/Composer project.

The authored manual lives in docs/next/. The site targets https://josiahking.github.io/evolvephp/ when GitHub Pages publication is separately authorized. Current source is pre-release and not production-ready; no historical documentation snapshot is generated from today's Next pages.

Use an explicit Python 3.11+ interpreter in a private virtual environment, install the pinned requirements-docs.txt set, and run build_docs.py. The wrapper runs MkDocs in strict mode and places .nojekyll in generated build/. Development and audit tooling is pinned separately in requirements-audit.txt. Documentation CI runs on Python 3.11 and 3.13 with PHP 8.4 for PHP examples. Build validation does not publish the site.

See the [versioning policy](docs/next/project/documentation-versioning.md) for future snapshots tied to actual release tags. Do not commit build/, virtual environments or caches.
