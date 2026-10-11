# Sessions — SessionManager

Per-chat state that survives between messages: multi-step form wizards, "press 1 for…", shopping carts, per-user mode toggles. Sessions are stored through [CacheManager](caching.md), keyed by chat id, with a TTL so idle conversations expire automatically.

```php
use alirezax5\TelegramBase\App\Session\SessionManager;
```

## API

```php
// Start (or resume) a session — creates one when none exists
$session = SessionManager::start($chatId);

$session->set('step', 'waiting_email');        // persists immediately
$session->setMany(['cart' => ['item1'], 'lang' => 'fa']);
$step = $session->get('step');                  // null when unset
$step = $session->get('step', 'default');       // with fallback
$session->has('step');                          // true even when value is null
$session->all();                                // whole payload as array
$session->forget('cart');                       // remove one key
$session->regenerate();                         // new random session id (after login!)
$session->touch();                              // refresh last-activity
$session->destroy();                            // delete entirely

// Read WITHOUT creating (null when absent — use in read-only paths)
$existing = SessionManager::get($chatId);
SessionManager::has($chatId);
SessionManager::flush($chatId);                 // delete
```

Under the hood the whole payload is one cache entry:

| Cache key | `SESSION_PREFIX` (default `tgbase:session:`) + chat id |
|---|---|
| TTL | `SESSION_TTL` (default 3600 s, floor 60 s) — **every `set()` re-persists and resets the window** |
| Payload | `['id' => sessionId, 'data' => […], 'last_activity' => ts]` |

## A multi-step flow

```php
public function onMessage($message, Telegram $tg): void
{
    $text = trim($message->text ?? '');
    $chatId = $tg->chatId();

    $session = SessionManager::start($chatId);

    switch ($session->get('step')) {
        case null:
            if ($text === '/register') {
                $session->set('step', 'ask_email');
                $resp = $tg->sendMessage($chatId, 'ایمیلت را بفرست:');
                tg_retryable($resp, 'sendMessage');
            }
            return;

        case 'ask_email':
            if (!filter_var($text, FILTER_VALIDATE_EMAIL)) {
                $resp = $tg->sendMessage($chatId, 'ایمیل معتبر نیست — دوباره بفرست:');
                tg_retryable($resp, 'sendMessage');
                return;                              // stay on the same step
            }
            $session->setMany(['email' => $text, 'step' => 'done']);
            $resp = $tg->sendMessage($chatId, "ثبت شد: {$text}");
            tg_retryable($resp, 'sendMessage');
            return;
    }
}
```

Each user has an independent state machine because the session is keyed by chat id.

## Which cache driver do I need?

- **Polling daemon (`update_direct` / `update_queue` fetcher):** one long-lived PHP process — even `array` keeps state alive between messages. But the state dies on restart.
- **Webhook (`webhook_*`) and queue workers:** state must cross process boundaries → `file`, `redis` or `memcached`.

Verified live: a session written in process A (`sid d5dfbf11…`, step + cart) was read back in a fresh process B on `file`, `redis` and `memcached` — **same session id, identical payload**; on `array` process B saw nothing (documented).

## Security notes

- `regenerate()` after any privilege change (login) to prevent session fixation — the id is a fresh 32-hex random.
- Sessions carry no identity of their own: the chat id is the key. In group chats every member shares the chat's session — for per-user state in groups, key by `$tg->fromId()` in your own cache keys instead.
- Long-idle sessions expire silently (`SESSION_TTL`); design flows to tolerate a missing session (`SessionManager::get() === null` → start over).

## Test commands (integration/TestBot)

`/session` dumps id + keys + data · `/session-set <k> <v>` · `/session-get <k>` · `/session-clear`. The set/get pair was executed through the real plugin pipeline in two separate processes as part of the P-CACHE verification.
