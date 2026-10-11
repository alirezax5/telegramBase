# Queue pipeline — when one process isn't enough

## Why queue at all?

Inline processing has a simple weakness: the process that *receives* the update must also *finish* handling it. If a handler uploads a 50 MB video or waits on a slow third-party API, the receiver is blocked (and in webhook mode, Telegram is waiting for its 200). The queue pipeline splits the work:

```
producer                          broker                    consumers (N processes)
──────                            ──────                    ────────
queue.php / webhook.php  ──push──► Redis / RabbitMQ ──pop──► bot.php workers
(pick up updates fast,            (holds updates while       (do the slow work,
 advance offset / 200)             nobody is around)          ack after processing)
```

Benefits: fast receipt (Telegram never waits on your handlers), horizontal scaling (run more workers), and crash isolation (a dying worker doesn't affect the receiver).

## Choosing a driver

| Driver | `QUEUE_SAVE_TYPE` | Needs | Pop behaviour | Guarantee on worker crash |
|--------|-------------------|-------|---------------|---------------------------|
| JSON | `json` *(default)* | nothing | destructive file read | **at-most-once** — job lost if crash mid-processing |
| Redis | `redis` | `redis` extension + server | `LPOP` (destructive) | **at-most-once** — job lost if crash mid-processing |
| Memcached | `memcached` | `memcached` extension + server | destructive get/delete | **at-most-once** |
| RabbitMQ | `rabbitmq` | `amqp` extension + server | `basic_get` + **ack-after-process** (v2.4.0) | **at-least-once** — unacked job is redelivered |

Pick RabbitMQ when losing a single update is unacceptable; pick Redis/JSON for throughput and simplicity when the occasional loss is tolerable (or when updates are cheap to reconstruct).

## The RabbitMQ ack contract (v2.4.0)

Since v2.4.0 the RabbitMQ driver uses **ack-late** semantics:

1. `pop()` fetches a message and holds its delivery tag — the message is *not* acknowledged yet.
2. Your handler runs.
3. The worker calls `ack()` **after** processing. Only then does the broker delete the message.
4. If the worker dies anywhere between 1 and 3, the broker redelivers the message to the next worker.

Code-level, the worker does exactly this:

```php
$update = $queue->pop();
try {
    $processor->handle($update);
} finally {
    $queue->ack();   // success → done; handled exception → discarded (no poison loops)
}
```

Consequences worth knowing:

- **Duplicate processing is possible** (crash after handling but before the ack — the broker can't tell). Make handlers idempotent: store `update_id` before acting, skip if already seen. See [reliability.md](reliability.md).
- A message that fails to decode is acked immediately (discarded) so it cannot poison the queue.
- Custom queue drivers don't need to implement `ack()` — `QueueManager::ack()` is a no-op for drivers without broker-side acknowledgement.

Configuration lives under the `rabbitmq` section of `.env` (host, port, user, password, vhost, queue name) — see [.env.example](../../.env.example).

## Running the pipeline

`update_queue` (polling producer):

```bash
# terminal 1 — fetcher: long-polls Telegram, pushes to the broker
php queue.php

# terminal 2..N — consumers: pop + process + ack
php bot.php
```

`webhook_queue` (webhook producer):

```bash
# web server receives Telegram POSTs → validates → enqueues → 200
# (webhook.php handles this automatically when BOT_MODE=webhook_queue)

# terminal 1..N — consumers
php bot.php
```

Run all of them under a supervisor. Workers reconnect to the broker automatically with exponential backoff (`BACKOFF_INITIAL=1s` … `BACKOFF_MAX=60s`), so you can restart Redis/RabbitMQ without touching the workers.

## Bounded queues

`QUEUE_MAX_SIZE` (default per [.env.example](../../.env.example)) caps how many updates may sit in the broker. When the limit is reached, new pushes are **rejected and logged** (WARNING), never silently dropped into an unbounded pile. This protects the disk/RAM when workers are down for a long time. Size 0 disables the cap.

## The retry queue (separate from the main queue)

The retry queue handles *transient handler failures* (HTTP `429`, `5xx`, timeouts, curl errors) for all modes, not just queued ones:

- `Processor::handle()` catches a retryable error → pushes the update into `RetryQueue`.
- Long-running workers drain due items on their maintenance tick (every loop iteration in queue workers).
- Backoff doubles per attempt; when Telegram supplies `retry_after`, that exact value is honored (`RetryableException::retryAfter`).
- After `RETRY_MAX_ATTEMPTS` the item is dropped and logged.

Your handlers opt in with the `tg_retryable()` helper:

```php
use function alirezax5\TelegramBase\App\Queue\tg_retryable;

$resp = tg_retryable($tg->sendMessage($chatId, 'Hello!'), 'sendMessage');
// ok=true            → returned unchanged
// 429 / 5xx          → RetryableException thrown → retry queue
// other 4xx          → returned unchanged (permanent errors are not retried)
```

Configuration: `RETRY_ENABLED`, `RETRY_QUEUE_DRIVER` (`json`|`redis`), `RETRY_MAX_ATTEMPTS`, `RETRY_BASE_DELAY`, `RETRY_MAX_SIZE` — defaults in [.env.example](../../.env.example).
