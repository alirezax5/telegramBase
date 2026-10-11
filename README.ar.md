<div dir="rtl">

# TelegramBase

[English](README.md) · [فارسی](README.fa.md) · [中文](README.zh.md) · [Русский](README.ru.md) · [Français](README.fr.md) · [Português](README.pt.md) · **العربية**

إطار عمل مصغّر معياري بلغة PHP 8.1+ لبناء بوتات Telegram — بنظام إضافات، وواجهات خلفية لقوائم الانتظار، وتحقق من صحة الويب هوك، وجلسات، ووسطاء برمجيين، ومجدول، وترحيلات قواعد بيانات، وتوليد هياكل عبر CLI. مبني على مكوّنات Illuminate (Laravel) بدلًا من إعادة اختراعها.

## ما هذا، في فقرة واحدة؟

يتولى TelegramBase *التوصيلات* التي يحتاجها كل بوت Telegram — استقبال التحديثات (استطلاع أو ويب هوك)، والتحقق من صحة طلبات الويب هوك، وتوزيع التحديثات إلى كودك، وإعادة المحاولة لاستدعاءات الـ API الفاشلة، والتحدث إلى واجهات قوائم الانتظار الخلفية مثل Redis وRabbitMQ — بحيث تكتب أنت الجزء *الممتع* فقط: أصناف PHP صغيرة تُسمى **إضافات** تتفاعل مع الرسائل. ضع صنفًا في `Plugin/`، وسيكتشفه الإطار ويشغّله. هذه هي الفكرة كلها.

## لماذا وُجد؟

كتابة بوت «مباشرةً مقابل الـ API» أمر سهل حتى يصل حركة المرور الحقيقية. عندها تحتاج إجابات لأسئلة مثل: *ماذا يحدث إذا انهار عامل معالجتي في منتصف تحديث؟ أين تُتحقق طلبات الويب هوك؟ كيف أعيد المحاولة عند `429 Too Many Requests` دون فقدان التحديث؟ هل أستطيع إخراج العمل الثقيل من طلب HTTP؟* يجيب TelegramBase عن كل ذلك مسبقًا:

- **ستة أوضاع تشغيل** (‏`update_direct`، `update_queue`، `webhook_direct`، `webhook_queue`، `cronjob_update`، `cronjob_queue`) — بدّل بين الاستطلاع والويب هوك، وبين المعالجة المباشرة والموجّهة إلى القائمة، بتغيير قيمة واحدة في `.env`.
- **دلالات تسليم صادقة** — خضع الإطار لاختبارات انهيار من طرف إلى طرف (SIGKILL داخل كل مرحلة من مراحل خط المعالجة). ضمانة كل موضع موثّقة: مسارات الويب هوك/المباشرة *at-least-once*، وخط أنابيب RabbitMQ *at-least-once* (إقرار بعد المعالجة)، والمُشغِّلات المُدمِّرة (Redis/JSON/Memcached) *at-most-once*. انظر [docs/reliability.md](docs/ar/reliability.md).
- **مصان ضد سوء الإعدادات الواقعي** — تشغيل وضع CLI فقط خلف نقطة دخول الويب هوك يُرفض برمز HTTP 500 بدلًا من تعليق عامل الويب لديك في حلقة استطلاع لا نهائية (عيب اصطدمنا به فعلًا في الاختبارات الإنتاجية).
- **البطاريات مشمولة** — جلسات، وباني لوحات مفاتيح، وتعدد لغات (i18n)، ووسطاء برمجيون (حد معدل / حارس مشرفين / سجل تدقيق)، ومجدول، وترحيلات Eloquent، وقوائم انتظار محدودة، وإعادة محاولة تلقائية مع مراعاة `retry_after` من Telegram.

## المتطلبات

- PHP 8.1+
- Composer
- الامتدادات: `json`، `mbstring`، `curl`
- اختياري: `redis`، `memcached`، `amqp` (واجهات قوائم الانتظار/الكاش الخلفية)
- اختياري: MySQL/MariaDB (قاعدة البيانات، ترحيلات Eloquent)

## البدء السريع

```bash
git clone https://github.com/alirezax5/telegramBase.git
cd telegramBase
composer install
cp .env.example .env
# Edit .env — set BOT_TOKEN (get one from @BotFather)
php bot.php
```

أرسل إلى بوتك الأمر `/start` — تجيب إضافة البداية في `Plugin/start.php`. ثم اكتب إضافتك الخاصة (انظر [docs/plugins.md](docs/ar/plugins.md)).

## كيف يسري التحديث

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

## الوثائق

كل شيء يسكن في `docs/` — ابدأ بنظرة عامة:

| الوثيقة | ما بداخلها |
|----------|---------------|
| [docs/overview.md](docs/ar/overview.md) | ما هو الإطار، وماذا يفعل، وما لا يفعله عمدًا |
| [docs/architecture.md](docs/ar/architecture.md) | تسلسل الإقلاع، ومسار التحديث، وخريطة مكوّنات `App/` |
| [docs/getting-started.md](docs/ar/getting-started.md) | التثبيت ← أول إضافة ← النشر، خطوة بخطوة |
| [docs/bot-modes.md](docs/ar/bot-modes.md) | كل أوضاع `BOT_MODE` الستة، ونقاط الدخول، وأي العمليات تشغّل |
| [docs/plugins.md](docs/ar/plugins.md) | كتابة الإضافات: دوال المعالجة، والأولوية، و`#[ChatType]`، والاكتشاف |
| [docs/queue-pipeline.md](docs/ar/queue-pipeline.md) | واجهات قوائم الانتظار الخلفية، والعمال، وقائمة إعادة المحاولة، ودلالات الإقرار |
| [docs/webhook.md](docs/ar/webhook.md) | إعداد الويب هوك، وطبقات التحقق الخمس، وملاحظات الوكيل/CDN |
| [docs/reliability.md](docs/ar/reliability.md) | نتائج اختبارات الانهيار: ضمانات التسليم لكل وضع، وإرشادات الالتزام (idempotency) |
| [docs/configuration.md](docs/ar/configuration.md) | المرجع الكامل لـ `.env` |
| [docs/features.md](docs/ar/features.md) | الجلسات، ولوحات المفاتيح، والوسطاء البرمجيين، والكاش، وi18n، والمجدول، وقاعدة البيانات |
| [docs/caching.md](docs/ar/caching.md) | تعمّق في CacheManager: المُشغِّلات، ومهل TTL، ونطاق flush، ومصفوفة العبور بين العمليات (مُتحقَّق منها مباشرة) |
| [docs/sessions.md](docs/ar/sessions.md) | حالة الجلسة لكل محادثة: الـ API، والتدفقات متعددة الخطوات، والملاحظات الأمنية |
| [docs/i18n.md](docs/ar/i18n.md) | الترجمات: تخطيط الملفات، والدالة `__()`، ولغة كل مستخدم، والتخزين المؤقت |
| [docs/buttons.md](docs/ar/buttons.md) | باني لوحات المفاتيح + القوائم المعرفة في ملفات (‏`btn()`)، ومعالجة الـ callback |
| [docs/multi-bot.md](docs/ar/multi-bot.md) | عدة بوتات في عملية واحدة: ‏`BotManager` و`BOT_EXTRA_BOTS` ومجموعة الرموز (مُتحقَّق منها مباشرة) |
| [docs/testing.md](docs/ar/testing.md) | المجموعة المحلية، واختبارات Telegram المباشرة، واختبار الدخان، والـ CI |
| [docs/troubleshooting.md](docs/ar/troubleshooting.md) | الأعراض والحلول الشائعة، ومفارقات النشر |

ملفات مفيدة أخرى:

- [CHANGELOG.md](CHANGELOG.md) — ما الذي تغيّر في كل نسخة
- [llms.txt](llms.txt) / [llms-ctx.txt](llms-ctx.txt) — فهرس وثائق موجّه لنماذج LLM وسياق بملف واحد
- [.env.example](.env.example) — كل مفتاح إعدادات مع قيمه الافتراضية
- [examples/HelloBot.php](examples/HelloBot.php) — إضافة مثال كاملة

## واجهة الـ CLI

```bash
php bin/tgbase list              # all commands
php bin/tgbase make:plugin admin/ban   # scaffold a plugin
php bin/tgbase doctor            # environment diagnostics
php bin/tgbase plugins           # list discovered plugins
php bin/tgbase migrate           # run database migrations
```

## الرخصة

MIT

</div>
