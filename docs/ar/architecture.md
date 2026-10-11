<div dir="rtl">

# البنية المعمارية — كيف تتكامل الأجزاء مع بعضها

## تسلسل الإقلاع

كل نقطة دخول (سطر أوامر أو ويب) تبني كائنًا واحدًا: `Core`.

```php
$core = new Core(CoreMode::FULL);   // FULL = boot plugins too
$core->run();
```

يُنفِّذ المُنشئ سلسلة الإقلاع بهذا الترتيب:

| الخطوة | الصنف | ما الذي يحدث |
|------|-------|--------------|
| 1 | `Bootstrap::boot()` | يحمّل `.env` (عبر `EnvHandler`)، ويتحقق من صحة البيئة (`EnvironmentValidator`)، ويجهّز المسارات (`Paths` و`AppData/`)، ويصل بذاكرة التخزين المؤقت |
| 2 | `BotManager::getInstance()->get()` | ينشئ نسخة الـ SDK من الصنف `Telegram` انطلاقًا من `BOT_TOKEN` (نمط singleton — كل المكوّنات تتشارك نسخة واحدة) |
| 3 | `PluginsBootstrap::boot()` *(FULL فقط)* | يمسح `PLUGINS_DIR`، وينشئ نسخًا من أصناف الإضافات، ويرتّبها حسب الأولوية، ويبني سجل `PluginHandler` |

يتخطى `CoreMode::UPDATES_ONLY` (الذي يستخدمه `queue.php`) الخطوة 3 — الجالب يدفع التحديثات فقط إلى قائمة الانتظار ولا يوزّع الإضافات بنفسه أبدًا.

## مسار سير التحديث

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

النقاط الرئيسية:

- **نسخة `Processor` واحدة لكل مرحلة من خط المعالجة.** مسارات الاستطلاع والويب هوك وقائمة الانتظار يبني كل منها نسخة واحدة؛ وكلها تُرفق خط أنابيب الوسطاء البرمجيين نفسه الذي سجّلته على `Core`.
- **توحيد شكل يحافظ على البنية.** التحديثات الواصلة كمصفوفات (من مُشغِّلات قوائم الانتظار) تُحوَّل إلى كائنات بشكل تكراري — المصفوفات من نوع *قائمة* (أحجام الصور، والكيانات، وصفوف لوحات المفاتيح المضمنة) تبقى مصفوفات، بينما تصبح الخرائط الترابطية كائنات. هذا مهم لأن Telegram يمثّل حقولًا مثل `photo` كمصفوفات والإضافات تطبّق عليها `foreach`.
- **الإخفاقات داخل `handle()`** تُلتقط، وتُسجَّل مع سياق التحديث، وعندما يبدو الخطأ عابرًا (`429` أو `5xx` أو انتهاء مهلة أو خطأ curl) تُدفع إلى `RetryQueue` بتراجع أُسّي.

## خريطة مكوّنات `App/`

| المجلد | المجال | الأصناف الرئيسية |
|-----------|---------|-------------|
| `Attributes/` | سمات PHP 8 | `ChatType` (تصفية الإضافات حسب private/group/channel) |
| `Bootstrap/` | سلسلة الإقلاع | `Bootstrap`، `PluginsBootstrap`، `QueueBootstrap` |
| `Bot/` | نسخة الـ SDK | `BotManager` (singleton)، `BotConfig` |
| `Button/` | لوحات المفاتيح | `Keyboard` (باني سلس)، `ButtonManager` (تسميات متعددة اللغات) |
| `Cache/` | التخزين المؤقت (كاش) | `CacheManager` — مُشغِّلات array / file / redis / memcached / database |
| `Cli/` | أدوات سطر الأوامر | `Console`، `Maker` (توليد هياكل)، `Migrate`، `Doctor`، `PluginsList`، `RetryDrain` |
| `Config/` | إعدادات بأنواع محددة | `Config` (سجل)، `AppConfig`، `SessionConfig`، ... |
| `Connection/` | الوسطاء | `ConnectionManager` (Redis / Memcached / RabbitMQ، بنمط singleton مع فحوصات حيوية) |
| `Core.php` | نقطة الدخول | توجيه الأوضاع، تسجيل الوسطاء البرمجيين، `run()`، `runFetchQueueUpdate()` |
| `Cron/` | أوضاع cron | `CronManager`، `CronWorker`، `WorkerLocker` (فتحات بأقفال ملفات) |
| `Database/` | التخزين الدائم | `DatabaseManager` (Eloquent)، `MigrationRunner` |
| `Enum/` | التعدادات | `CoreMode` (FULL / UPDATES_ONLY) |
| `Environment/` | متغيرات البيئة | `EnvHandler` (دوال جلب بأنواع محددة)، `EnvironmentValidator` (تحقق وقت الإقلاع) |
| `Language/` | تعدد اللغات | محمّل `Language` + الدالة المساعدة `__()` |
| `Logger/` | التسجيل | `LogHandler` (غلاف حول Monolog)، `LoggerConfig` (آمن ضد تجاوز المسار) |
| `Middleware/` | خط الأنابيب | `MiddlewareInterface`، `MiddlewarePipeline`، وأدوات مدمجة: `RateLimitMiddleware`، `AdminGuardMiddleware`، `AuditLogMiddleware` |
| `Plugin/` | التوزيع | `PluginHandler` (الاكتشاف + التشغيل)، `PluginInterface` (إضافاتك تطبّقه) |
| `Queue/` | قوائم الانتظار | `QueueManager` (واجهة المُشغِّلات)، المُشغِّلات في `Drivers/` (json/redis/rabbitmq/memcached)، `RetryQueue`، `RetryableException`، `QueueWorker` |
| `Scheduler/` | المهام | `Scheduler` (تعبيرات cron)، `TaskLock` |
| `Session/` | الحالة | `SessionManager::start($chatId)` ← `Session` (مدعومة بالكاش، مع TTL) |
| `Shared/` | مساحة عمل مؤقتة لكل تحديث | `SharedManagement` (تُفرَّغ بين التكرارات) |
| `Storage/` | ملفات الحالة | `OffsetStorage` (إزاحة getUpdates، كتابات بـ `LOCK_EX`) |
| `Update/` | مسار التحديث | `Fetcher`، `PollingLoop`، `Processor`، `WebhookDispatcher`، `WebhookResponse` |

## الأشكال الثلاثة للعمليات طويلة الأمد

1. **عامل الاستطلاع** (`bot.php`، الوضع `update_direct`) — يكرر حلقة `getUpdates` ← `Processor::handle()` ← تقديم الإزاحة. يعيد تشغيل نفسه بعد كل دفعة من دفعات `POLLING_RESTART_INTERVAL` لإبقاء الذاكرة محدودة؛ شغّله تحت إشراف نظام مراقبة عمليات.
2. **عامل الويب هوك** (`webhook.php`، FPM/Apache) — عديم الحالة لكل طلب؛ كل ما يحتاجه Telegram هو رمز حالة في الوقت المناسب. العمل الثقيل مكانه قائمة الانتظار (الوضع `webhook_queue`).
3. **عمال قائمة الانتظار** (`queue.php` منتج + `bot.php` مستهلك، الأوضاع `*_queue`) — الجالب يدفع والعمال يسحبون. أي عدد من عمليات المستهلك؛ والوسيط يوزّع التحديثات بينها.

## أين تسكن الحالة

| الحالة | الموقع | ملاحظات |
|-------|----------|-------|
| إزاحة getUpdates | `AppData/lastupdate.txt` | `OffsetStorage`، كتابات ذرية؛ أوضاع الاستطلاع فقط هي التي تلمسها |
| حمولات قائمة الانتظار | الوسيط (أو `AppData/queue/` لمُشغِّل json) | |
| عناصر إعادة المحاولة | `AppData/retry/` أو Redis (حسب `RETRY_QUEUE_DRIVER`) | |
| الجلسات | مُشغِّل الكاش (حسب `CACHE_DRIVER`) | مهلة TTL عبر `SESSION_TTL` |
| السجلات | `logs/` (حسب `LOG_DIR`) | يرفض التحقق من الإعدادات أي تجاوز للمسار |

مجلد `AppData/` مخصص لوقت التشغيل فقط ومستثنى من git — أعِد إنشائه بمجرد تشغيل البوت.

</div>
