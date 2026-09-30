# EvolvePHP Queue Memory

Object-local in-memory queue adapter for EvolvePHP 2.

Package: `evolvephp/queue-memory`.

EvolvePHP 2 is pre-release. This experimental package requires PHP `^8.4` and `evolvephp/queue-contracts`. It is intended for tests and local development, is not yet independently published, and its canonical source remains the EvolvePHP monorepo at https://github.com/josiahking/evolvephp.

## Behavior

Each `InMemoryQueue` instance owns its own queue state. Separate instances are isolated. Messages are non-durable, exist only in that adapter object's lifetime, and are not shared between processes. This is not a production broker and provides no persistence or cross-process communication.

Messages are FIFO within each exact queue name. `receive()` performs one non-blocking attempt and returns `null` when that queue has no available message. Publishing preserves the exact `MessageEnvelope` object, including its payload and metadata.

Receiving removes a message from the available FIFO and returns a delivery capability. `acknowledge()` is terminal successful settlement. For this adapter, `reject()` means terminal discard: it does not requeue, retry, or dead-letter the message. This adapter-specific mapping does not change the portable queue contract. Repeated settlement calls on the same delivery are harmless no-ops.

An unsettled abandoned delivery is not restored to the queue. There is no automatic requeue, retry, or dead-letter behavior. The adapter makes no exactly-once delivery claim.

This package is provided under the BSD-3-Clause licence. See `LICENSE.md`.
