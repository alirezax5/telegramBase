<div dir="rtl">

# TelegramBase — مستندات فارسی

[English](README.md) · **فارسی** · [中文](README.zh.md) · [Русский](README.ru.md) · [Français](README.fr.md) · [Português](README.pt.md) · [العربية](README.ar.md)

مینی‌فریمورک ماژولار PHP 8.1+ برای ساخت ربات‌های تلگرام — با سیستم پلاگین، بک‌اندهای صف، اعتبارسنجی webhook، سشن، میدلور، زمان‌بند، مایگریشن و ابزار CLI. روی اجزای Illuminate (لاراول) ساخته شده تا چرخ دوباره اختراع نشود.

## این پروژه در یک پاراگراف چیست؟

TelegramBase کارهای «لوله‌کشی»ای را که هر ربات تلگرام لازم دارد انجام می‌دهد — دریافت آپدیت‌ها (polling یا webhook)، اعتبارسنجی درخواست‌های webhook، توزیع آپدیت‌ها بین کدهای شما، تلاش مجدد فراخوانی‌های ناموفق API و گفتگو با بک‌اندهای صف مانند Redis و RabbitMQ — تا شما فقط بخش جالب را بنویسید: کلاس‌های کوچک PHP به نام **پلاگین** که به پیام‌ها واکنش نشان می‌دهند. یک کلاس در `Plugin/` بگذارید و فریمورک خودش آن را کشف و اجرا می‌کند. تمام ایده همین است.

## چرا وجود دارد؟

نوشتن ربات «مستقیم روی API» تا وقتی ترافیک واقعی نرسیده ساده است. بعد باید به سؤال‌هایی جواب بدهید مثل: *اگر ورکر وسط پردازش یک آپدیت کرش کند چه می‌شود؟ درخواست‌های webhook کجا اعتبارسنجی می‌شوند؟ خطای `429 Too Many Requests` را چطور بدون گم‌شدن آپدیت دوباره امتحان کنم؟ می‌توانم کار سنگین را از درخواست HTTP جدا کنم؟* TelegramBase جواب همه این‌ها را از پیش دارد:

- **شش حالت عملیاتی** (`update_direct`، `update_queue`، `webhook_direct`، `webhook_queue`، `cronjob_update`، `cronjob_queue`) — با تغییر یک مقدار در `.env` بین polling و webhook، پردازش مستقیم و صف‌شده جابه‌جا شوید.
- **معناشناسی صادقانه تحویل (delivery)** — فریمورک به‌صورت سرتاسری crash-test شده (SIGKILL داخل هر مرحله پایپ‌لاین). تضمین هر حالت مستند است: مسیرهای webhook/مستقیم *at-least-once* هستند، پایپ‌لاین RabbitMQ *at-least-once* (ack بعد از پردازش)، درایورهای مخرب (Redis/JSON/Memcached) *at-most-once*. ببینید [docs/reliability.md](docs/fa/reliability.md).
- **محافظت در برابر پیکربندی‌های اشتباه واقعی** — اجرای حالتِ مخصوص CLI پشت نقطه ورود webhook با HTTP 500 رد می‌شود تا وب‌ورکر شما در حلقه بی‌نهایت polling گیر نکند (باگی که واقعاً در تست عملی دیدیم).
- **همه‌چیز همراه فریمورک** — سشن، سازنده کیبورد، i18n، میدلور (rate-limit / admin-guard / audit-log)، زمان‌بند، مایگریشن‌های Eloquent، صف‌های محدود و retry خودکار با `retry_after` تلگرام.

## نیازمندی‌ها

- PHP 8.1+
- Composer
- افزونه‌ها: `json`، `mbstring`، `curl`
- اختیاری: `redis`، `memcached`، `amqp` (بک‌اندهای صف/کش)
- اختیاری: MySQL/MariaDB (دیتابیس، مایگریشن‌های Eloquent)

## شروع سریع

```bash
git clone https://github.com/alirezax5/telegramBase.git
cd telegramBase
composer install
cp .env.example .env
# Edit .env — set BOT_TOKEN (get one from @BotFather)
php bot.php
```

به ربات خود `/start` بفرستید — پلاگین شروع در `Plugin/start.php` پاسخ می‌دهد. سپس پلاگین خودتان را بنویسید (ببینید [docs/plugins.md](docs/fa/plugins.md)).

## مسیر یک آپدیت

```
Telegram
   │
   ├─ getUpdates long-poll ──► bot.php ──┐
   │                                     │
   └─ webhook POST ──► webhook.php ──────┤
                                         ▼
                                    Core::run()
                                         │
                       validate (webhook)│mode routing
                                         ▼
                                    Processor
                                         │
                             middleware pipeline
                                         ▼
                              your plugins (Plugin/)
```

## مستندات

همه‌چیز در `docs/` قرار دارد — با نمای کلی شروع کنید:

| سند | چه چیزی داخلش است |
|----------|---------------|
| [docs/overview.md](docs/fa/overview.md) | فریمورک چیست، چه کاری می‌کند و چه کاری را عمداً نمی‌کند |
| [docs/architecture.md](docs/fa/architecture.md) | ترتیب بوت، جریان آپدیت، نقشه اجزای `App/` |
| [docs/getting-started.md](docs/fa/getting-started.md) | نصب → اولین پلاگین → استقرار، قدم به قدم |
| [docs/bot-modes.md](docs/fa/bot-modes.md) | هر شش `BOT_MODE`، نقاط ورود، اینکه کدام پروسه‌ها را اجرا کنید |
| [docs/plugins.md](docs/fa/plugins.md) | نوشتن پلاگین: هندلرها، اولویت، `#[ChatType]`، کشف |
| [docs/queue-pipeline.md](docs/fa/queue-pipeline.md) | بک‌اندهای صف، ورکرها، صف retry، معناشناسی ack |
| [docs/webhook.md](docs/fa/webhook.md) | راه‌اندازی وبهوک، ۵ لایه اعتبارسنجی، نکات پروکسی/CDN |
| [docs/reliability.md](docs/fa/reliability.md) | نتایج crash-test: تضمین‌های تحویل هر حالت، راهنمای idempotency |
| [docs/configuration.md](docs/fa/configuration.md) | مرجع کامل `.env` |
| [docs/features.md](docs/fa/features.md) | سشن‌ها، کیبوردها، میدلور، کش، i18n، زمان‌بند، دیتابیس |
| [docs/caching.md](docs/fa/caching.md) | کاووش عمیق CacheManager: درایورها، TTLها، دامنهٔ flush، ماتریس بین‌پروسه‌ای (تأییدشده به‌صورت زنده) |
| [docs/sessions.md](docs/fa/sessions.md) | وضعیت سشن هر چت: API، جریان‌های چندمرحله‌ای، نکات امنیتی |
| [docs/conversations.md](docs/fa/conversations.md) | فلوهای گفتگوی چندمرحله‌ای: مرحله‌های اعلانی، اعتبارسنجی/retry، شاخه‌بندی، ناوبری بازگشت/منو، اینلاین + Reply Keyboard، مهلت زمانی |
| [docs/i18n.md](docs/fa/i18n.md) | ترجمه‌ها: چیدمان فایل، هلپر `__()`، زبان هر کاربر، کش |
| [docs/buttons.md](docs/fa/buttons.md) | سازنده کیبورد + منوهای تعریف‌شده در فایل (`btn()`)، هندل‌کردن callback |
| [docs/multi-bot.md](docs/fa/multi-bot.md) | چند ربات در یک پروسه: `BotManager`، `BOT_EXTRA_BOTS`، توکن‌استک (تأییدشده به‌صورت زنده) |
| [docs/testing.md](docs/fa/testing.md) | مجموعه آفلاین، تست‌های زنده تلگرام، تست دود، CI |
| [docs/troubleshooting.md](docs/fa/troubleshooting.md) | نشانه‌ها و رفع‌های رایج، تله‌های دیپلوی |

فایل‌های مفید دیگر:

- [CHANGELOG.md](CHANGELOG.md) — چه چیزهایی در هر نسخه تغییر کرده است
- [llms.txt](llms.txt) / [llms-ctx.txt](llms-ctx.txt) — فهرست مستندات و کانتکست تک‌فایلیِ مناسب LLM
- [.env.example](.env.example) — همه کلیدهای پیکربندی همراه با مقادیر پیش‌فرض
- [examples/HelloBot.php](examples/HelloBot.php) — یک پلاگین نمونهٔ کامل

## CLI

```bash
php bin/tgbase list              # all commands
php bin/tgbase make:plugin admin/ban   # scaffold a plugin
php bin/tgbase doctor            # environment diagnostics
php bin/tgbase plugins           # list discovered plugins
php bin/tgbase migrate           # run database migrations
```

## مجوز

MIT

</div>
