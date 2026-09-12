# EvolvePHP Bridge Symfony

`evolvephp/bridge-symfony` is the Symfony host Bridge adapter for embedded EvolvePHP 2 delegation.

This package is experimental and targets PHP `^8.4`. EvolvePHP 2 is pre-release, and this package is not yet independently published. The canonical source remains the EvolvePHP monorepo at https://github.com/josiahking/evolvephp.

## Responsibility

The package translates an explicitly delegated Symfony `Request` into the same-process PSR Bridge boundary and translates safe PSR responses back to Symfony `Response` objects.

It only owns the Symfony host adapter surface:

- explicit request delegation from host code;
- safe scalar and nested array query and form input translation;
- narrow request and response header allowlists;
- authenticated principal identifier extraction from Symfony security token storage;
- quarantine visibility from the embedded same-process Bridge.

It does not provide a Symfony bundle, automatic route registration, `RequestStack` lookup, catch-all routing, session or cookie propagation, uploaded-file bridging, object coercion, collection conversion, remote fallback, retries, worker recycling, migration planning, cutover orchestration or package publication.

## Dependencies

`evolvephp/bridge-contracts`, `evolvephp/bridge-psr`, `psr/http-factory`, `psr/http-message`, `symfony/http-foundation` and `symfony/security-core`

## Licence

BSD-3-Clause. See `LICENSE.md`.
