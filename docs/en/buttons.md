# Buttons — Keyboard builder & ButtonManager

Two complementary tools:

- **`Keyboard`** — fluent, code-built reply markup for *dynamic* menus (rows depend on runtime data).
- **`ButtonManager` / `btn()`** — *file-defined* fixed menus in `Button/`, translated labels, cacheable, shareable between plugins.

```php
use alirezax5\TelegramBase\App\Button\Keyboard;
```

## Keyboard builder

### Inline keyboard (callback buttons)

```php
$kb = Keyboard::inline()
    ->button('✅ تایید', 'order_confirm')      // text, callback payload
    ->button('❌ انصراف', 'order_cancel')
    ->row()                                     // next buttons go on a new row
    ->button('راهنما', 'https://example.com', Keyboard::URL)
    ->toArray();

$resp = $tg->sendMessage($chatId, 'سفارش را تایید می‌کنید؟', ['reply_markup' => $kb]);
tg_retryable($resp, 'sendMessage');
```

### Reply keyboard (the user's virtual keyboard)

```php
$kb = Keyboard::reply()
    ->button('🔍 جستجو')
    ->button('💳 خرید')
    ->row()
    ->button('⚙️ تنظیمات')
    ->resize()        // fit text height
    ->oneTime()       // hide after first tap
    // ->selective()  // show only to mentioned users
    ->toArray();
```

Remove a reply keyboard with a raw payload (or a `btn()` definition, see below):

```php
$tg->sendMessage($chatId, 'باشه', ['reply_markup' => ['remove_keyboard' => true]]);
```

### Builder API

| Method | Applies to | Effect |
|---|---|---|
| `inline()` / `reply()` | constructor | callback keyboard / custom keyboard |
| `button($text, $value = '', $type = CALLBACK)` | both | append to current row; `$type` one of `Keyboard::CALLBACK` (`callback_data`), `Keyboard::URL` (`url`), `Keyboard::SWITCH_INLINE`, `Keyboard::SWITCH_INLINE_CURRENT` |
| `row()` | both | start a new row |
| `resize($v)` | reply | `resize_keyboard` |
| `oneTime($v)` | reply | `one_time_keyboard` |
| `selective($v)` | reply | `selective` |
| `inputPlaceholder($text)` | inline | `input_field_placeholder` |
| `toArray()` / `toJson()` | both | final `reply_markup` value (array) / JSON string |

Empty rows are dropped automatically; `toArray()` returns exactly the shape the Bot API expects for `reply_markup`.

### Handling the taps

Inline buttons arrive as updates of type `callback_query` — implement `onCallback_query()` in your plugin and route on `callback_data` (see [plugins.md](plugins.md)); answer them with `$tg->answerCallbackQuery($callbackQueryId)`. Reply-keyboard taps are ordinary text messages.

## ButtonManager — file-defined buttons

Definitions live in `BUTTONS_DIR` (default `/Button`) — the main file `BUTTONS_FILE` (default `btn.php`) plus any other `*.php` file in that directory, which gets a `'<filename>.'` key prefix (same pattern as the language loader).

```php
// Button/btn.php
return [
    "remove" => ['KeyboardRemove' => [], 'remove_keyboard' => true],

    "start" => [
        'keyboard' => [
            [
                ['text' => __('start')],           // translations work inside definitions
                ['text' => __('messages.help')],
            ],
        ],
        'resize_keyboard' => true,
    ],
];
```

```php
use function alirezax5\TelegramBase\App\Button\btn;   // or the global btn() helper

$kb  = btn('start');                       // whole definition → reply_markup
$kb2 = btn('profile', ['ID' => 12]);       // {ID} and {{ID}} placeholders replaced everywhere
$raw = btn('remove');                      // raw Telegram payload (KeyboardRemove)
ButtonManager::has('start');               // exists?
ButtonManager::getAll();                   // every definition
ButtonManager::reload();                   // re-read files, refresh cache
```

Definitions are exactly "whatever the file returns" — most are ready-to-send `reply_markup` arrays, so `$tg->sendMessage($chatId, 'متن', ['reply_markup' => btn('start')])` is the whole story.

## Caching (verified)

Compiled definitions are cached under the key `buttons:all` with `BUTTONS_CACHE_TTL` (default 300 s) so the files are read once per TTL window, not per update. `BUTTONS_CACHE_ENABLED=false` turns the cache off entirely (definitions re-read from files every time) — this switch is wired end-to-end as of P-CACHE-F002 (previously it was read into config but never consulted; the fix was verified live in both states). `ButtonManager::reload()` forces a refresh at runtime.

Cache key / reach follow [caching.md](caching.md): on `array` the cache is per-process; on `file`/`redis`/`memcached` the definitions are shared across workers.

## Test commands (integration/TestBot)

`/btn` (if present in your plugin set) — the shipped `StartPlugin` shows `btn('start')` in action; the P-CACHE suite verified `btn('start')` resolves (`rows=1`) and its payload persists cross-process under `buttons:all`.
