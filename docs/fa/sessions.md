<div dir="rtl">

# سشن‌ها — SessionManager

وضعیت هر چت که بین پیام‌ها باقی می‌ماند: ویزاردهای فرم چندمرحله‌ای، «برای … عدد 1 را بزنید»، سبد خرید، کلیدهای تغییر وضعیت هر کاربر. سشن‌ها از طریق [CacheManager](caching.md) ذخیره می‌شوند، با شناسهٔ چت کلیدگذاری می‌شوند و TTL دارند تا مکالمات بی‌استفاده به‌طور خودکار منقضی شوند.

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

زیر، کل payload یک ورودی کش است:

| کلید کش | `SESSION_PREFIX` (پیش‌فرض `tgbase:session:`) + شناسهٔ چت |
|---|---|
| TTL | `SESSION_TTL` (پیش‌فرض 3600 ثانیه، کف 60 ثانیه) — **هر `set()` دوباره ذخیره می‌کند و پنجره را صفر می‌کند** |
| Payload | `['id' => sessionId, 'data' => […], 'last_activity' => ts]` |

## یک جریان چندمرحله‌ای

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

هر کاربر یک ماشین وضعیت مستقل دارد، چون سشن با شناسهٔ چت کلیدگذاری می‌شود.

## به کدام درایور کش نیاز دارم؟

- **دیمون polling (فچر `update_direct` / `update_queue`):** یک پروسهٔ بلندعمر PHP — حتی `array` وضعیت را بین پیام‌ها زنده نگه می‌دارد. اما این وضعیت با ری‌استارت می‌میرد.
- **وبهوک (`webhook_*`) و ورکرهای صف:** وضعیت باید از مرز پروسه‌ها عبور کند → `file`، `redis` یا `memcached`.

تأییدشده به‌صورت زنده: سشن‌ای که در پروسهٔ A نوشته شد (`sid d5dfbf11…`، step + cart) در یک پروسهٔ تازهٔ B روی `file`، `redis` و `memcached` خوانده شد — **همان شناسهٔ سشن، payload یکسان**؛ روی `array` پروسهٔ B هیچ چیزی ندید (مستندشده).

## نکات امنیتی

- بعد از هر تغییر سطح دسترسی (ورود) `regenerate()` را صدا بزنید تا از session fixation جلوگیری شود — شناسه یک عدد تصادفی تازهٔ ۳۲-هکسی است.
- سشن‌ها هویت مستقل خودشان را حمل نمی‌کنند: کلید همان شناسهٔ چت است. در چت‌های گروهی همهٔ اعضا سشنِ آن چت را به اشتراک می‌گذارند — برای وضعیت هر کاربر در گروه‌ها، کلیدگذاری را در کلیدهای کش خودتان با `$tg->fromId()` انجام دهید.
- سشن‌های بی‌استفادهٔ طولانی بی‌صدا منقضی می‌شوند (`SESSION_TTL`)؛ جریان‌ها را طوری طراحی کنید که تحمل سشنِ غایب را داشته باشند (`SessionManager::get() === null` → از نو شروع کنید).

## دستورهای تست (integration/TestBot)

`/session` شناسه + کلیدها + داده را dump می‌کند · `/session-set <k> <v>` · `/session-get <k>` · `/session-clear`. زوج set/get در چارچوب تأیید P-CACHE، از طریق پایپ‌لاین واقعی پلاگین در دو پروسهٔ جدا اجرا شد.

</div>
