<div dir="rtl">

# پیکربندی — مرجع کامل `.env`

فایل [.env.example](../../.env.example) را به `.env` کپی کنید و ویرایشش کنید. مقادیری که نشان داده شده‌اند پیش‌فرض‌ها هستند. اعتبارسنجی زمان بوت (`EnvironmentValidator`) مقادیر حیاتی را بررسی می‌کند و در صورت مشکل، به‌جای یک کرش مرموز با پیامی خوانا متوقف می‌شود.

> پیکربندی فقط از طریق متغیرهای محیطی واقعی (`Environment=` در systemd، `docker -e`، k8s) به همان اندازه پشتیبانی می‌شود — `.env` اختیاری است. وقتی هر دو وجود داشته باشند، محیط پروسه حرف آخر را می‌زند (Dotenv غیرقابل تغییر/immutable است).

## ربات

| کلید | پیش‌فرض | نکات |
|-----|---------|-------|
| `BOT_TOKEN` | — | **لازم**؛ از @BotFather. فرمت آن اعتبارسنجی می‌شود و هرگز در پیام‌های خطا بازتاب داده نمی‌شود. `TGBASE_SKIP_BOT_VALIDATION=1` اجازه می‌دهد دستورهای CLI مربوط به دیتابیس بدون توکن اجرا شوند |
| `BOT_MODE` | `update_direct` | یکی از شش حالت — به [bot-modes.md](bot-modes.md) مراجعه کنید |
| `BOT_API_URL` | `https://api.telegram.org` | برای سرورهای محلی Bot API بازنویسی کنید |
| `POLLING_TIMEOUT` | `30` | ثانیه‌های long-poll برای هر فراخوانی `getUpdates` |
| `POLLING_RESTART_INTERVAL` | `100` | تعداد بسته‌ها به ازای هر خود-ری‌استارت (بهداشت حافظه؛ مقدار `0` غیرفعالش می‌کند) |
| `BOT_WEBHOOK_URL` | — | URL نقطهٔ ورود وبهوک (HTTPS) |
| `BOT_WEBHOOK_SECRET` | — | توکن مخفی برای `X-Telegram-Bot-Api-Secret-Token`؛ دست‌کم ۳۲ کاراکتر هگز تصادفی استفاده کنید (`openssl rand -hex 32`) |
| `WEBHOOK_CHECK_IP` | `true` | اعتبارسنجی `REMOTE_ADDR` در برابر ساب‌نت‌های تلگرام — پشت Cloudflare/پروکسی‌ها آن را `false` بگذارید (در این حالت توکن مخفی لایهٔ احراز هویت شماست) |
| `DATA_DIR` | `AppData` | وضعیت زمان اجرا (آفست، فایل‌های صف، قفل‌ها) |

## پلاگین‌ها

| کلید | پیش‌فرض | نکات |
|-----|---------|-------|
| `PLUGINS_DIR` | `/Plugin/` | نسبت به ریشهٔ پروژه؛ مسیرهای مطلق پشتیبانی می‌شوند |
| `PLUGIN_CACHE_ENABLED` | `true` | کش‌کردن پلاگین‌های کشف‌شده بین بوت‌ها |
| `PLUGINS_RELOAD_INTERVAL` | `0` | فاصلهٔ زمانی بین اسکن‌های دوبارهٔ کشف بر حسب ثانیه (راحتی توسعه) |

## زبان و دکمه‌ها

| کلید | پیش‌فرض | نکات |
|-----|---------|-------|
| `LANG_DRIVER` | `json` | درایور فایل‌های ترجمه — ⚠️ فایل‌های همراه فریمورک آرایه‌های PHP هستند، بنابراین `.env.example` مقدار `php` را تنظیم می‌کند؛ در صورت ناهمخوانی، بی‌صدا به نام کلید برمی‌گردد (به [i18n.md](i18n.md) مراجعه کنید) |
| `DEFAULT_LANG` | `fa` | کد زبان جایگزین (fallback) |
| `LANG_DIR` | `/Language` | محل فایل‌های ترجمه |
| `LANG_CACHE_TTL` | `60` | ثانیه‌های کش ترجمه |
| `BUTTONS_DIR` | `/Button` | فایل‌های تعریف برچسب‌های کیبورد |
| `BUTTONS_FILE` | `btn.php` | نام فایل پایه |
| `BUTTONS_CACHE_TTL` | `60` | ثانیه‌های کش برچسب‌ها |
| `BUTTONS_CACHE_ENABLED` | `true` | برای اینکه تعاریف دکمه‌ها همیشه دوباره از فایل خوانده شوند `false` بگذارید |

## صف

| کلید | پیش‌فرض | نکات |
|-----|---------|-------|
| `QUEUE_SAVE_TYPE` | `json` | `json` \| `redis` \| `rabbitmq` \| `memcached` |
| `QUEUE_PATH` | `AppData/queue` | دایرکتوری ذخیره‌سازی درایور json |
| `QUEUE_MAX_SIZE` | `0` | سقف صف محدود (0 = نامحدود)؛ صفِ پر رد می‌کند + لاگ می‌کند |
| `QUEUE_MAX_RETRIES` | `3` | تلاش‌های مجدد عملیات برکر به ازای هر push/pop |
| `QUEUE_RETRY_DELAY` | `2` | ثانیه بین تلاش‌های مجدد برکر |
| `QUEUE_REDIS_KEY` / `QUEUE_MEMCACHED_KEY` | `bot_updates` | کلید لیست هر درایور |
| `RABBITMQ_QUEUE` | `bot_updates` | نام صف RabbitMQ |

### اتصال Redis

| کلید | پیش‌فرض |
|-----|---------|
| `REDIS_HOST` | `127.0.0.1` |
| `REDIS_PORT` | `6379` |
| `REDIS_PASSWORD` | *(خالی)* |
| `REDIS_DATABASE` | `0` |
| `REDIS_TIMEOUT` | `60` |

### اتصال RabbitMQ

| کلید | پیش‌فرض |
|-----|---------|
| `RABBITMQ_HOST` | `127.0.0.1` |
| `RABBITMQ_PORT` | `5672` |
| `RABBITMQ_USER` | `guest` |
| `RABBITMQ_PASSWORD` | `guest` |
| `RABBITMQ_VHOST` | `/` |

### اتصال Memcached

| کلید | پیش‌فرض |
|-----|---------|
| `MEMCACHED_HOST` | `127.0.0.1` |
| `MEMCACHED_PORT` | `11211` |
| `MEMCACHED_USERNAME` / `MEMCACHED_PASSWORD` | *(خالی — SASL)* |

## صف retry

| کلید | پیش‌فرض | نکات |
|-----|---------|-------|
| `RETRY_ENABLED` | `true` | کلید اصلی |
| `RETRY_QUEUE_DRIVER` | `json` | `json` \| `redis` |
| `RETRY_MAX_ATTEMPTS` | `3` | سپس حذف + لاگ |
| `RETRY_BASE_DELAY` | `2` | ثانیه؛ در هر تلاش دو برابر می‌شود؛ با `retry_after` تلگرام بازنویسی می‌شود |
| `RETRY_MAX_SIZE` | `1000` | سقف صف محدود |
| `RETRY_REDIS_KEY` | `bot_retry_queue` | وقتی درایور redis است |

## کش و سشن

| کلید | پیش‌فرض | نکات |
|-----|---------|-------|
| `CACHE_DRIVER` | `array` | `array` \| `file` \| `redis` \| `memcached` — درایورهای پشتیبانی‌نشده/در دسترس نبودن با هشدار لاگ‌شده به `array` برمی‌گردند |
| `CACHE_PREFIX` | `tgbase_` | پیشوند کلید برای همهٔ cache storeها |
| `CACHE_PATH` | پوشهٔ temp سیستم | دایرکتوری درایور file |
| `CACHE_TTL` | `300` | ثانیهٔ پیش‌فرض TTL وقتی `put()`/`remember()` بدون TTL صریح صدا زده شوند |
| `SESSION_TTL` | `3600` | عمر سشن بر حسب ثانیه |
| `SESSION_PREFIX` | `tgbase:session:` | پیشوند کلید سشن |

## دیتابیس

| کلید | پیش‌فرض | نکات |
|-----|---------|-------|
| `DATABASE_ENABLE` | `false` | همهٔ bootstrap دیتابیس را کنترل می‌کند (CLI بدون دیتابیس کار می‌کند) |
| `DB_DRIVER` | `mysql` | هر درایور PDO (`sqlite` برای توسعهٔ محلی) |
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `3306` | |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | — | |

## چندرباته

| کلید | پیش‌فرض | نکات |
|-----|---------|-------|
| `BOT_EXTRA_BOTS` | — | ربات‌های اضافی در یک پروسه — `name1:token1,name2:token2` (جدا کردن روی اولین دونقطه)؛ برای جزئیات [multi-bot.md](multi-bot.md) را ببینید. هم در `.env` کار می‌کند و هم در محیط پروسه |

## زمان‌بند و کرون

| کلید | پیش‌فرض | نکات |
|-----|---------|-------|
| `SCHEDULER_LOCK_TTL` | `300` | ثانیه‌های قفل تسک |
| `SCHEDULER_LOCK_TABLE` | `task_locks` | جدول بک‌اند قفل |
| `SCHEDULE_FILE` | `bin/tasks.php` | فایل تسک مورد استفادهٔ `bin/scheduler.php` (بازنویسی مسیر) |
| `CRON_WORKER` | `10` | اسلات‌های همزمان برای `cronjob_queue` (با قفل فایل) |
| `CRON_MAX_TIME` | `50` | حداکثر ثانیه برای هر تیک کرونِ ورکر |

## لاگ‌گیری

| کلید | پیش‌فرض | نکات |
|-----|---------|-------|
| `LOG_ENABLED` | `true` | کلید اصلی |
| `LOG_LEVEL` | `debug` | سطوح Monolog |
| `LOG_DIR` | `./logs` | **فقط نام‌های نسبی** — مسیرهای مطلق و `..` رد می‌شوند (محافظت در برابر path traversal) |
| `LOG_FILE` | `log.txt` | |

## ادمین‌ها

| کلید | پیش‌فرض | نکات |
|-----|---------|-------|
| `ADMINS_WHITELIST` | — | شناسه‌های کاربری تلگرام جدا‌شده با کاما، استفاده‌شده توسط `AdminGuardMiddleware` |

## نکات امنیتی

- هرگز `.env` را کامیت نکنید؛ `.gitignore` موجود آن را مستثنا می‌کند (`*.secret` و `secrets.json` هم همین‌طور).
- پیام‌های خطای `EnvironmentValidator` نام کلیدِ جاافتاده را می‌گویند، نه مقدارش را.
- توکن‌های مخفی وبهوک با `hash_equals()` مقایسه می‌شوند (زمان ثابت).
- عمداً **هیچ دسته‌کلیدی به نام `VERIFY_TLS=false` وجود ندارد** — اعتبارسنجی TLS به api.telegram.org همیشه روشن است.

</div>
