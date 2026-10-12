# TelegramBase Integration Test Bot

A real Telegram bot built on top of TelegramBase, used to validate the framework under realistic conditions — it exercises plugins, sessions, cache, middleware and the Telegram API end-to-end against a live bot token.

## Structure

```
integration/TestBot/
├── bot.php              # Entry point (polling mode, FULL core)
├── run.php              # Scenario runner
├── .env.example         # Config template (BOT_TOKEN goes here)
├── Plugin/              # Test plugins (auto-discovered by this bot only)
│   ├── StartPlugin.php
│   ├── SessionTestPlugin.php
│   ├── CacheTestPlugin.php
│   ├── FailureTestPlugin.php
│   └── TelegramApiTestPlugin.php
├── Middleware/          # Test middleware
│   ├── LoggingMiddleware.php
│   └── ShortCircuitMiddleware.php
└── runtime/             # Runtime data (AppData, logs) — gitignored, created on demand
```

## Running

```bash
cd integration/TestBot
cp .env.example .env      # set BOT_TOKEN (never commit the real one)

php bot.php
```

Then send commands to the bot from Telegram:

- `/start` — welcome + keyboard
- `/session` — session info
- `/cache <key> <value>` — set cache
- `/error` — trigger a plugin exception (retry-queue path)
- API test commands via `TelegramApiTestPlugin`

## Isolation

This Test Bot uses its own `Plugin/` directory (set via `PLUGINS_DIR` in `bot.php`), so test plugins never interfere with the project's `Plugin/` directory. Runtime data goes to `runtime/` (gitignored, created on demand).

## Why it ships with the framework

It doubles as a working reference deployment: the same wiring (boot, middleware registration, plugin discovery from a custom directory) is what a production bot looks like. The framework's own `webhook.php` entry point was kept dependency-free on purpose — this harness is opt-in.
