<div dir="rtl">

# پایپ‌لاین صف — وقتی یک پروسه کافی نیست

## اصلاً چرا صف؟

پردازش درون‌پردازشی یک ضعف ساده دارد: پروسه‌ای که آپدیت را *دریافت* می‌کند باید پردازش آن را هم *تمام* کند. اگر یک هندلر ویدیوی ۵۰ مگابایتی را آپلود کند یا منتظر یک API شخص ثالثِ کند بماند، گیرنده مسدود می‌شود (و در حالت وبهوک، تلگرام منتظر 200 خودش می‌ماند). پایپ‌لاین صف کار را تقسیم می‌کند:

```
producer                          broker                    consumers (N processes)
──────                            ──────                    ────────
queue.php / webhook.php  ──push──► Redis / RabbitMQ ──pop──► bot.php workers
(pick up updates fast,            (holds updates while       (do the slow work,
 advance offset / 200)             nobody is around)          ack after processing)
```

مزایا: دریافت سریع (تلگرام هرگز منتظر هندلرهای شما نمی‌ماند)، مقیاس‌پذیری افقی (ورکرهای بیشتر اجرا کنید) و جداسازی خرابی (مُردن یک ورکر روی گیرنده اثری ندارد).

## انتخاب درایور

| درایور | `QUEUE_SAVE_TYPE` | نیازمندی‌ها | رفتار pop | تضمین هنگام کرش ورکر |
|--------|-------------------|-------|---------------|---------------------------|
| JSON | `json` *(پیش‌فرض)* | هیچ | خواندن مخرب فایل | **at-most-once** — اگر وسط پردازش کرش شود کار از دست می‌رود |
| Redis | `redis` | افزونهٔ `redis` + سرور | `LPOP` (مخرب) | **at-most-once** — اگر وسط پردازش کرش شود کار از دست می‌رود |
| Memcached | `memcached` | افزونهٔ `memcached` + سرور | get/delete مخرب | **at-most-once** |
| RabbitMQ | `rabbitmq` | افزونهٔ `amqp` + سرور | `basic_get` + **ack بعد از پردازش** (نسخهٔ v2.4.0) | **at-least-once** — کارِ ackنشده دوباره تحویل داده می‌شود |

وقتی از دست رفتن حتی یک آپدیت قابل‌قبول نیست RabbitMQ را انتخاب کنید؛ وقتی گاه‌به‌گاه از دست رفتن چند آپدیت قابل‌تحمل است (یا وقتی بازسازی آپدیت‌ها ارزان است) برای throughput و سادگی Redis/JSON را انتخاب کنید.

## قرارداد ack در RabbitMQ (نسخهٔ v2.4.0)

از نسخهٔ v2.4.0 درایور RabbitMQ از معناشناسی **ack-late** استفاده می‌کند:

1. `pop()` پیام را می‌گیرد و delivery tag آن را نگه می‌دارد — پیام هنوز *ack نشده* است.
2. هندلر شما اجرا می‌شود.
3. ورکر **بعد از** پردازش `ack()` را صدا می‌زند. فقط در آن لحظه برکر پیام را حذف می‌کند.
4. اگر ورکر در هر نقطهٔ بین 1 و 3 بمیرد، برکر پیام را به ورکر بعدی دوباره تحویل می‌دهد.

در سطح کد، ورکر دقیقاً این کار را می‌کند:

```php
$update = $queue->pop();
try {
    $processor->handle($update);
} finally {
    $queue->ack();   // success → done; handled exception → discarded (no poison loops)
}
```

پیامدهایی که ارزش دانستن دارند:

- **پردازش تکراری ممکن است** (کرش بعد از هندل اما قبل از ack — برکر نمی‌تواند تفاوت را بفهمد). هندلرها را idempotent کنید: قبل از عمل‌کردن `update_id` را ذخیره کنید و اگر قبلاً دیده شده رد شوید. برای جزئیات [reliability.md](reliability.md) را ببینید.
- پیامی که decode نشود بلافاصله ack می‌شود (دور انداخته می‌شود) تا نتواند صف را مسموم کند.
- درایورهای سفارشی صف نیازی به پیاده‌سازی `ack()` ندارند — `QueueManager::ack()` برای درایورهای بدون ack سمت برکر، یک no-op است.

پیکربندی مربوطه زیر بخش `rabbitmq` در `.env` است (host، port، user، password، vhost، نام صف) — به [.env.example](../../.env.example) نگاهی بیندازید.

## اجرای پایپ‌لاین

`update_queue` (تولیدکننده با polling):

```bash
# terminal 1 — fetcher: long-polls Telegram, pushes to the broker
php queue.php

# terminal 2..N — consumers: pop + process + ack
php bot.php
```

`webhook_queue` (تولیدکننده با وبهوک):

```bash
# web server receives Telegram POSTs → validates → enqueues → 200
# (webhook.php handles this automatically when BOT_MODE=webhook_queue)

# terminal 1..N — consumers
php bot.php
```

همهٔ آن‌ها را زیر یک سوپروایزر اجرا کنید. ورکرها به‌طور خودکار با backoff نمایی به برکر دوباره وصل می‌شوند (`BACKOFF_INITIAL=1s` تا `BACKOFF_MAX=60s`)، پس می‌توانید Redis/RabbitMQ را بدون دست زدن به ورکرها ری‌استارت کنید.

## صف‌های محدود

`QUEUE_MAX_SIZE` (پیش‌فرض در [.env.example](../../.env.example)) سقف تعداد آپدیت‌هایی را تعیین می‌کند که می‌توانند در برکر بمانند. وقتی به سقف برسید، pushهای جدید **رد و لاگ می‌شوند** (WARNING) و هرگز بی‌صدا در پشته‌ای نامحدود نمی‌روند. این کار وقتی ورکرها مدتی طولانی از دسترس خارج شده‌اند از دیسک/RAM محافظت می‌کند. مقدار 0 سقف را غیرفعال می‌کند.

## صف تلاش مجدد (جدا از صف اصلی)

صف retry خرابی‌های گذرای هندلرها (HTTP با کد `429`، `5xx`، timeoutها، خطاهای curl) را برای همهٔ حالت‌ها مدیریت می‌کند، نه فقط حالت‌های صف‌دار:

- `Processor::handle()` یک خطای قابل‌retry را می‌گیرد → آپدیت را در `RetryQueue` قرار می‌دهد.
- ورکرهای بلندمدت آیتم‌های سررسیده را در تیک نگهداری خودشان تخلیه می‌کنند (در ورکرهای صف، هر تکرار حلقه).
- backoff با هر تلاش دو برابر می‌شود؛ وقتی تلگرام `retry_after` بدهد، دقیقاً همان مقدار رعایت می‌شود (`RetryableException::retryAfter`).
- بعد از `RETRY_MAX_ATTEMPTS` آیتم حذف و لاگ می‌شود.

هندلرهای شما با هلپر `tg_retryable()` وارد این مسیر می‌شوند:

```php
use function alirezax5\TelegramBase\App\Queue\tg_retryable;

$resp = tg_retryable($tg->sendMessage($chatId, 'Hello!'), 'sendMessage');
// ok=true            → returned unchanged
// 429 / 5xx          → RetryableException thrown → retry queue
// other 4xx          → returned unchanged (permanent errors are not retried)
```

پیکربندی: `RETRY_ENABLED`، `RETRY_QUEUE_DRIVER` (`json`|`redis`)، `RETRY_MAX_ATTEMPTS`، `RETRY_BASE_DELAY`، `RETRY_MAX_SIZE` — مقادیر پیش‌فرض در [.env.example](../../.env.example).

</div>
