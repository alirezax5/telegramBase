<div dir="rtl">

# معماری — چطور اجزا کنار هم قرار می‌گیرند

## ترتیب بوت

هر نقطهٔ ورود (CLI یا وب) یک شیء می‌سازد: `Core`.

```php
$core = new Core(CoreMode::FULL);   // FULL = boot plugins too
$core->run();
```

سازنده زنجیرهٔ بوت را به این ترتیب اجرا می‌کند:

| گام | کلاس | چه اتفاقی می‌افتد |
|------|-------|--------------|
| 1 | `Bootstrap::boot()` | فایل `.env` را بارگذاری می‌کند (از طریق `EnvHandler`)، محیط را اعتبارسنجی می‌کند (`EnvironmentValidator`)، مسیرها را آماده می‌کند (`Paths`، `AppData/`)، کش را متصل می‌کند |
| 2 | `BotManager::getInstance()->get()` | نمونهٔ SDK `Telegram` را از `BOT_TOKEN` می‌سازد (سینگلتون — همهٔ اجزا یک نمونهٔ مشترک دارند) |
| 3 | `PluginsBootstrap::boot()` *(فقط FULL)* | دایرکتوری `PLUGINS_DIR` را اسکن می‌کند، کلاس‌های پلاگین را نمونه‌سازی می‌کند، بر اساس اولویت مرتب می‌کند و رجیستری `PluginHandler` را می‌سازد |

`CoreMode::UPDATES_ONLY` (که `queue.php` از آن استفاده می‌کند) گام 3 را رد می‌کند — fetcher فقط آپدیت‌ها را در صف قرار می‌دهد و خودش هرگز پلاگین‌ها را توزیع نمی‌کند.

## جریان آپدیت

```
                 ┌──────────────────────────────────────────────┐
                 │                  Core::run()                 │
                 └──────────────────────────────────────────────┘
                                    │
              route by BOT_MODE + SAPI (CLI vs HTTP) — see bot-modes.md
                                    │
        ┌───────────────┬───────────┴──────────┬─────────────────┐
        ▼               ▼                      ▼                 ▼
   PollingLoop    WebhookDispatcher        QueueWorker       CronManager
   (getUpdates    (validate + dispatch     (pop from queue   (cron slots →
    in a loop)     one webhook payload)     + process)        poll/worker)
        │               │                      │
        └───────────────┴──────────┬───────────┘
                                   ▼
                              Processor::handle($update)
                                   │
                     normalize to object (shape-preserving —
                     list arrays like photo sizes stay arrays)
                                   │
                                   ▼
                       MiddlewarePipeline (optional)
                                   │
                                   ▼
                     PluginHandler::runAll($update, $telegram)
```

نکات کلیدی:

- **یک `Processor` برای هر مرحلهٔ پایپ‌لاین.** مسیرهای polling، وبهوک و صف هر کدام یکی می‌سازند؛ همهٔ آن‌ها همان پایپ‌لاین میدلوری را attach می‌کنند که روی `Core` ثبت کرده‌اید.
- **نرمال‌سازی حفظ‌کنندهٔ ساختار (shape-preserving).** آپدیت‌هایی که به‌صورت آرایه می‌رسند (از درایورهای صف) به‌صورت بازگشتی به شیء تبدیل می‌شوند — آرایه‌های *لیستی* (سایزهای photo، entityها، سطرهای کیبورد inline) آرایه می‌مانند و مپ‌های انجمنی به شیء تبدیل می‌شوند. این موضوع مهم است چون تلگرام فیلدهایی مثل `photo` را به‌صورت آرایه مدل می‌کند و پلاگین‌ها روی آن‌ها `foreach` می‌زنند.
- **خرابی‌های داخل `handle()`** گرفته می‌شوند، همراه با کانتکست آپدیت لاگ می‌شوند و — وقتی خطا گذرا به نظر برسد (`429`، `5xx`، timeout، خطای curl) — با backoff نمایی در `RetryQueue` قرار می‌گیرند.

## نقشهٔ اجزای `App/`

| دایرکتوری | حوزهٔ مسئولیت | کلاس‌های کلیدی |
|-----------|---------|-------------|
| `Attributes/` | اتریبیوت‌های PHP 8 | `ChatType` (فیلتر پلاگین‌ها بر اساس private/group/channel) |
| `Bootstrap/` | زنجیرهٔ بوت | `Bootstrap`، `PluginsBootstrap`، `QueueBootstrap` |
| `Bot/` | نمونهٔ SDK | `BotManager` (سینگلتون)، `BotConfig` |
| `Button/` | کیبوردها | `Keyboard` (سازندهٔ fluent)، `ButtonManager` (برچسب‌های i18n) |
| `Cache/` | کش | `CacheManager` — درایورهای array / file / redis / memcached / database |
| `Cli/` | ابزارهای CLI | `Console`، `Maker` (اسکفولدینگ)، `Migrate`، `Doctor`، `PluginsList`، `RetryDrain` |
| `Config/` | پیکربندی تایپ‌دار | `Config` (رجیستری)، `AppConfig`، `SessionConfig` و ... |
| `Connection/` | برکرها | `ConnectionManager` (Redis / Memcached / RabbitMQ، سینگلتون با بررسی سلامت اتصال) |
| `Core.php` | نقطهٔ ورود | مسیریابی حالت، ثبت میدلور، `run()`، `runFetchQueueUpdate()` |
| `Cron/` | حالت‌های کرون | `CronManager`، `CronWorker`، `WorkerLocker` (اسلات‌های قفل فایلی) |
| `Database/` | ماندگاری داده | `DatabaseManager` (Eloquent)، `MigrationRunner` |
| `Enum/` | Enumها | `CoreMode` (FULL / UPDATES_ONLY) |
| `Environment/` | متغیرهای محیطی | `EnvHandler` (گترهای تایپ‌دار)، `EnvironmentValidator` (اعتبارسنجی هنگام بوت) |
| `Language/` | i18n | لودر `Language` + هلپر `__()` |
| `Logger/` | لاگ‌گیری | `LogHandler` (پوشش Monolog)، `LoggerConfig` (مقاوم در برابر path traversal) |
| `Middleware/` | پایپ‌لاین | `MiddlewareInterface`، `MiddlewarePipeline`، میدلورهای داخلی: `RateLimitMiddleware`، `AdminGuardMiddleware`، `AuditLogMiddleware` |
| `Plugin/` | توزیع | `PluginHandler` (کشف + اجرا)، `PluginInterface` (پلاگین‌های شما آن را پیاده‌سازی می‌کنند) |
| `Queue/` | صف‌ها | `QueueManager` (فاسادِ درایورها)، درایورها در `Drivers/` (json/redis/rabbitmq/memcached)، `RetryQueue`، `RetryableException`، `QueueWorker` |
| `Scheduler/` | تسک‌ها | `Scheduler` (عبارات cron)، `TaskLock` |
| `Session/` | وضعیت | `SessionManager::start($chatId)` → `Session` (پشتیبانی‌شده با کش، TTL) |
| `Shared/` | حافظهٔ موقت هر آپدیت | `SharedManagement` (بین تکرارها پاک می‌شود) |
| `Storage/` | فایل‌های وضعیت | `OffsetStorage` (آفست getUpdates، نوشتن با `LOCK_EX`) |
| `Update/` | جریان آپدیت | `Fetcher`، `PollingLoop`، `Processor`، `WebhookDispatcher`، `WebhookResponse` |

## سه شکل پروسهٔ بلندمدت

1. **ورکر polling** (`bot.php`، حالت `update_direct`) — حلقهٔ `getUpdates` → `Processor::handle()` → جلو بردن آفست. برای محدود ماندن حافظه، هر `POLLING_RESTART_INTERVAL` بسته خودش را ری‌استارت می‌کند؛ آن را زیر نظر یک supervisor اجرا کنید.
2. **ورکر وبهوک** (`webhook.php`، FPM/Apache) — برای هر درخواست stateless است؛ تلگرام فقط به یک status code به‌موقع نیاز دارد. کارهای سنگین باید به صف سپرده شوند (حالت `webhook_queue`).
3. **ورکرهای صف** (تولیدکنندهٔ `queue.php` + مصرف‌کنندهٔ `bot.php`، حالت‌های `*_queue`) — fetcher قرار می‌دهد، ورکرها برمی‌دارند. هر تعداد پروسهٔ مصرف‌کننده؛ برکر آپدیت‌ها را بین آن‌ها پخش می‌کند.

## وضعیت‌ها کجا زندگی می‌کنند

| وضعیت | مکان | نکات |
|-------|----------|-------|
| آفست getUpdates | `AppData/lastupdate.txt` | `OffsetStorage`، نوشتن اتمیک؛ فقط حالت‌های polling به آن دست می‌زنند |
| بارهای صف | برکر (یا `AppData/queue/` برای درایور json) | |
| آیتم‌های retry | `AppData/retry/` یا Redis (بسته به `RETRY_QUEUE_DRIVER`) | |
| سشن‌ها | درایور کش (بسته به `CACHE_DRIVER`) | TTL از طریق `SESSION_TTL` |
| لاگ‌ها | `logs/` (بسته به `LOG_DIR`) | path traversal توسط اعتبارسنجی پیکربندی رد می‌شود |

`AppData/` فقط مخصوص زمان اجراست و gitignore شده — برای ساخته‌شدن دوباره‌اش کافی است ربات را اجرا کنید.

</div>
