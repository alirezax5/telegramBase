# Overview — what TelegramBase is

## The one-sentence version

TelegramBase is a PHP framework that turns "talking to the Telegram Bot API" into "writing small plugin classes", while handling everything reliable bots need: update fetching, webhook validation, queueing, retries, and process lifecycle.

## What it does

Every Telegram bot — no matter how simple — needs the same skeleton:

1. **Receive updates.** Either you ask Telegram "anything new?" (long-polling via `getUpdates`), or Telegram calls your HTTPS URL (webhook). TelegramBase supports both, selected by a single `.env` value.
2. **Validate webhook requests.** Anyone can POST fake updates to your URL. TelegramBase checks body size, source IP (official Telegram subnets), an optional secret token, and JSON well-formedness before your code ever runs.
3. **Route updates to your code.** A `Processor` normalizes the raw update object and hands it to every plugin whose filters match (chat type, priority order), optionally through a middleware pipeline.
4. **Survive failure.** API calls can return `429 Too Many Requests`. Handlers can throw. Workers can be killed mid-update. Queue brokers can go down. TelegramBase has a story for each: a retry queue with exponential backoff (honoring Telegram's `retry_after`), bounded queue sizes, broker reconnection with backoff, and documented per-mode delivery guarantees.
5. **Scale when needed.** Start with a single `php bot.php` process. When handling gets heavy, switch to a queue pipeline: a lightweight fetcher pushes updates into Redis/RabbitMQ and separate worker processes consume them.

TelegramBase does all of this and exposes a small, boring, testable API surface.

## What it deliberately doesn't do

- **It is not a bot SDK.** The actual Telegram API calls come from [`alirezax5/telegram-bot-php`](https://github.com/alirezax5/telegram-bot-php) (the `Telegram` object injected into your plugins). TelegramBase is the layer *around* that SDK.
- **It doesn't write your bot logic.** There's no "conversation builder" or admin panel. You write plain PHP classes; the framework schedules and feeds them.
- **It doesn't hide PHP.** No custom DSL, no YAML bot definitions. If you know PHP 8 and the Telegram Bot API, you know how TelegramBase works.

## Design philosophy

- **Config over code.** Operation mode, queue backend, cache driver, language — all switched through `.env`, not by editing classes.
- **Boring components.** Sessions, caching and locks are built on Illuminate (`illuminate/cache`, `illuminate/database`). These are the same components Laravel uses in production; TelegramBase adds no exotic dependencies.
- **Honest about failure.** Every processing path has a documented delivery guarantee (at-least-once or at-most-once) — measured by killing real workers with SIGKILL during integration testing, not guessed. See [reliability.md](reliability.md).
- **Fail loudly, not silently.** A CLI-only mode on a web entry point returns HTTP 500 with an explanation, rather than silently hanging. A full queue rejects and logs new pushes rather than eating your disk.

## The shape of the codebase

```
App/          the framework (one concern per subdirectory)
Plugin/       YOUR plugins (auto-discovered)
Button/       keyboard label definitions
Language/     translation files
Database/     Eloquent models + migrations
bin/          CLI tools (tgbase, scheduler)
docs/         documentation (you are here)
tests/        offline + live test suite
```

Everything in `App/` is served to you through a handful of entry classes: `Core` (run modes), `Processor` (update dispatch), `QueueManager` (queue backends), `SessionManager` (per-chat state), `Keyboard` (fluent keyboards), `LogHandler` (logging). The rest is plumbing those five need.

## Where to go next

- [getting-started.md](getting-started.md) — install and run your first bot
- [architecture.md](architecture.md) — how the pieces fit together
- [bot-modes.md](bot-modes.md) — choosing between the six operation modes
