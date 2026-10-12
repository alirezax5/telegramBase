<div dir="rtl">

# تست — مجموعهٔ آفلاین، تست‌های زنده و CI

## مجموعهٔ تست آفلاین

فریمورک با یک اجراکنندهٔ تست بدون وابستگی همراه است (نیازی به PHPUnit نیست):

```bash
composer install        # vendor/ must exist (the suite boots the framework)
php tests/run.php
```

ساختار:

| دایرکتوری | چه چیزی آنجاست |
|-----------|------------------|
| `tests/Unit/` | تست‌های واحدِ خالص — هندلر env، پیکربندی، درایورهای صف، ذخیرهٔ آفست، اعتبارسنجی dispatcher وبهوک، سیم‌کشی میدلور، چرخهٔ retry و ... (بدون شبکه) |
| `tests/Integration/` | تست‌های چند-کامپوننتی (هنوز آفلاین) |
| `tests/Live/` | **تست‌های واقعی Telegram API — فقط با انتخاب صریح** (پایین را ببینید) |
| `tests/smoke.php` | تست دودِ مستقلِ bootstrap (توکن بد، توکن خالی، حالت بد، بوت معتبر) |
| `tests/benchmark.php` | میکرو-بنچمارک برای مسیرهای داغ |

فیلتر بر اساس نام:

```bash
php tests/run.php --filter=Keyboard
php tests/run.php --filter=Queue
```

## رانش شناخته‌شدهٔ مجموعه — حل‌شده

تا نسخهٔ v2.4.0 اجرای آفلاین گزارش **196 pass / 18 fail / 12 skip** می‌داد. آن 18 شکست (`SessionTest` ×10، `BackwardCompatTest` ×4، `MiddlewareWiringTest` ×4) از قبل وجود داشتند و با برگرداندن تک‌تک رفع‌ها و اجرای دوباره، ثابت شد که ربطی به رفع‌های فریمورک ندارند.

علت ریشه‌ای در بازبینی پیش از انتشار پیدا شد (P-REL-B05): `tests/run.php` پیش‌فرض‌های env را می‌کارد و `Bootstrap::boot()` را صدا می‌زند، اما `EnvironmentBootstrap` از متد `load()` در Dotenv استفاده می‌کرد که **وقتی مخزن `.env` نداشته باشد استثنا پرتاب می‌کند** (یک clone تازه فقط `.env.example` دارد). بوت نیمه‌کاره می‌ماند، `Config` مقداردهی‌نشده می‌ماند و هر تستِ وابسته به پیکربندی شکست می‌خورد. با سوئیچ به `safeLoad()` مجموعه در یک clone تازه و بدون هیچ راه‌اندازی سبز اجرا می‌شود:

```
Pass: 224  Fail: 0  Skip: 12
```

## تست‌های زندهٔ تلگرام

تست‌های زنده به Bot API واقعی می‌زنند و مگر اینکه صریحاً فعال شوند skip می‌شوند:

```bash
RUN_TELEGRAM_LIVE_TESTS=1 \
TELEGRAM_BOT_TOKEN="<your-token>" \
TELEGRAM_TEST_CHAT_ID="<your-chat-id>" \
php tests/run.php
```

قواعد اخلاقی (که خود تست‌ها اعمالشان می‌کنند):

- هر پیامی که می‌فرستند برچسب `[telegramBase automated test]` دارد
- هرجا ممکن باشد پیام‌های خودشان را بعد از تأیید حذف می‌کنند — و هرگز پیام‌هایی را که خودشان نساخته‌اند حذف نمی‌کنند
- مسیرهای منفی هم شامل می‌شوند: چت نامعتبر، توکن نامعتبر، شبیه‌سازی 429
- توکن **فقط** از متغیرهای محیطی خوانده می‌شود

## CI (GitHub Actions)

`.github/workflows/tests.yml` روی هر push/PR و در بازهٔ PHP **8.1 تا 8.4** اجرا می‌شود: اعتبارسنجی composer، `composer audit` و مجموعهٔ آفلاین.

`.github/workflows/live-tests.yml` یک workflow **دستی** (`workflow_dispatch`) برای تست‌های زنده است؛ توکن را از secret مخزن یعنی `TGBASE_LIVE_BOT_TOKEN` و یک ورودی `chat_id` می‌خواند — هیچ secretی در فایل workflow نیست و CI پیش‌فرض هرگز به secretها دست نمی‌زند.

## تست دود (smoke)

```bash
php tests/smoke.php
```

شش حالت: توکن بد / توکن خالی / تلاش برای path traversal در لاگ / مسیر مطلق لاگ / حالت بد / بوت معتبر. بعد از هر دیپلوی به‌عنوان یک بررسی سلامت ۵ ثانیه‌ای از آن استفاده کنید.

## مجموعه چه چیزهایی را پوشش می‌دهد (و چرا برای اعتماد مهم است)

ادعاهای قابلیت اطمینان در [reliability.md](reliability.md) توسط تست‌ها به‌علاوهٔ سناریوهای یکپارچگیِ crash-testشده پشتیبانی می‌شوند (SIGKILL در هر مرحلهٔ پایپ‌لاین، بررسی واقعی تحویل دوباره در برکر، تست‌های گذار وبهوک با تأیید از طریق `getWebhookInfo`). مرتبط‌ترین مجموعه‌ها برای ممیزی آن ادعاها:

```bash
php tests/run.php --filter=Queue      # driver behaviour (json/redis/rabbitmq contracts)
php tests/run.php --filter=Offset     # offset storage atomicity
php tests/run.php --filter=Retry      # retry queue lifecycle + retry_after
php tests/run.php --filter=Webhook    # the 5 webhook validation layers
php tests/run.php --filter=Security   # env/log hardening rules
```

بستر یکپارچگی که در crash-testing استفاده می‌شد (یک ربات واقعی + اسکریپت‌های سناریو) در `integration/TestBot/` قرار دارد — برای اینکه چه می‌کند و چطور آن را با دیپلوی خودتان وفق دهید، README آن را ببینید.

</div>


## پخش مجدد آپدیت‌ها — `tgbase simulate` (۲۰۲۶-۱۰)

```bash
php bin/tgbase simulate tests/Fixtures/updates/start.json --dry   # فقط پارس
php bin/tgbase simulate tests/Fixtures/updates/                   # پخش همه
php bin/tgbase simulate my_update.json                            # یک فیکسچر
```

`--dry` ساختار JSON و هوک پلاگینِ مقابل را گزارش می‌کند — بدون تماس با API.
بدون آن، آپدیت از پایپ‌لاین واقعی عبور می‌کند (پلاگین‌ها واقعاً اجرا
می‌شوند؛ روی ربات تست). فیکسچرهای نمونه در `tests/Fixtures/updates/`
(start، edited_message، callback_query) و `examples/fixtures/` هستند.
