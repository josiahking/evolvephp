# EvolvePHP Database PDO

`evolvephp/database-pdo` provides the PDO database adapter for EvolvePHP 2 database contracts.

EvolvePHP 2 is pre-release. This package is not yet independently published; the canonical source remains the EvolvePHP monorepo at https://github.com/josiahking/evolvephp.

## Requirements

- PHP `^8.4`
- `ext-pdo`
- `evolvephp/contracts`
- `evolvephp/database-contracts`

The adapter receives a caller-owned `PDO` instance. It does not create PDO connections, read DSNs, inspect credentials or mutate PDO attributes.

Package dependencies: `evolvephp/contracts`, `evolvephp/database-contracts`.

## Verified Backend

The repository test suite currently verifies this adapter with `pdo_sqlite` using in-memory databases. Other PDO drivers may be present locally, but they are not claimed as verified by this package documentation.

## License

BSD-3-Clause. See `LICENSE.md`.
