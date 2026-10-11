<div dir="rtl">

# الإعدادات — المرجع الكامل لـ `.env`

انسخ [.env.example](../../.env.example) إلى `.env` وعدّله. القيم المعروضة هي الافتراضية. يتحقق التحقق وقت الإقلاع (`EnvironmentValidator`) من القيم الحرجة ويفشل برسالة مقروءة بدلًا من انهيار غامض.

> الإعداد عبر متغيرات بيئة حقيقية فقط (‏`Environment=` في systemd، و`docker -e`، وk8s) مدعوم بالتساوي — `.env` اختياري. عندما يوجد كلاهما، تتفوق بيئة العملية (Dotenv غير قابل للتغيير).

## البوت

| المفتاح | الافتراضي | ملاحظات |
|-----|---------|-------|
| `BOT_TOKEN` | — | **إلزامي**؛ من @BotFather. يُتحقق من صحة تنسيقه؛ ولا يُطبع أبدًا في رسائل الخطأ. يتيح `TGBASE_SKIP_BOT_VALIDATION=1` لأوامر CLI المتعلقة بقاعدة البيانات فقط العمل دون رمز |
| `BOT_MODE` | `update_direct` | أحد الأوضاع الستة — انظر [bot-modes.md](bot-modes.md) |
| `BOT_API_URL` | `https://api.telegram.org` | تجاوز لخوادم Bot API المحلية |
| `POLLING_TIMEOUT` | `30` | ثواني الاستطلاع الطويل لكل استدعاء `getUpdates` |
| `POLLING_RESTART_INTERVAL` | `100` | الدفعات بين كل إعادة تشغيل ذاتي (نظافة ذاكرة؛ `0` يُلغيها) |
| `BOT_WEBHOOK_URL` | — | عنوان URL لنقطة نهاية الويب هوك (HTTPS) |
| `BOT_WEBHOOK_SECRET` | — | الرمز السري للترويسة `X-Telegram-Bot-Api-Secret-Token`؛ استخدم 32 حرفًا سادس عشريًا عشوائيًا أو أكثر (`openssl rand -hex 32`) |
| `WEBHOOK_CHECK_IP` | `true` | التحقق من `REMOTE_ADDR` مقابل شبكات Telegram الفرعية — اضبط `false` خلف Cloudflare/الوكلاء (ويصبح عندئذٍ الرمز السري طبقة مصادقتك) |
| `DATA_DIR` | `AppData` | حالة وقت التشغيل (الإزاحة، ملفات قائمة الانتظار، الأقفال) |

## الإضافات

| المفتاح | الافتراضي | ملاحظات |
|-----|---------|-------|
| `PLUGINS_DIR` | `/Plugin/` | نسبةً إلى جذر المشروع؛ المسارات المطلقة مدعومة |
| `PLUGIN_CACHE_ENABLED` | `true` | تخزين الإضافات المكتشفة في الكاش بين الإقلاعات |
| `PLUGINS_RELOAD_INTERVAL` | `0` | الثواني بين كل إعادة مسح للاكتشاف (تسهيل للتطوير) |

## اللغة والأزرار

| المفتاح | الافتراضي | ملاحظات |
|-----|---------|-------|
| `LANG_DRIVER` | `json` | مُشغِّل ملفات الترجمة — ⚠️ الملفات المشحونة هي مصفوفات PHP، لذا يضبط `.env.example` القيمة `php`؛ عدم التطابق يعود بصمت إلى اسم المفتاح (انظر [i18n.md](i18n.md)) |
| `DEFAULT_LANG` | `fa` | رمز اللغة الاحتياطية |
| `LANG_DIR` | `/Language` | موقع ملفات الترجمة |
| `LANG_CACHE_TTL` | `60` | ثواني كاش الترجمات |
| `BUTTONS_DIR` | `/Button` | ملفات تعريف تسميات لوحات المفاتيح |
| `BUTTONS_FILE` | `btn.php` | اسم الملف الأساسي |
| `BUTTONS_CACHE_TTL` | `60` | ثواني كاش التسميات |
| `BUTTONS_CACHE_ENABLED` | `true` | اضبط `false` لإعادة قراءة تعريفات الأزرار من الملفات دائمًا |

## قائمة الانتظار

| المفتاح | الافتراضي | ملاحظات |
|-----|---------|-------|
| `QUEUE_SAVE_TYPE` | `json` | `json` \| `redis` \| `rabbitmq` \| `memcached` |
| `QUEUE_PATH` | `AppData/queue` | مجلد تخزين مُشغِّل json |
| `QUEUE_MAX_SIZE` | `0` | سقف قائمة الانتظار المحدودة (0 = بلا حد)؛ القائمة الممتلئة ترفض + تسجّل |
| `QUEUE_MAX_RETRIES` | `3` | إعادات محاولة عمليات الوسيط لكل دفع/سحب |
| `QUEUE_RETRY_DELAY` | `2` | الثواني بين إعادات محاولة الوسيط |
| `QUEUE_REDIS_KEY` / `QUEUE_MEMCACHED_KEY` | `bot_updates` | مفتاح القائمة لكل مُشغِّل |
| `RABBITMQ_QUEUE` | `bot_updates` | اسم قائمة RabbitMQ |

### اتصال Redis

| المفتاح | الافتراضي |
|-----|---------|
| `REDIS_HOST` | `127.0.0.1` |
| `REDIS_PORT` | `6379` |
| `REDIS_PASSWORD` | *(فارغ)* |
| `REDIS_DATABASE` | `0` |
| `REDIS_TIMEOUT` | `60` |

### اتصال RabbitMQ

| المفتاح | الافتراضي |
|-----|---------|
| `RABBITMQ_HOST` | `127.0.0.1` |
| `RABBITMQ_PORT` | `5672` |
| `RABBITMQ_USER` | `guest` |
| `RABBITMQ_PASSWORD` | `guest` |
| `RABBITMQ_VHOST` | `/` |

### اتصال Memcached

| المفتاح | الافتراضي |
|-----|---------|
| `MEMCACHED_HOST` | `127.0.0.1` |
| `MEMCACHED_PORT` | `11211` |
| `MEMCACHED_USERNAME` / `MEMCACHED_PASSWORD` | *(فارغ — SASL)* |

## قائمة إعادة المحاولة

| المفتاح | الافتراضي | ملاحظات |
|-----|---------|-------|
| `RETRY_ENABLED` | `true` | المفتاح الرئيسي |
| `RETRY_QUEUE_DRIVER` | `json` | `json` \| `redis` |
| `RETRY_MAX_ATTEMPTS` | `3` | بعدها يُهمَل العنصر + يُسجَّل ذلك |
| `RETRY_BASE_DELAY` | `2` | ثوانٍ؛ تتضاعف مع كل محاولة؛ ويتجاوزها `retry_after` من Telegram |
| `RETRY_MAX_SIZE` | `1000` | سقف قائمة الانتظار المحدودة |
| `RETRY_REDIS_KEY` | `bot_retry_queue` | عندما يكون المُشغِّل redis |

## الكاش والجلسة

| المفتاح | الافتراضي | ملاحظات |
|-----|---------|-------|
| `CACHE_DRIVER` | `array` | `array` \| `file` \| `redis` \| `memcached` — المُشغِّلات غير المدعومة/غير المتاحة تعود إلى `array` مع تسجيل تحذير |
| `CACHE_PREFIX` | `tgbase_` | بادئة المفاتيح لكل مخازن الكاش |
| `CACHE_PATH` | المجلد المؤقت للنظام | مجلد مُشغِّل الملفات |
| `CACHE_TTL` | `300` | ثواني TTL الافتراضية عندما لا يتلقى `put()`/`remember()` مهلة صريحة |
| `SESSION_TTL` | `3600` | ثواني عمر الجلسة |
| `SESSION_PREFIX` | `tgbase:session:` | بادئة مفاتيح الجلسات |

## قاعدة البيانات

| المفتاح | الافتراضي | ملاحظات |
|-----|---------|-------|
| `DATABASE_ENABLE` | `false` | تتحكم بكل تهيئة قاعدة البيانات عند الإقلاع (CLI يعمل دون قاعدة بيانات) |
| `DB_DRIVER` | `mysql` | أي مُشغِّل PDO (`sqlite` للتطوير المحلي) |
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `3306` | |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | — | |

## تعدد البوتات

| المفتاح | الافتراضي | ملاحظات |
|-----|---------|-------|
| `BOT_EXTRA_BOTS` | — | بوتات إضافية في عملية واحدة — بصيغة `name1:token1,name2:token2` (يُفصل عند أول نقطتين)؛ انظر [multi-bot.md](multi-bot.md). يعمل في `.env` وفي بيئة العملية |

## المجدول وcron

| المفتاح | الافتراضي | ملاحظات |
|-----|---------|-------|
| `SCHEDULER_LOCK_TTL` | `300` | ثواني قفل المهمة |
| `SCHEDULER_LOCK_TABLE` | `task_locks` | جدول خلفية القفل |
| `SCHEDULE_FILE` | `bin/tasks.php` | ملف المهام المستخدم من `bin/scheduler.php` (تجاوز المسار) |
| `CRON_WORKER` | `10` | الفتحات المتزامنة لـ `cronjob_queue` (مقفلة بملفات) |
| `CRON_MAX_TIME` | `50` | أقصى ثوانٍ لكل نبضة cron للعامل |

## التسجيل

| المفتاح | الافتراضي | ملاحظات |
|-----|---------|-------|
| `LOG_ENABLED` | `true` | المفتاح الرئيسي |
| `LOG_LEVEL` | `debug` | مستويات Monolog |
| `LOG_DIR` | `./logs` | **أسماء نسبية فقط** — تُرفض المسارات المطلقة و`..` (حماية من تجاوز المسار) |
| `LOG_FILE` | `log.txt` | |

## المشرفون

| المفتاح | الافتراضي | ملاحظات |
|-----|---------|-------|
| `ADMINS_WHITELIST` | — | معرفات مستخدمي Telegram مفصولة بفواصل، تستخدمها `AdminGuardMiddleware` |

## ملاحظات أمنية

- لا تُرسِل `.env` أبدًا إلى المستودع؛ `.gitignore` المرفق يستثنيه (وأيضًا `*.secret` و`secrets.json`).
- رسائل خطأ `EnvironmentValidator` تذكر المفتاح المفقود، ولا تذكر قيمته أبدًا.
- تُقارن أسرار الويب هوك عبر `hash_equals()` (بزمن ثابت).
- لا يوجد عمدًا **أي مفتاح `VERIFY_TLS=false`** — التحقق من TLS إلى api.telegram.org دائمًا مفعّل.

</div>
