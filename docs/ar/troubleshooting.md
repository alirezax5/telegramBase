<div dir="rtl">

# استكشاف الأخطاء وإصلاحها — الأعراض والأسباب والحلول

## الإقلاع والإعدادات

| العرَض | السبب المحتمل | الحل |
|---------|--------------|-----|
| ‏`BOT_TOKEN is not set or empty` عند `migrate:status` | سلوك قديم | أُصلح في النسخة الحالية — أوامر الترحيل لم تعد تتطلب رمزًا. إذا ما زلت تراه، شغّل مع `TGBASE_SKIP_BOT_VALIDATION=1` |
| ‏`could not find driver` عند `migrate` | ‏`DATABASE_ENABLE=true` دون مُشغِّل PDO/قاعدة بيانات مضبوطة | اضبط `DATABASE_ENABLE=false` إن لم تكن بحاجة إلى قاعدة بيانات، أو ثبّت مُشغِّل PDO |
| فشل الإقلاع بخطأ تحقق متعلق بالوضع | خطأ إملائي في `BOT_MODE` | استخدم واحدًا من هذه بالضبط: `update_direct`، `update_queue`، `webhook_direct`، `webhook_queue`، `cronjob_update`، `cronjob_queue` |
| تظهر السجلات في CWD بدلًا من `logs/` | سلوك قديم — كان مسار السجل النسبي يُحلّ نسبةً إلى CWD | أُصلح: يستخدم `LogHandler` ‏`LoggerConfig::absolutePath()` (نسبةً إلى جذر المشروع). كان هذا يؤثر أيضًا على تشغيل `bin/tgbase` من مجلد آخر |
| خطأ قاتل في `Session::regenerate()`: ‏«Cannot modify readonly property» | سلوك قديم | أُصلح: لم يعد `Session` يصرّح بـ `$sessionId` بصفتها readonly |
| فشل `tests/smoke.php` في كل الحالات السالبة | منطق match معطوب قديمًا | أُصلح في النسخة الحالية |
| الإضافات لا تُحمَّل | `PLUGINS_DIR` خاطئ، أو كاش الإضافات قديم | الافتراضي هو `/Plugin/` (نسبةً إلى جذر المشروع؛ المسارات المطلقة مدعومة). للتطوير اضبط `PLUGIN_CACHE_ENABLED=false`، ثم نفّذ `php bin/tgbase plugins` للتحقق من الاكتشاف |
| انهيار محدد المعدل/حارس المشرفين على استعلامات callback | وصول قديم غير nullsafe عبر `->message->chat->id` | أُصلح: سلاسل nullsafe + دعم `edited_message` / `channel_post` / `chat_member` |

## الويب هوك

| العرَض | السبب المحتمل | الحل |
|---------|--------------|-----|
| يعيد الويب هوك `403` لكل تسليم | القائمة البيضاء للـ IP مفعّلة لكن وكيل/CDN يعيد كتابة عنوان IP المصدر | خلف Cloudflare/nginx اضبط `WEBHOOK_CHECK_IP=false` واعتمد على `BOT_WEBHOOK_SECRET` قوي — انظر [webhook.md](webhook.md) |
| يعيد الويب هوك `403`، ويقول `getWebhookInfo` إن هناك عدم تطابق في السر | ‏`BOT_WEBHOOK_SECRET` في `.env` ≠ ‏`secret_token` الممرَّر إلى `setWebhook` | اجعلهما متطابقين؛ وبدّله بـ `openssl rand -hex 32` |
| يعيد الويب هوك `500` مع الرسالة ‏«BOT_MODE ... is CLI-only» | وضع CLI فقط على نقطة الدخول الويب (حارس SAPI في v2.4.0) | اضبط `BOT_MODE=webhook_direct` / `webhook_queue` لنقطة الدخول الويب، أو شغّل الوضع من الـ CLI (‏`bot.php` / `queue.php`) |
| ‏`getWebhookInfo`: ‏`last_error_message="Read timeout expired"` | لم تجب نقطة النهاية في الوقت المناسب (قبل v2.4.0 قد يكون هذا عيب الحلقة اللانهائية؛ وبعده يكون السبب معالجات بطيئة) | رقِّ إلى ≥ v2.4.0؛ وافحص المعالجات البطيئة؛ وانقل العمل الثقيل إلى `webhook_queue` |
| ‏`pending_update_count` يواصل النمو | نقطة النهاية لديك متوقفة/فاشلة باستمرار | افحص `last_error_message`، وصلاحية شهادة TLS، وأن `webhook.php` قابل للوصول؛ وطلب GET مجرد يعيد `400 empty` يثبت أن الموزّع حي |
| ردود الويب هوك تتأخر ثوانٍ | تعمل المعالجات مباشرةً أثناء طلب HTTP | انتقل إلى `webhook_queue` — عندها تجيب نقطة النهاية فورًا ويعالج العمال بشكل غير متزامن |

## قوائم الانتظار والعمال

| العرَض | السبب المحتمل | الحل |
|---------|--------------|-----|
| ‏`RabbitMQ ... "Broken pipe or closed connection"` عند الاتصال | عيب قديم في طريقة تسجيل الدخول (‏`default` ليست آلية AMQP صالحة) | أُصلح في v2.4.0 (يستخدم المُشغِّل الآن `AMQPLAIN`)؛ وتحقق من مستخدم/vhost لديك في RabbitMQ على أي حال |
| تُدفع التحديثات ولا تُعالَج أبدًا | لا مستهلك يعمل | تحتاج الأوضاع `*_queue` إلى الطرفين معًا: المنتج (`queue.php` أو `webhook.php`) **و**المستهلك (`php bot.php`) |
| تنمو قائمة الانتظار بلا حدود بينما العمال متوقفون | | اضبط `QUEUE_MAX_SIZE` (قوائم الانتظار المحدودة ترفض + تسجّل عند الامتلاء) |
| معالجة مكررة بعد انهيار عامل | متوقعة مع at-least-once (الإقرار المتأخر في RabbitMQ، وإعادة تسليم الويب هوك/الاستطلاع) | اجعل المعالجات قابلة لإعادة التنفيذ بأمان — انظر [reliability.md](reliability.md) |
| يخرج العامل بصمت تحت الضغط | مشرف غائب | شغّل كل العمليات طويلة الأمد تحت systemd/supervisord مع `Restart=always`؛ يعيد الإطار تشغيل الاستطلاع تلقائيًا كل `POLLING_RESTART_INTERVAL` دفعة، والمشرف يغطي كل ما عدا ذلك |
| ‏`409 Conflict` من `getUpdates` | عمليتان تستطلعان رمز البوت نفسه (أو ويب هوك + استطلاع معًا) | مصدر تحديثات واحد فقط: شغّل جالبًا واحدًا، ونفّذ `deleteWebhook` قبل بدء الاستطلاع |

## ملاحظات نشر تستحق وزنها

- **صلاحيات ملف السجل (FPM):** إذا كانت نقطة دخول الويب هوك عاجزة عن كتابة السجلات، فالسبب عادةً مالك الملف — يعمل PHP-FPM بصفة `www-data`/`www`، لذا يجب أن تكون ملفات/مجلدات السجلات قابلة للكتابة من قبل ذلك المستخدم (`chown www-data:www-data logs -R`). كتابة السجل الصامتة العديمة الأثر يصعب ملاحظتها غير ذلك.
- **‏`request_terminate_timeout` في PHP-FPM:** أي شيء تفعله معالج الويب هوك أطول من هذا الحد يموت بخطأ 502. أبقِ معالجة الويب هوك سريعة؛ واستخدم القائمة لما تبقى.
- **‏`php bin/tgbase doctor`** أسرع أداة فرز — يفحص إصدار PHP/الامتدادات، وقابلية قراءة `.env`، وقابلية كتابة `AppData/`، وقابلية كتابة السجلات، وتنسيق الرمز، دون لمس Telegram.
- **مصدرا تحديثات يتنازعان:** بعد التبديل بين الاستطلاع والويب هوك، تحقق من اكتمال التبديل (‏`deleteWebhook` أو توقف نظيف للاستطلاع) قبل تشغيل الطرف الآخر. كلاهما يعمل معًا = تعارضات إزاحة و409.

## مرجع سريع للتشخيص

```bash
php bin/tgbase doctor              # environment triage
php bin/tgbase plugins             # what the framework actually discovered
php tests/smoke.php                # 6-case bootstrap check
php tests/run.php --filter=Queue   # subsystem deep-dive
curl "https://api.telegram.org/bot<TOKEN>/getWebhookInfo"   # Telegram-side view
```

</div>
