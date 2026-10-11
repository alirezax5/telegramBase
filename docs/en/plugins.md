# Plugins — writing your bot's logic

A plugin is one PHP class in `Plugin/` that implements `PluginInterface`. There is no registration, no config file, no service provider — drop the file in and the framework discovers it on the next boot.

## Anatomy

```php
<?php
namespace alirezax5\TelegramBase\Plugin;

use alirezax5\TelegramBase\App\Attributes\ChatType;
use alirezax5\TelegramBase\App\Plugin\Contract\PluginInterface;
use telegramBotApiPhp\Telegram;

#[ChatType(['private'])]
class orderStatus implements PluginInterface
{
    // Lower runs first. Default 10. Use 1 for "always handle first".
    public function getPriority(): int
    {
        return 10;
    }

    // Called for every message in an allowed chat type.
    public function onMessage($message, Telegram $Telegram): void
    {
        $chatId = $Telegram->fromId();          // sender's user id
        $text   = trim($message->text ?? '');

        if ($text === '/status') {
            $Telegram->sendMessage($chatId, 'All systems nominal ✅');
        }
    }

    // Called when an inline button attached to one of your messages is pressed.
    public function onCallback_query($callbackQuery, Telegram $Telegram): void
    {
        // always answer, or the sender's client shows a loading spinner:
        $Telegram->answerCallbackQuery($callbackQuery->id);
    }
}
```

Notes:

- `$message` is the **message object** (not the full update) — `$message->text`, `$message->chat->id`, `$message->photo`, etc.
- The full update object (with `update_id`) is available as `$Telegram->getInputData()`.
- A plugin with no `#[ChatType]` attribute runs for all chat types.

## Handler methods

| Method | Triggered when |
|--------|----------------|
| `onMessage` | any message (text, photo, document, ...) |
| `onCallback_query` | an inline keyboard button is pressed |
| `onCommand` | a `/slash_command` message |
| `before` | before every update (any type) |
| `after` | after every update |

Implement only the handlers you need.

## Chat-type filtering

```php
#[ChatType(['private'])]                   // private chats only
#[ChatType(['group', 'supergroup'])]       // groups only
#[ChatType(['channel'])]                   // channel posts
```

The attribute maps to Telegram's chat types, resolved by the SDK (`$Telegram->chatType()`).

## Ordering and short-circuiting

Plugins run in priority order for each handler. Any plugin can stop the chain simply by *not* calling further logic — but the cleaner tool for cross-cutting "reject" rules (bans, rate limits) is **middleware**, which runs before plugin dispatch for every update. See [features.md](features.md#middleware).

## Discovery rules

- Directory: `PLUGINS_DIR` in `.env` (default `/Plugin/`, relative to the project base; absolute paths are supported).
- Files are scanned recursively; the class name must match the file name, PSR-4 style (`Plugin/Admin/Ban.php` → class `Ban` in namespace `…\Plugin\Admin`).
- Plugin metadata (the discovered set) is cached; during development set `PLUGIN_CACHE_ENABLED=false` or use `PLUGINS_RELOAD_INTERVAL` to pick up new files without dumping caches manually.
- Verify what the framework sees: `php bin/tgbase plugins` — shows priority, chat types and registered handlers per plugin.

## A complete example

[examples/HelloBot.php](../../examples/HelloBot.php) demonstrates the common patterns in one file:

- routing `/hello` and `/status` commands
- building an inline keyboard with the fluent `Keyboard` builder
- per-chat state with `SessionManager`
- safe API calls with `tg_retryable()` (throws `RetryableException` on 429/5xx → automatic retry queue)
- answering and acting on callback queries

To try it: copy the file into `Plugin/`, change the namespace to `alirezax5\TelegramBase\Plugin`, restart the bot.

## Scaffolding

```bash
php bin/tgbase make:plugin users/register --type=private
php bin/tgbase make:plugin admin/ban      --type=group
```

Path segments become PSR-4 namespaces (`users/register` → `Plugin\Users\Register`).
