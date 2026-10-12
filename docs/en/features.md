# Features — the toolbox

Everything in this document ships with the framework and is configured through `.env` (see [configuration.md](configuration.md)). Deep dives: [caching.md](caching.md) · [sessions.md](sessions.md) · [i18n.md](i18n.md) · [buttons.md](buttons.md) · [m[m[multi-bot.md](multi-bot.md).

## Sessions

Per-chat state that survives between messages, backed by your cache driver — full guide in [sessions.md](sessions.md):

```php
use alirezax5\TelegramBase\App\Session\SessionManager;

$session = SessionManager::start($chatId);

$session->set('step', 'waiting_email');
$step = $session->get('step');            // null when unset
$step = $session->get('step', 'default'); // with fallback

// TTL via SESSION_TTL (default 3600s); state auto-expires after silence.
```

Typical use: multi-step flows (form wizards, "press 1 for..."). The session is keyed by chat id, so each user has an independent state machine.

## Keyboard builder

Fluent construction of inline and reply keyboards — full guide in [buttons.md](buttons.md) (incl. file-defined menus via `ButtonManager`/`btn()`):

```php
use alirezax5\TelegramBase\App\Button\Keyboard;

// Inline keyboard — two rows
$kb = Keyboard::inline()
    ->button('Help', 'btn_help')
    ->button('Settings', 'btn_settings')
    ->row()                       // next buttons go on a new row
    ->button('Close', 'btn_close')
    ->toArray();

$Telegram->sendMessage($chatId, 'Choose:', ['reply_markup' => $kb]);

// Reply keyboard — replaces the user's virtual keyboard
$reply = Keyboard::reply()
    ->button('Option A')
    ->button('Option B')
    ->resize()      // fit text
    ->oneTime()     // hide after first tap
    ->toArray();
```

Button labels can come from translation files via the `Button/` definitions (`BUTTONS_DIR`, `BUTTONS_FILE`).

## Middleware

A pipeline that runs **before plugin dispatch** for every update — polling, webhook and queue alike. Register on the `Core` instance before `run()`:

```php
$core = new Core();
$core->middleware()->add(new RateLimitMiddleware(10, 60)); // 10 msgs / 60 s per chat
$core->middleware()->add(new AdminGuardMiddleware());      // only ADMINS_WHITELIST ids pass
$core->run();
```

Middlewares execute in registration order (onion model). Each may **pass through** (call `$next()`) or **short-circuit** (return without calling `$next()` — the update is rejected). Class-string registration is supported and instantiated lazily.

| Built-in | Purpose |
|----------|---------|
| `RateLimitMiddleware(max, window)` | sliding-window rate limit per chat id |
| `AdminGuardMiddleware` | restrict updates to `ADMINS_WHITELIST` |
| `AuditLogMiddleware` | log every update with latency |

Custom middleware:

```php
class MyMiddleware implements MiddlewareInterface {
    public function handle(object $update, Telegram $tg, callable $next): mixed {
        // pre-process
        $result = $next();
        // post-process
        return $result;
    }
}
```

## Caching

`CacheManager` exposes one API over four drivers (`CACHE_DRIVER`): `array` (per-process), `file`, `redis`, `memcached`. Sessions, plugin discovery metadata, translations and button labels all ride on it — switching the driver changes the persistence story of the whole framework without code changes. Full guide incl. the verified cross-process matrix and per-driver `flush()` reach: [caching.md](caching.md).

## i18n

Translation files live in `Language/` (default `fa`). Use the `__()` helper in plugins — full guide in [i18n.md](i18n.md):

```php
$Telegram->sendMessage($chatId, __('welcome_message'));
```

Language files are PHP arrays (scaffold one with `php bin/tgbase make:language en`). Cached with `LANG_CACHE_TTL`. ⚠️ `LANG_DRIVER` must match your file format (`php` for the shipped files — see [i18n.md](i18n.md)).

## Multi-bot

Run several bots from one process: `BotManager` registry + `BOT_EXTRA_BOTS` env (`name:token` entries) + the library's token stack. Both real-world scenarios (membership check executed by bot #2, delegated send as bot #2) are documented with live evidence in [m[m[multi-bot.md](multi-bot.md).

## Scheduler (cron tasks)

Register tasks in `bin/tasks.php`, run every minute from crontab:

```php
// bin/tasks.php
$scheduler->call('prune_old_sessions', '0 3 * * *');   // daily 3am
$scheduler->call('send_summary',      '*/5 * * * *');  // every 5 min
```

```bash
* * * * * cd /path/to/project && php bin/scheduler.php
```

Tasks are lock-protected (`SCHEDULER_LOCK_*`) so overlapping ticks don't double-run. The same entry drives the `cronjob_*` bot modes: `cronjob_update` does one polling fetch per tick; `cronjob_queue` runs the queue worker for up to `CRON_MAX_TIME` seconds across `CRON_WORKER` file-locked slots.

## Database & migrations

Optional Eloquent integration (`DATABASE_ENABLE=true` + credentials in `Database/Connections.php`):

```bash
php bin/tgbase make:migration create_users_table
# → Database/Migrations/2026_08_05_203306_create_users_table.php

php bin/tgbase migrate            # run pending
php bin/tgbase migrate:status     # what's applied
php bin/tgbase migrate:rollback   # undo last batch
```

```php
use alirezax5\TelegramBase\App\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration {
    public function up(\Illuminate\Database\Schema\Builder $schema): void {
        $schema->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }
    public function down(\Illuminate\Database\Schema\Builder $schema): void {
        $schema->dropIfExists('users');
    }
};
```

Migrate commands never require `BOT_TOKEN` — the validator skips bot-related checks for them (`TGBASE_SKIP_BOT_VALIDATION`).

## CLI commands

```bash
php bin/tgbase list
# Scaffolding
make:plugin <path>          # --type=private|group|both
make:middleware <name>
make:session <name>
make:language <code>
make:button <code>
make:migration <name>
# Database
migrate / migrate:rollback / migrate:status
# Diagnostics
doctor [--telegram]         # local checks; --telegram adds one getMe call
plugins                     # discovered plugins + handlers
retry:drain                 # drain due retry-queue items on demand
```

`doctor` output example:

```
PHP                 OK      8.4.24
Extensions          OK      curl, json, mbstring (optional: pdo_sqlite)
Configuration       OK      .env readable
Storage             OK      AppData/ + subdirs writable
Logs                OK      /path/to/logs
BOT_TOKEN           OK      format valid
Telegram API        SKIP    use --telegram to test getMe
Plugins             OK      2 plugin file(s) discovered

RESULT: OK
```

Exit code `0` = all checks pass, `1` = at least one failure.


## Edited updates (2026-10)

`edited_message` / `edited_channel_post` / `edited_business_message` are
dispatched by PluginHandler to `onEditedMessage($msg, $tg)` (and friends)
with the same priority and `#[ChatType]` rules as normal hooks — the typed
accessors (`$tg->editedMessage()`, `chatType()`, `chatId()`, …) work on
edits. One deployment precondition: the webhook's `allowed_updates` must
include `edited_message` (the default of `tgbase webhook:set`).

## Media helper (2026-10)

`App\Shared\Media::download($tg, $fileId, ?dir, ?name)` saves incoming
files under `storage/downloads`; `Media::send($tg, $type, $chatId, $src,
$opts)` sends by `file_id` or local path (auto-CURLFile). See
[media.md](media.md).

## Mini App initData (2026-10)

Server-side validation of the signed identity string Mini Apps receive
(`Telegram.WebApp.initData`) — the only trustworthy proof of which Telegram
user is calling your Mini App backend. `App\MiniApp\InitData::validate($raw)`
implements the official HMAC-SHA256 scheme with constant-time comparison,
checks `auth_date` freshness (`max_age`, default 86400 s) and auto-detects
`BOT_TOKEN` from the environment (pass the token explicitly for multi-bot).
Helpers: `valid()`, `user()`, `build()` (dev-only signed fixture generator).
The same validator ships standalone in the library as
`telegramBotApiPhp\WebApp\InitData`. Full guide: [mini-apps.md](mini-apps.md);
runnable endpoint: `examples/MiniAppEndpoint.php`.
