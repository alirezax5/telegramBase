# Reliability — what actually happens when things crash

This document is unusual for a framework's docs: everything in it was **measured, not assumed**. During integration testing (phases 3.2–3.3), workers were killed with `SIGKILL` at every stage of every pipeline — during fetch, during queue push, mid-handler, after handler but before ack, before/after offset writes — and the outcome of every scenario was recorded. This page is the distilled result, and it drives both the framework's design and the advice below.

## Delivery guarantees per path

| Path | Guarantee | Why | Duplicate window |
|------|-----------|-----|------------------|
| Polling (`update_direct`) | **at-least-once** | the `getUpdates` offset is persisted only *after* `Processor::handle()` returns | crash after side effects, before offset write |
| Webhook (`webhook_direct`) | **at-least-once** | the `200` is sent only after `handle()` returns; Telegram retries anything else | crash after side effects, before the 200 |
| Webhook → queue (`webhook_queue`) | **at-least-once into the broker** | `200` only after a successful enqueue | broker outage between enqueue and 200 |
| Queue consumer — **RabbitMQ** (v2.4.0) | **at-least-once** | ack-after-process: unacked messages are redelivered by the broker | crash after handling, before ack |
| Queue consumer — Redis / JSON / Memcached | **at-most-once** | destructive pop — the message is removed at pop time | none (a crash mid-processing loses the job) |
| Retry queue | **at-least-once, bounded** | items re-dispatched with backoff until `RETRY_MAX_ATTEMPTS` | same as its parent path |

Two honest observations from the crash tests:

- **Exactly-once does not exist.** Not in TelegramBase, not in any system built on top of a network. You choose between "never lose" (at-least-once, duplicates possible on crash) and "never duplicate" (at-most-once, losses possible on crash). The framework gives you both families and documents which is which.
- **The duplicate windows are narrow but real.** They only open on a crash at the exact wrong moment. They close with idempotent handlers.

## Making handlers idempotent (the practical 90%)

At-least-once is only painful when the same update is *acted on* twice. One guard makes every window harmless:

```php
public function onMessage($message, Telegram $tg): void
{
    $update  = $tg->getInputData();
    $uId     = $update->update_id ?? null;

    // Cheap, atomic, per-update marker — works with any cache driver.
    if (!empty(cache()->get("upd:{$uId}"))) {
        return;                       // already processed (redelivery) — skip
    }

    // ... do the actual work ...

    cache()->set("upd:{$uId}", 1, 3600);   // remember for an hour
}
```

Store the marker *before* irreversible side effects where possible, and accept that a crash between marker and side effect converts a duplicate into a loss — pick the trade-off that fits the action (sending a message twice is usually cheaper than never sending it).

## Crash behaviour by component

| Component | Crash scenario | Observed behaviour |
|-----------|---------------|--------------------|
| Polling worker (`update_direct`) | `SIGKILL` mid-batch | offset unchanged → same updates redelivered on restart → reprocessed (idempotency matters) |
| Polling worker | `SIGKILL` between batches | clean; at most the current update redelivered |
| Queue fetcher (`queue.php`) | `SIGKILL` after pop, before push | update still on Telegram's side (offset not advanced) → redelivered |
| Queue fetcher | `SIGKILL` after push, before offset write | update in broker **and** still on Telegram's side → producer restart would duplicate it into the broker (bounded by `QUEUE_MAX_SIZE`; consumers' idempotency absorbs it) |
| RabbitMQ worker (pre-v2.4.0) | `SIGKILL` mid-processing | job **permanently lost** (ack had already been sent) — fixed in v2.4.0 |
| RabbitMQ worker (v2.4.0+) | `SIGKILL` mid-processing | job **redelivered** to the next worker (unacked) |
| Redis/JSON worker | `SIGKILL` mid-processing | job permanently lost (destructive pop) — by design, documented |
| Webhook (FPM) | handler exceeds ~60 s | Telegram times out and retries; if the handler eventually completes, the retry duplicates it — keep webhook handling fast or use `webhook_queue` |
| Broker down (any queue mode) | connection refused | workers back off exponentially (1 s → 60 s), reconnect and resume; fetcher keeps the offset until pushes succeed |

## Bounded resources

- **Queues are bounded** (`QUEUE_MAX_SIZE`, `RETRY_MAX_SIZE`): when full, pushes are rejected and logged — an outage cannot fill your disk silently.
- **Memory is bounded**: the polling worker self-restarts every `POLLING_RESTART_INTERVAL` batches (configurable, `0` disables).
- **Logs are path-traversal-safe**: `LOG_DIR`/`LOG_FILE` cannot point outside the project.

## Error taxonomy used by the retry queue

`RetryableException::isRetryable()` classifies errors so only *transient* failures are retried:

- HTTP `429 Too Many Requests` (honoring Telegram's `parameters.retry_after` when present)
- HTTP `5xx` / "server error"
- timeouts (`timeout`, `timed out`, `connect`)
- `cURL error N`
- any `RetryableException` subclass you throw yourself

Everything else (400 "chat not found", 403 "bot blocked", ...) is permanent — logged, not retried.

## Testing your own reliability posture

The shipped test suite includes the crash-relevant primitives (offset storage atomicity, queue drivers, retry lifecycle, webhook dispatcher validation paths):

```bash
php tests/run.php --filter=Queue     # queue driver behaviour
php tests/run.php --filter=Offset    # offset storage
php tests/run.php --filter=Retry     # retry lifecycle
php tests/run.php --filter=Webhook   # webhook validation layers
```

For end-to-end confidence, repeat the integration approach on your own deployment: register the webhook, send a marker message, `kill -9` the worker mid-flight, and verify redelivery in `getWebhookInfo` / logs.
