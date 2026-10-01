# EvolvePHP HTTP Client

`evolvephp/http-client` provides the experimental PSR-18 outbound HTTP client composition foundation for EvolvePHP 2. It is separate from the server-side lifecycle, routing and middleware responsibilities of `evolvephp/http`.

Applications supply a concrete PSR-18 `ClientInterface` transport. `MiddlewareClient` decorates that transport with ordered `ClientMiddleware` instances: the first supplied middleware is entered first, the final transport runs after the last middleware, and responses unwind in reverse order. With zero middleware, requests delegate directly to the supplied transport.

The package uses PSR-7 `RequestInterface` and `ResponseInterface` messages. Middleware may pass an immutable derived request downstream, inspect or replace a returned response, or short-circuit with a valid response. Normal 4xx and 5xx responses remain responses. PSR-18 request, network and client exceptions propagate unchanged by default.

No HTTP client implementation is bundled. The package supplies no retry, timeout, redirect, TLS, proxy or authentication policy; no async or promise abstraction; and no global client discovery or registry. It does not require PSR-17 because this composition layer receives already-created messages and does not construct requests, responses, streams or URIs. Callers and adapters that create messages own their PSR-17 dependencies.

This package requires PHP `^8.4`.

The public API is experimental. EvolvePHP 2 is pre-release, and this package is not yet independently published; the canonical source remains the EvolvePHP monorepo at https://github.com/josiahking/evolvephp.

Dependencies: `psr/http-client` and `psr/http-message`.

License: BSD-3-Clause. See `LICENSE.md`.
