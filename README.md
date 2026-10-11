# TelegramBase

**English** · [فارسی](README.fa.md) · [中文](README.zh.md) · [Русский](README.ru.md) · [Français](README.fr.md) · [Português](README.pt.md) · [العربية](README.ar.md)

A modular PHP 8.1+ mini-framework for building Telegram bots — with a plugin system, queue backends, webhook validation, sessions, middleware, scheduler, migrations, and CLI scaffolding. Built on Illuminate (Laravel) components instead of reinventing them.

## What is this, in one paragraph?

TelegramBase takes care of the *plumbing* every Telegram bot needs — receiving updates (polling or webhook), validating webhook requests, dispatching updates to your code, retrying failed API calls, and talking to queue backends like Redis and RabbitMQ — so you only write the *interesting* part: small PHP classes called **plugins** that react to messages. Drop a class into `Plugin/`, and the framework discovers and runs it. That's the whole idea.

## Why does it exist?

Writing a bot "directly against the API" is easy until real traffic arrives. Then you need answers to questions like: *What happens if my worker crashes mid-update? Where do webhook requests get validated? How do I retry a `429 Too Many Requests` without losing the update? Can I move heavy work off the HTTP request?* TelegramBase answers all of these up front:

- **Six operation modes** (`update_direct`, `update_queue`, `webhook_direct`, `webhook_queue`, `cronjob_update`, `cronjob_queue`) — switch between polling and webhook, inline and queued processing, by changing one `.env` value.
- **Honest delivery semantics** — the framework was crash-tested end-to-end (SIGKILL inside every stage of the pipeline). Every mode's guarantee is documented: webhook/direct paths are *at-least-once*, the RabbitMQ pipeline is *at-least-once* (ack-after-process), destructive drivers (Redis/JSON/Memcached) are *at-most-once*. See [docs/reliability.md](docs/en/reliability.md).
- **Guarded against real misconfigurations** — running a CLI-only mode behind the webhook entry point is rejected with HTTP 500 instead of hanging your web worker in an infinite polling loop (a bug we actually hit in production testing).
- **Batteries included** — sessions, keyboard builder, i18n, middleware (rate-limit / admin-guard / audit-log), scheduler, Eloquent migrations, bounded queues, automatic retry with Telegram's `retry_after`.

## Requirements

- PHP 8.1+
- Composer
- Extensions: `json`, `mbstring`, `curl`
- Optional: `redis`, `memcached`, `amqp` (queue/cache backends)
- Optional: MySQL/MariaDB (database, Eloquent migrations)

## Quick Start

```bash
git clone https://github.com/alirezax5/telegramBase.git
cd telegramBase
composer install
cp .env.example .env
# Edit .env — set BOT_TOKEN (get one from @BotFather)
php bot.php
```

Send your bot `/start` — the starter plugin in `Plugin/start.php` answers. Then write your own plugin (see [docs/plugins.md](docs/en/plugins.md)).

## How an update flows

```
Telegram
   │
   ├─ getUpdates long-poll ──► bot.php ──┐
   │                                     │
   └─ webhook POST ──► webhook.php ──────┤
                                         ▼
                                    Core::run()
                                         │
                       validate (webhook)│mode routing
                                         ▼
                                    Processor
                                         │
                             middleware pipeline
                                         ▼
                              your plugins (Plugin/)
```

## Documentation

Everything lives in `docs/` — start with the overview:

| Document | What's inside |
|----------|---------------|
| [docs/overview.md](docs/en/overview.md) | What the framework is, what it does, what it deliberately doesn't do |
| [docs/architecture.md](docs/en/architecture.md) | Boot sequence, update flow, component map of `App/` |
| [docs/getting-started.md](docs/en/getting-started.md) | Install → first plugin → deploy, step by step |
| [docs/bot-modes.md](docs/en/bot-modes.md) | All six `BOT_MODE`s, entry points, which processes to run |
| [docs/plugins.md](docs/en/plugins.md) | Writing plugins: handlers, priority, `#[ChatType]`, discovery |
| [docs/queue-pipeline.md](docs/en/queue-pipeline.md) | Queue backends, workers, retry queue, ack semantics |
| [docs/webhook.md](docs/en/webhook.md) | Webhook setup, the 5 validation layers, proxy/CDN notes |
| [docs/reliability.md](docs/en/reliability.md) | Crash-test results: delivery guarantees per mode, idempotency guidance |
| [docs/configuration.md](docs/en/configuration.md) | Full `.env` reference |
| [docs/features.md](docs/en/features.md) | Sessions, keyboards, middleware, cache, i18n, scheduler, database |
| [docs/caching.md](docs/en/caching.md) | CacheManager deep dive: drivers, TTLs, flush reach, cross-process matrix (live-verified) |
| [docs/sessions.md](docs/en/sessions.md) | Per-chat session state: API, multi-step flows, security notes |
| [docs/i18n.md](docs/en/i18n.md) | Translations: file layout, `__()` helper, per-user language, caching |
| [docs/buttons.md](docs/en/buttons.md) | Keyboard builder + file-defined menus (`btn()`), callback handling |
| [docs/multi-bot.md](docs/en/multi-bot.md) | Multiple bots in one process: `BotManager`, `BOT_EXTRA_BOTS`, token stack (live-verified) |
| [docs/testing.md](docs/en/testing.md) | Offline suite, live Telegram tests, smoke test, CI |
| [docs/troubleshooting.md](docs/en/troubleshooting.md) | Common symptoms and fixes, deployment gotchas |

Other useful files:

- [CHANGELOG.md](CHANGELOG.md) — what changed in each version
- [llms.txt](llms.txt) / [llms-ctx.txt](llms-ctx.txt) — LLM-oriented documentation index and single-file context
- [.env.example](.env.example) — every configuration key with defaults
- [examples/HelloBot.php](examples/HelloBot.php) — a complete example plugin

## CLI

```bash
php bin/tgbase list              # all commands
php bin/tgbase make:plugin admin/ban   # scaffold a plugin
php bin/tgbase doctor            # environment diagnostics
php bin/tgbase plugins           # list discovered plugins
php bin/tgbase migrate           # run database migrations
```

## License

MIT
