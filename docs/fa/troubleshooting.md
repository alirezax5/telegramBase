<div dir="rtl">

# عیب‌یابی — نشانه‌ها، علت‌ها، رفع‌ها

## بوت و پیکربندی

| نشانه | علت احتمالی | رفع |
|---------|--------------|-----|
| خطای `BOT_TOKEN is not set or empty` روی `migrate:status` | رفتار قدیمی | در نسخهٔ فعلی رفع شده — دستورهای migrate دیگر به توکن نیاز ندارند. اگر هنوز می‌بینیدش، با `TGBASE_SKIP_BOT_VALIDATION=1` اجرا کنید |
| خطای `could not find driver` روی `migrate` | `DATABASE_ENABLE=true` است اما هیچ درایور PDO/دیتابیسی پیکربندی نشده | اگر به دیتابیس نیاز ندارید `DATABASE_ENABLE=false` بگذارید، یا درایور PDO را نصب کنید |
| بوت با خطای اعتبارسنجی مربوط به حالت شکست می‌خورد | `BOT_MODE` غلط املایی دارد | دقیقاً یکی از این‌ها را بنویسید: `update_direct`، `update_queue`، `webhook_direct`، `webhook_queue`، `cronjob_update`، `cronjob_queue` |
| لاگ‌ها به‌جای `logs/` در CWD ظاهر می‌شوند | رفتار قدیمی — مسیر نسبی لاگ نسبت به CWD resolve می‌شد | رفع شد: `LogHandler` از `LoggerConfig::absolutePath()` استفاده می‌کند (نسبی نسبت به ریشهٔ پروژه). اجرای `bin/tgbase` از دایرکتوری دیگر هم به همین مشکل می‌خورد |
| خطای fatal در `Session::regenerate()`: «Cannot modify readonly property» | رفتار قدیمی | رفع شد: `Session` دیگر `$sessionId` را readonly اعلان نمی‌کند |
| `tests/smoke.php` در همهٔ حالت‌های منفی شکست می‌خورد | منطق match خراب قدیمی | در نسخهٔ فعلی رفع شده |
| پلاگین‌ها لود نمی‌شوند | `PLUGINS_DIR` اشتباه، یا کش پلاگین کهنه | پیش‌فرض `/Plugin/` است (نسبی نسبت به ریشهٔ پروژه؛ مسیرهای مطلق پشتیبانی می‌شوند). برای توسعه `PLUGIN_CACHE_ENABLED=false` را تنظیم کنید و بعد با `php bin/tgbase plugins` کشف را تأیید کنید |
| کرش محدودکنندهٔ نرخ / نگهبان ادمین روی callback queryها | دسترسی قدیمی غیر-nullsafe با `->message->chat->id` | رفع شد: زنجیره‌های nullsafe + پشتیبانی از `edited_message` / `channel_post` / `chat_member` |

## وبهوک

| نشانه | علت احتمالی | رفع |
|---------|--------------|-----|
| وبهوک برای هر تحویلی `403` برمی‌گرداند | ویت‌لیست IP فعال است اما یک پروکسی/CDN آی‌پی مبدأ را بازنویسی می‌کند | پشت Cloudflare/nginx مقدار `WEBHOOK_CHECK_IP=false` را بگذارید و به یک `BOT_WEBHOOK_SECRET` قوی تکیه کنید — به [webhook.md](webhook.md) مراجعه کنید |
| وبهوک `403` برمی‌گرداند و `getWebhookInfo` عدم تطابق secret می‌گوید | `BOT_WEBHOOK_SECRET` در `.env` ≠ `secret_token`ای که به `setWebhook` داده‌اید | یکی‌شان کنید؛ با `openssl rand -hex 32` بچرخانید |
| وبهوک `500` برمی‌گرداند با پیام «BOT_MODE ... is CLI-only» | حالتِ مخصوص CLI روی نقطهٔ ورود وب (همان نگهبان SAPI نسخهٔ v2.4.0) | برای نقطهٔ ورود وب `BOT_MODE=webhook_direct` / `webhook_queue` را تنظیم کنید، یا آن حالت را از CLI اجرا کنید (`bot.php` / `queue.php`) |
| `getWebhookInfo`: `last_error_message="Read timeout expired"` | endpoint به‌موقع جواب نداد (قبل از v2.4.0 می‌توانست باگ حلقهٔ بی‌نهایت باشد؛ بعد از v2.4.0 یعنی هندلرهای کند) | به نسخهٔ ≥ v2.4.0 ارتقا دهید؛ هندلرهای کند را بررسی کنید؛ کارهای سنگین را به `webhook_queue` منتقل کنید |
| `pending_update_count` مدام بزرگ می‌شود | endpoint شما مدام از کار افتاده/شکست خورده است | `last_error_message`، اعتبار گواهی TLS و در دسترس بودن `webhook.php` را بررسی کنید؛ یک GET خالی که `400 empty` برگرداند ثابت می‌کند dispatcher زنده است |
| پاسخ‌های وبهوک چندثانیه‌ای تأخیر دارند | هندلرها درون درخواست HTTP اجرا می‌شوند | به `webhook_queue` سوئیچ کنید — endpoint بعدی بلافاصله جواب می‌دهد و ورکرها به‌صورت ناهمگام پردازش می‌کنند |

## صف‌ها و ورکرها

| نشانه | علت احتمالی | رفع |
|---------|--------------|-----|
| خطای `RabbitMQ ... "Broken pipe or closed connection"` هنگام اتصال | باگ قدیمی روشن ورود (`default` یک مکانیزم AMQP معتبر نیست) | در نسخهٔ v2.4.0 رفع شد (درایور حالا از `AMQPLAIN` استفاده می‌کند)؛ به هر حال user/vhost خود در RabbitMQ را بررسی کنید |
| آپدیت‌ها push می‌شوند اما هرگز پردازش نمی‌شوند | هیچ مصرف‌کننده‌ای در حال اجرا نیست | حالت‌های `*_queue` به هر دو طرف نیاز دارند: تولیدکننده (`queue.php` یا `webhook.php`) **و** مصرف‌کننده (`php bot.php`) |
| صف وقتی ورکرها از کار افتاده‌اند بی‌نهایت بزرگ می‌شود | | `QUEUE_MAX_SIZE` را تنظیم کنید (صف‌های محدود وقتی پر شوند رد می‌کنند + لاگ می‌کنند) |
| پردازش تکراری بعد از کرش یک ورکر | با at-least-once انتظار می‌رود (ack-late در RabbitMQ، تحویل دوبارهٔ وبهوک/polling) | هندلرها را idempotent کنید — به [reliability.md](reliability.md) نگاهی بیندازید |
| ورکر زیر بار بی‌صدا خارج می‌شود | سوپروایزر وجود ندارد | همهٔ پروسه‌های بلندمدت را زیر systemd/supervisord با `Restart=always` اجرا کنید؛ فریمورک خودش polling را هر `POLLING_RESTART_INTERVAL` بسته ری‌استارت می‌کند و سوپروایزر بقیهٔ موارد را پوشش می‌دهد |
| خطای `409 Conflict` از `getUpdates` | دو پروسه روی یک توکن ربات poll می‌زنند (یا وبهوک + polling همزمان) | فقط یک منبع آپدیت: یک fetcher واحد اجرا کنید و قبل از شروع polling دستور `deleteWebhook` را بزنید |

## نکات دیپلوی که ارزش طلا دارند

- **مجوزهای فایل لاگ (FPM):** اگر نقطهٔ ورود وبهوک نمی‌تواند لاگ بنویسد، معمولاً مشکل مالک فایل است — PHP-FPM با کاربر `www-data`/`www` اجرا می‌شود، پس فایل‌ها/دایرکتوری‌های لاگ باید برای آن کاربر قابل نوشتن باشند (`chown www-data:www-data logs -R`). یک نوشتنِ لاگِ بی‌صدا که عملاً no-op بوده، به‌راحتی دیده نمی‌شود.
- **`request_terminate_timeout` در PHP-FPM:** هر کاری که هندلر وبهوک شما بیشتر از این حد طول بکشد با یک 502 می‌میرد. پردازش وبهوک را سریع نگه دارید؛ بقیه را به صف بسپارید.
- **`php bin/tgbase doctor`** سریع‌ترین ابزار اولویت‌بندی مشکلات است — نسخهٔ PHP/افزونه‌ها، خوانا بودن `.env`، قابل نوشتن بودن `AppData/`، قابل نوشتن بودن لاگ و فرمت توکن را بدون دست زدن به تلگرام بررسی می‌کند.
- **دو منبع آپدیت که می‌جنگند:** بعد از جابه‌جایی بین polling و وبهوک، قبل از راه‌اندازی طرف دیگر، مطمئن شوید جابه‌جایی کامل شده است (`deleteWebhook` یا توقف تمیز polling). هر دو همزمان = تداخل آفست و خطاهای 409.

## مرجع سریع عیب‌یابی

```bash
php bin/tgbase doctor              # environment triage
php bin/tgbase plugins             # what the framework actually discovered
php tests/smoke.php                # 6-case bootstrap check
php tests/run.php --filter=Queue   # subsystem deep-dive
curl "https://api.telegram.org/bot<TOKEN>/getWebhookInfo"   # Telegram-side view
```

</div>
