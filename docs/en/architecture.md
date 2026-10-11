# Architecture — how the pieces fit together

## The boot sequence

Every entry point (CLI or web) constructs one object: `Core`.

```php
$core = new Core(CoreMode::FULL);   // FULL = boot plugins too
$core->run();
```

The constructor runs the boot chain, in order:

| Step | Class | What happens |
|------|-------|--------------|
| 1 | `Bootstrap::boot()` | Loads `.env` (via `EnvHandler`), validates the environment (`EnvironmentValidator`), prepares paths (`Paths`, `AppData/`), connects cache |
| 2 | `BotManager::getInstance()->get()` | Creates the `Telegram` SDK instance from `BOT_TOKEN` (singleton — every component shares one instance) |
| 3 | `PluginsBootstrap::boot()` *(FULL only)* | Scans `PLUGINS_DIR`, instantiates plugin classes, sorts by priority, builds the `PluginHandler` registry |

`CoreMode::UPDATES_ONLY` (used by `queue.php`) skips step 3 — the fetcher only pushes updates into the queue, it never dispatches plugins itself.

## The update flow

```
                 ┌──────────────────────────────────────────────┐
                 │                  Core::run()                 │
                 └──────────────────────────────────────────────┘
                                    │
              route by BOT_MODE + SAPI (CLI vs HTTP) — see bot-modes.md
                                    │
        ┌───────────────┬───────────┴──────────┬─────────────────┐
        ▼               ▼                      ▼                 ▼
   PollingLoop    WebhookDispatcher        QueueWorker       CronManager
   (getUpdates    (validate + dispatch     (pop from queue   (cron slots →
    in a loop)     one webhook payload)     + process)        poll/worker)
        │               │                      │
        └───────────────┴──────────┬───────────┘
                                   ▼
                              Processor::handle($update)
                                   │
                     normalize to object (shape-preserving —
                     list arrays like photo sizes stay arrays)
                                   │
                                   ▼
                       MiddlewarePipeline (optional)
                                   │
                                   ▼
                     PluginHandler::runAll($update, $telegram)
```

Key points:

- **One `Processor` per pipeline stage.** Polling, webhook and queue paths each build one; all of them attach the same middleware pipeline you registered on `Core`.
- **Shape-preserving normalization.** Updates arriving as arrays (from queue drivers) are converted to objects recursively — *list* arrays (photo sizes, entities, inline keyboard rows) stay arrays, associative maps become objects. This matters because Telegram models fields like `photo` as arrays and plugins do `foreach` over them.
- **Failures inside `handle()`** are caught, logged with update context, and — when the error looks transient (`429`, `5xx`, timeout, curl error) — pushed into the `RetryQueue` with exponential backoff.

## Component map of `App/`

| Directory | Concern | Key classes |
|-----------|---------|-------------|
| `Attributes/` | PHP 8 attributes | `ChatType` (filter plugins by private/group/channel) |
| `Bootstrap/` | Boot chain | `Bootstrap`, `PluginsBootstrap`, `QueueBootstrap` |
| `Bot/` | SDK instance | `BotManager` (singleton), `BotConfig` |
| `Button/` | Keyboards | `Keyboard` (fluent builder), `ButtonManager` (i18n labels) |
| `Cache/` | Caching | `CacheManager` — array / file / redis / memcached / database drivers |
| `Cli/` | CLI tooling | `Console`, `Maker` (scaffolding), `Migrate`, `Doctor`, `PluginsList`, `RetryDrain` |
| `Config/` | Typed config | `Config` (registry), `AppConfig`, `SessionConfig`, ... |
| `Connection/` | Brokers | `ConnectionManager` (Redis / Memcached / RabbitMQ, singletons with liveness checks) |
| `Core.php` | Entry | Mode routing, middleware registration, `run()`, `runFetchQueueUpdate()` |
| `Cron/` | Cron modes | `CronManager`, `CronWorker`, `WorkerLocker` (file-lock slots) |
| `Database/` | Persistence | `DatabaseManager` (Eloquent), `MigrationRunner` |
| `Enum/` | Enums | `CoreMode` (FULL / UPDATES_ONLY) |
| `Environment/` | Env | `EnvHandler` (typed getters), `EnvironmentValidator` (boot-time validation) |
| `Language/` | i18n | `Language` loader + `__()` helper |
| `Logger/` | Logging | `LogHandler` (Monolog wrapper), `LoggerConfig` (path-traversal-safe) |
| `Middleware/` | Pipeline | `MiddlewareInterface`, `MiddlewarePipeline`, built-ins: `RateLimitMiddleware`, `AdminGuardMiddleware`, `AuditLogMiddleware` |
| `Plugin/` | Dispatch | `PluginHandler` (discovery + run), `PluginInterface` (your plugins implement it) |
| `Queue/` | Queues | `QueueManager` (driver facade), drivers in `Drivers/` (json/redis/rabbitmq/memcached), `RetryQueue`, `RetryableException`, `QueueWorker` |
| `Scheduler/` | Tasks | `Scheduler` (cron expressions), `TaskLock` |
| `Session/` | State | `SessionManager::start($chatId)` → `Session` (cache-backed, TTL) |
| `Shared/` | Per-update scratch | `SharedManagement` (cleared between iterations) |
| `Storage/` | State files | `OffsetStorage` (getUpdates offset, `LOCK_EX` writes) |
| `Update/` | Update flow | `Fetcher`, `PollingLoop`, `Processor`, `WebhookDispatcher`, `WebhookResponse` |

## The three long-running process shapes

1. **Polling worker** (`bot.php`, mode `update_direct`) — loops `getUpdates` → `Processor::handle()` → advance offset. Self-restarts every `POLLING_RESTART_INTERVAL` batches to keep memory bounded; run it under a supervisor.
2. **Webhook worker** (`webhook.php`, FPM/Apache) — stateless per-request; Telegram only needs a timely status code. Heavy work belongs in a queue (mode `webhook_queue`).
3. **Queue workers** (`queue.php` producer + `bot.php` consumer, modes `*_queue`) — fetcher pushes, workers pop. Any number of consumer processes; the broker spreads updates between them.

## Where state lives

| State | Location | Notes |
|-------|----------|-------|
| getUpdates offset | `AppData/lastupdate.txt` | `OffsetStorage`, atomic writes; only polling modes touch it |
| Queue payloads | broker (or `AppData/queue/` for the json driver) | |
| Retry items | `AppData/retry/` or Redis (per `RETRY_QUEUE_DRIVER`) | |
| Sessions | cache driver (per `CACHE_DRIVER`) | TTL via `SESSION_TTL` |
| Logs | `logs/` (per `LOG_DIR`) | path traversal rejected by config validation |

`AppData/` is runtime-only and gitignored — recreate it by simply running the bot.
