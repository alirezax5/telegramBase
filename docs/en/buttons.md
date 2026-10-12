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

## Editing a message without losing its inline keyboard

`editMessageText()` **replaces the whole message**, not just the text: called without `reply_markup`, any inline keyboard the message had is gone. When the edited message must keep its buttons, capture the original markup and pass it straight through:

```php
$markup = $msg->reply_markup ?? null;   // from the update's message object

$tg->editMessageText($chatId, $msgId, $newText, $markup !== null
    ? ['reply_markup' => $markup]
    : []);
```

The same rule applies to media edits (`editMessageCaption`, `editMessageMedia`) and to relay/mirror flows that copy one chat's messages into another: keep the copy's markup (e.g. alongside the source/copy message ids in your own table) and hand it back on every edit, or the mirrored message silently loses its buttons after the first edit. (Verified live in a user↔admin support relay, 2026-10-12: without the pass-through, the info keyboard on the group copy vanished on the first text edit.) For media messages that Telegram refuses to text-edit (sticker, video note, voice), `editMessageReplyMarkup($chatId, $msgId, ...)` is the graceful fallback.

## UX convention: every input state shows its exit

Any state that waits for free text — search box, broadcast payload, a value to ban/unban, an answer to forward — must render a Reply Keyboard with **visible exit buttons** (e.g. `↩️ بازگشت` / `↩️ Back`, `❌ لغو` / `❌ Cancel`) and its text handler must recognize those labels before treating the input as data. A text-only cancel command (`/cancel`) is not a substitute: it is undiscoverable. Observed live (2026-10-12): an admin tapped *Block*, got a bare "send the user id" prompt with the old panel keyboard still on screen and no visible way out — and had to escape via the main-menu button. Pair the buttons with a fresh prompt message (so the keyboard swap is visible), and when a flow abandons or reshapes a keyboard, follow the keyboard-cycle rule: send a fresh markup or `remove_keyboard` instead of leaving a stale one (see [conversations.md](conversations.md)).

## Test commands (integration/TestBot)

`/btn` (if present in your plugin set) — the shipped `StartPlugin` shows `btn('start')` in action; the P-CACHE suite verified `btn('start')` resolves (`rows=1`) and its payload persists cross-process under `buttons:all`.


## Request-shaped payloads + Mini App button (2026-10)

```php
Keyboard::remove();                    // ['remove_keyboard' => true] (+selective)
Keyboard::forceReply(false, 'جواب…');  // ['force_reply' => true, 'input_field_placeholder' => …]
Keyboard::reply()->webApp('فروشگاه', 'https://shop.example.com');  // KeyboardButtonWebApp
```

`remove()` is the TM-25 hygiene primitive: attach it (json_encoded) to the
next message whenever a custom keyboard must disappear — abandoned flow,
closed panel. `webApp()` opens a Telegram Mini App (reply keyboards in
private chats, inline keyboards anywhere); the app receives
`WebApp.initData` which must be validated server-side.
