# Bot modes — the six ways to run a TelegramBase bot

`BOT_MODE` in `.env` is the single most important configuration value. It decides *how updates arrive* (polling vs webhook) and *how they are processed* (inline vs queued).

## The modes

| Mode | Update source | Processing | Processes you run |
|------|--------------|------------|-------------------|
| `update_direct` *(default)* | `getUpdates` long-poll | inline, same process | `php bot.php` under a supervisor |
| `update_queue` | `getUpdates` long-poll | async via queue | `php queue.php` (fetcher) + one or more `php bot.php` (workers) |
| `webhook_direct` | Telegram webhook POST | inline, inside the HTTP request | web server only (`webhook.php`) |
| `webhook_queue` | Telegram webhook POST | async via queue | web server (`webhook.php`) + `php bot.php` worker(s) |
| `cronjob_update` | `getUpdates` (polling) | inline, **self-healing daemon** — the first tick starts an endless loop, later ticks no-op while it lives | crontab `* * * * * php bin/scheduler.php` |
| `cronjob_queue` | queue only (no Telegram polling) | async batch — each tick consumes for up to `CRON_MAX_TIME` (default 50 s), then exits | crontab + `CRON_WORKER` slots |

Mental model:

- **`update_*` / `cronjob_*`** modes pull updates themselves (your server talks to Telegram).
- **`webhook_*`** modes receive updates (Telegram talks to your server).
- **`*_direct`** processes the update right where it arrives — simplest, and fine for most bots.
- **`*_queue`** decouples receiving from processing — use it when handlers are slow (uploads, external APIs) or when you want horizontal scaling.
- **`cronjob_*`** modes run from plain crontab — no supervisor, no systemd, no Docker required.

## Cron modes in depth

Both cron modes share the same safety mechanism: on every tick the framework tries to acquire a **worker slot** — an exclusive `flock()` on a lock file under `data/cron-slots/` (`WorkerLocker`). If all `CRON_WORKER` slots are busy, the tick logs `All N worker slots busy` and exits within milliseconds — concurrent ticks can never double-process anything.

### `cronjob_update` — a self-healing daemon, not a batch

Despite the name, a tick does **not** run "one batch". The first tick acquires the slot and starts the same endless polling loop as `update_direct` (`set_time_limit(0)` + long-poll), and the tick never finishes — by design:

1. Tick 1 → daemon starts, slot stays locked.
2. Ticks 2..N → find the slot busy, exit in milliseconds (measured: ~30–45 ms). This is *normal*, not an error.
3. Daemon dies (crash, OOM, reboot) → the next cron tick starts a fresh daemon automatically. **Cron acts as the supervisor.**

Verified live: a daemon started at 09:24:01, processed a real message inline, survived several minute boundaries, shut down gracefully on SIGTERM within one long-poll cycle (~12 s — the stop signal is honored as soon as the current long-poll returns), released the lock, and the very next tick restarted it.

### `cronjob_queue` — a bounded batch worker

Each tick acquires a slot and consumes from the queue for at most `CRON_MAX_TIME` seconds (default 50), then exits cleanly with `Limited worker finished: N updates`. Nothing polls Telegram in this mode — the queue must be fed by a producer (`webhook_queue`, or your own `queue.php` run).

Verified live: a real message pushed into RabbitMQ at 09:22:49 was popped and dispatched by the tick that was already inside its 50-second window (plugin `onMessage` at 09:22:49.6, `Limited worker finished: 1 updates` at 09:22:51), and the queue drained back to 0/0. Delivery semantics are inherited from the queue driver — at-least-once with RabbitMQ since v2.4.0.

### Cron configuration

| Env | Default | Meaning |
|-----|---------|---------|
| `CRON_WORKER` | `1` | number of concurrent worker slots (flock files in `data/cron-slots/`) |
| `CRON_MAX_TIME` | `50` | `cronjob_queue` batch window in seconds — keep it below your cron interval so ticks don't overlap |

## Entry points and the SAPI guard

| File | Runs as | Purpose |
|------|---------|---------|
| `bot.php` | CLI | polling (`update_direct`), queue consumer (`*_queue`), cron callbacks |
| `queue.php` | CLI | queue *producer* — long-polls Telegram and pushes updates into the queue (`update_queue`) |
| `webhook.php` | HTTP | receives Telegram webhook POSTs (`webhook_*`) |
| `bin/tgbase` | CLI | diagnostics, scaffolding, migrations |
| `bin/scheduler.php` | CLI | cron tick runner (`cronjob_*` + scheduled tasks) |

**Important — the SAPI guard (v2.4.0):** an HTTP request can only serve webhook-style work. If `webhook.php` is executed while `BOT_MODE` is a CLI-only mode, the framework refuses to run an infinite loop inside your web worker:

| `BOT_MODE` on `webhook.php` | Behaviour |
|------------------------------|-----------|
| `webhook_direct` | validate → process inline → `200` |
| `webhook_queue` | validate → enqueue → `200` |
| `update_direct` | validate → process inline → `200` (a warning is logged — you probably meant `webhook_direct`) |
| `update_queue`, `cronjob_*` | **HTTP 500** with an explanatory message — no loop, no hang |

Background: before this guard existed, pointing the webhook at a bot configured for `update_direct` silently started an infinite `getUpdates` loop inside the PHP-FPM worker. Telegram never got a response (`Read timeout expired` → endless retries) and FPM killed the worker at `request_terminate_timeout` (502). The guard turns that silent hang into a loud, fast failure. You can test the detection logic directly with `Core::isHttpSapi($sapi)`.

> Rule of thumb: **CLI modes run from the terminal, webhook modes run from the web.** The framework enforces the boundary for you.

## Delivery semantics at a glance

| Path | Guarantee | Duplicate window |
|------|-----------|------------------|
| `webhook_direct` | at-least-once (Telegram retries until it gets a 2xx) | crash *after* `handle()` but before the 200 |
| `update_direct` | at-least-once (offset advances only after processing) | crash *after* side effects but before the offset write |
| `webhook_queue` (producer side) | the update is in the broker once you got your 200 | enqueue retries on broker failure |
| queue consumer — **RabbitMQ** | at-least-once (ack after processing, v2.4.0) | crash between processing and ack |
| queue consumer — Redis / JSON / Memcached | at-most-once (destructive pop — the job is gone at pop time) | a crash mid-processing loses the job |

Full crash-test evidence and idempotency guidance: [reliability.md](reliability.md).

`cronjob_update` behaves exactly like `update_direct` (one message processed inline before the offset advances); `cronjob_queue` behaves exactly like the queue consumer of its driver (at-least-once with RabbitMQ, at-most-once with Redis/JSON/Memcached).

## Choosing a mode — practical advice

- **Small bots, dashboards, prototypes** → `update_direct`. One process, zero infrastructure.
- **Slow handlers (file processing, third-party APIs), multiple instances** → `webhook_queue` or `update_queue` with Redis/RabbitMQ.
- **Shared hosting without CLI daemons** → `cronjob_update` (crontab becomes the supervisor) or `webhook_direct`.
- **Want exactly-once?** Nobody has it. TelegramBase gives you *at-least-once* paths that never lose updates, and *at-most-once* paths that never duplicate — pick per use case, and make handlers idempotent (see [reliability.md](reliability.md)).
