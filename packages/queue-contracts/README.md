# EvolvePHP Queue Contracts

Runtime-neutral queue transport contracts for EvolvePHP 2.

Package: `evolvephp/queue-contracts`.

EvolvePHP 2 is pre-release. This package requires PHP `^8.4`, depends only on `evolvephp/contracts`, and exposes public experimental API that may change before stable release. It is not yet independently published; the canonical source remains the EvolvePHP monorepo at https://github.com/josiahking/evolvephp.

`QueuePublisher` publishes an opaque string payload with flat string metadata. The envelope performs no encoding or serialization, permits an empty payload, and preserves accepted payload and metadata exactly. Metadata key order and string values are retained.

`QueueReceiver::receive()` makes one non-blocking attempt. It returns `null` when no delivery is currently available. It does not poll, wait, sleep, back off, or retry. Provider/backend failures from publishing and receiving cross the portable `QueueException` boundary. `QueueException::operation()` identifies the attempted portable operation, and `category()` provides its broad portable failure category.

A `Delivery` is a transient capability to inspect one message and settle it with `acknowledge()` or `reject()`. Provider/backend failures from acknowledgement and rejection also cross the `QueueException` boundary. Application handling and execution cleanup/reset happen before adapter settlement. The base contract does not prescribe what rejection means for a backend. A failed settlement can have an uncertain transport outcome, so repeating it is not guaranteed safe. The contract provides no automatic retry and makes no exactly-once delivery claim.

This package contains contracts only. It provides no broker implementation or worker loop and has no Insight or Observe coupling. Concrete adapters must document their settlement mapping and backend behavior.

This package is provided under the BSD-3-Clause licence. See `LICENSE.md`.
