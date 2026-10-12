# Configuration — the full `.env` reference

Copy [.env.example](../../.env.example) to `.env` and edit. Values shown are defaults. Boot-time validation (`EnvironmentValidator`) checks the critical ones and fails with a readable message instead of a mystery crash.

> Configuring through real environment variables only (systemd `Environment=`, `docker -e`, k8s) is equally supported — `.env` is optional. When both exist, the process environment wins (Dotenv is immutable).

## Bot

| Key | Default | Notes |
|-----|---------|-------|
| `BOT_TOKEN` | — | **required**; from @BotFather. Format-validated; never echoed in error messages. `TGBASE_SKIP_BOT_VALIDATION=1` lets DB-only CLI commands run without a token |
| `BOT_MODE` | `update_direct` | one of the six modes — see [bot-modes.md](bot-modes.md) |
| `BOT_API_URL` | `https://api.telegram.org` | override for local Bot API servers |
| `POLLING_TIMEOUT` | `30` | long-poll seconds per `getUpdates` call |
| `POLLING_RESTART_INTERVAL` | `100` | batches per self-restart (memory hygiene; `0` disables) |
| `BOT_WEBHOOK_URL` | — | webhook endpoint URL (HTTPS) |
| `BOT_WEBHOOK_SECRET` | — | secret for `X-Telegram-Bot-Api-Secret-Token`; use 32+ random hex chars (`openssl rand -hex 32`) |
| `WEBHOOK_CHECK_IP` | `true` | validate `REMOTE_ADDR` against Telegram's subnets — set `false` behind Cloudflare/proxies (then the secret is your auth layer) |
| `DATA_DIR` | `AppData` | runtime state (offset, queue files, locks) |

## Plugins

| Key | Default | Notes |
|-----|---------|-------|
| `PLUGINS_DIR` | `/Plugin/` | relative to project base; absolute paths supported |
| `PLUGIN_CACHE_ENABLED` | `true` | cache discovered plugins between boots |
| `PLUGINS_RELOAD_INTERVAL` | `0` | seconds between discovery re-scans (dev convenience) |

## Language & buttons

| Key | Default | Notes |
|-----|---------|-------|
| `LANG_DRIVER` | `json` | translation file driver — ⚠️ the shipped files are PHP arrays, so `.env.example` sets `php`; a mismatch silently falls back to the key name (see [i18n.md](i18n.md)) |
| `DEFAULT_LANG` | `fa` | fallback language code |
| `LANG_DIR` | `/Language` | translation files location |
| `LANG_CACHE_TTL` | `60` | translation cache seconds |
| `BUTTONS_DIR` | `/Button` | keyboard label definition files |
| `BUTTONS_FILE` | `btn.php` | base file name |
| `BUTTONS_CACHE_TTL` | `60` | label cache seconds |
| `BUTTONS_CACHE_ENABLED` | `true` | set `false` to always re-read button definitions from files |

## Queue

| Key | Default | Notes |
|-----|---------|-------|
| `QUEUE_SAVE_TYPE` | `json` | `json` \| `redis` \| `rabbitmq` \| `memcached` |
| `QUEUE_PATH` | `AppData/queue` | json driver storage dir |
| `QUEUE_MAX_SIZE` | `0` | bounded-queue cap (0 = unlimited); full queue rejects + logs |
| `QUEUE_MAX_RETRIES` | `3` | broker operation retries per push/pop |
| `QUEUE_RETRY_DELAY` | `2` | seconds between broker retries |
| `QUEUE_REDIS_KEY` / `QUEUE_MEMCACHED_KEY` | `bot_updates` | list key per driver |
| `RABBITMQ_QUEUE` | `bot_updates` | RabbitMQ queue name |

### Redis connection

| Key | Default |
|-----|---------|
| `REDIS_HOST` | `127.0.0.1` |
| `REDIS_PORT` | `6379` |
| `REDIS_PASSWORD` | *(empty)* |
| `REDIS_DATABASE` | `0` |
| `REDIS_TIMEOUT` | `60` |

### RabbitMQ connection

| Key | Default |
|-----|---------|
| `RABBITMQ_HOST` | `127.0.0.1` |
| `RABBITMQ_PORT` | `5672` |
| `RABBITMQ_USER` | `guest` |
| `RABBITMQ_PASSWORD` | `guest` |
| `RABBITMQ_VHOST` | `/` |

### Memcached connection

| Key | Default |
|-----|---------|
| `MEMCACHED_HOST` | `127.0.0.1` |
| `MEMCACHED_PORT` | `11211` |
| `MEMCACHED_USERNAME` / `MEMCACHED_PASSWORD` | *(empty — SASL)* |

## Retry queue

| Key | Default | Notes |
|-----|---------|-------|
| `RETRY_ENABLED` | `true` | master switch |
| `RETRY_QUEUE_DRIVER` | `json` | `json` \| `redis` |
| `RETRY_MAX_ATTEMPTS` | `3` | then drop + log |
| `RETRY_BASE_DELAY` | `2` | seconds; doubled per attempt; overridden by Telegram's `retry_after` |
| `RETRY_MAX_SIZE` | `1000` | bounded queue cap |
| `RETRY_REDIS_KEY` | `bot_retry_queue` | when driver is redis |

## Cache & session

| Key | Default | Notes |
|-----|---------|-------|
| `CACHE_DRIVER` | `array` | `array` \| `file` \| `redis` \| `memcached` — unsupported/unavailable drivers fall back to `array` with a logged warning |
| `CACHE_PREFIX` | `tgbase_` | key prefix for all cache stores |
| `CACHE_PATH` | system temp | file-driver directory |
| `CACHE_TTL` | `300` | default TTL seconds when `put()`/`remember()` get no explicit TTL |
| `SESSION_TTL` | `3600` | session lifetime seconds |
| `SESSION_PREFIX` | `tgbase:session:` | session key prefix |

## Database

| Key | Default | Notes |
|-----|---------|-------|
| `DATABASE_ENABLE` | `false` | gates all DB bootstrapping (CLI works without a DB) |
| `DB_DRIVER` | `mysql` | any PDO driver (`sqlite` for local dev) |
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `3306` | |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | — | |

## Multi-bot

| Key | Default | Notes |
|-----|---------|-------|
| `BOT_EXTRA_BOTS` | — | extra bots in one process — `name1:token1,name2:token2` (split on the first colon); see [multi-bot.md](multi-bot.md). Works in `.env` and in the process environment |

## Scheduler & cron

| Key | Default | Notes |
|-----|---------|-------|
| `SCHEDULER_LOCK_TTL` | `300` | task lock seconds |
| `SCHEDULER_LOCK_TABLE` | `task_locks` | lock backend table |
| `SCHEDULE_FILE` | `bin/tasks.php` | task file used by `bin/scheduler.php` (path override) |
| `CRON_WORKER` | `10` | concurrent slots for `cronjob_queue` (file-locked) |
| `CRON_MAX_TIME` | `50` | max seconds per cron tick for the worker |

## Logging

| Key | Default | Notes |
|-----|---------|-------|
| `LOG_ENABLED` | `true` | master switch |
| `LOG_LEVEL` | `debug` | Monolog levels |
| `LOG_DIR` | `./logs` | **relative names only** — absolute paths and `..` are rejected (path-traversal protection) |
| `LOG_FILE` | `log.txt` | |

## Admins

| Key | Default | Notes |
|-----|---------|-------|
| `ADMINS_WHITELIST` | — | comma-separated Telegram user ids, used by `AdminGuardMiddleware` |

## Security notes

- Never commit `.env`; the provided `.gitignore` excludes it (`*.secret` and `secrets.json` too).
- `EnvironmentValidator` error messages name the missing key, never its value.
- Webhook secrets are compared with `hash_equals()` (constant time).
- There is deliberately **no `VERIFY_TLS=false` knob** — TLS verification to api.telegram.org is always on.


## LOG_API_TRACE (2026-10)

```dotenv
LOG_API_TRACE=1            # one delivery line per outgoing Bot API call
LOG_API_TRACE_POLL=0       # also trace getUpdates long polls (noisy)
```

Line shape: `📡 sendMessage → ok 128ms chat 82267967` (or
`… → FAIL 91ms chat 123 | 403 Forbidden: …`). Payloads are never logged —
only method, chat_id, duration and the error summary. Registers via the
library's static `Telegram::onApiCall()` hook, so it covers every bot in
`BOT_EXTRA_BOTS` too. Keep it off for quiet high-volume logs.
