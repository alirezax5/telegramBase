<div dir="rtl">

# الاختبار — المجموعة المحلية، والاختبارات المباشرة، والـ CI

## مجموعة الاختبارات المحلية (offline)

يشحن الإطار مع مشغّل اختبارات بلا تبعيات (لا حاجة إلى PHPUnit):

```bash
composer install        # vendor/ must exist (the suite boots the framework)
php tests/run.php
```

البنية:

| المجلد | ما الذي يسكن فيه |
|-----------|------------------|
| `tests/Unit/` | اختبارات وحدة خالصة — معالج البيئة، والإعدادات، ومُشغِّلات قائمة الانتظار، وتخزين الإزاحة، وتحقق موزّع الويب هوك، وتوصيل الوسطاء البرمجيين، ودورة حياة إعادة المحاولة، ... (بلا شبكة) |
| `tests/Integration/` | اختبارات متعددة المكوّنات (ما تزال محلية) |
| `tests/Live/` | **اختبارات Telegram API الحقيقية — بالتفعيل الاختياري فقط** (انظر أدناه) |
| `tests/smoke.php` | اختبار دخان مستقل للإقلاع (رمز خاطئ، رمز فارغ، وضع خاطئ، إقلاع سليم) |
| `tests/benchmark.php` | قياسات مرجعية مصغّرة للمسارات الساخنة |

التصفية بالاسم:

```bash
php tests/run.php --filter=Keyboard
php tests/run.php --filter=Queue
```

## الانحراف المعروف في المجموعة — مُحلول

حتى v2.4.0 كان التشغيل المحلي يبلّغ عن **196 ناجح / 18 فاشل / 12 متخطى**. كانت الإخفاقات الـ 18 (‏`SessionTest` ×10، و`BackwardCompatTest` ×4، و`MiddlewareWiringTest` ×4) سابقة للوجود، وقد أُثبت عدم علاقتها بإصلاحات الإطار بالرجوع بكل إصلاح وإعادة التشغيل.

وُجد السبب الجذري أثناء مراجعة ما قبل الإطلاق (P-REL-B05): يبذر `tests/run.php` قيم البيئة الافتراضية ويستدعي `Bootstrap::boot()`، لكن كان `EnvironmentBootstrap` يستخدم `load()` من Dotenv، التي **ترمي استثناءً عندما لا يحوي المستودع `.env`** (النسخة المستنسخة حديثًا تشحن `.env.example` فقط). فكان الإقلاع يُجهَض، ويبقى `Config` غير مهيأ، ويفشل كل اختبار يعتمد على الإعدادات. وبالتحول إلى `safeLoad()` تجري المجموعة بنجاح في نسخة حديثة الاستنساخ دون أي إعداد:

```
Pass: 224  Fail: 0  Skip: 12
```

## اختبارات Telegram المباشرة

تصطدم الاختبارات المباشرة بـ Bot API الحقيقي وتُتخطَّى ما لم تُفعَّل صراحةً:

```bash
RUN_TELEGRAM_LIVE_TESTS=1 \
TELEGRAM_BOT_TOKEN="<your-token>" \
TELEGRAM_TEST_CHAT_ID="<your-chat-id>" \
php tests/run.php
```

القواعد المنزلية (يفرضها الاختبارات نفسها):

- كل رسالة ترسلها موسومة بـ `[telegramBase automated test]`
- تحذف رسائلها الخاصة بعد التحقق حيثما أمكن — ولا تحذف أبدًا رسائل لم تُنشئها
- المسارات السالبة مشمولة: محادثة غير صالحة، رمز غير صالح، 429 مُحاكى
- يُقرأ الرمز **فقط** من متغيرات البيئة

## الـ CI (GitHub Actions)

يعمل `.github/workflows/tests.yml` على كل push/PR عبر PHP ‏**8.1 – 8.4**: تحقق composer، و`composer audit`، والمجموعة المحلية.

أما `.github/workflows/live-tests.yml` فهو سير عمل **يدوي** (`workflow_dispatch`) للاختبارات المباشرة؛ يقرأ الرمز من سر المستودع `TGBASE_LIVE_BOT_TOKEN` ومن مدخل `chat_id` — لا أسرار في ملف سير العمل، ولا يلمس الـ CI الافتراضي الأسرار أبدًا.

## اختبار الدخان

```bash
php tests/smoke.php
```

ست حالات: رمز خاطئ / رمز فارغ / محاولة اجتياز مسار السجلات / مسار مطلق للسجلات / وضع خاطئ / إقلاع سليم. استخدمه بعد النشرات بوصفه فحص صحة من 5 ثوانٍ.

## ماذا تغطي المجموعة (ولماذا يهم ذلك للثقة)

ادعاءات الموثوقية في [reliability.md](reliability.md) مدعومة باختبارات إضافة إلى سيناريوهات تكاملية مُختبرة بالانهيار (SIGKILL في كل مرحلة من مراحل خط المعالجة، وفحوصات إعادة تسليم حقيقية من الوسيط، واختبارات انتقال الويب هوك مع التحقق عبر `getWebhookInfo`). المجموعات الأكثر صلة بمراجعة تلك الادعاءات:

```bash
php tests/run.php --filter=Queue      # driver behaviour (json/redis/rabbitmq contracts)
php tests/run.php --filter=Offset     # offset storage atomicity
php tests/run.php --filter=Retry      # retry queue lifecycle + retry_after
php tests/run.php --filter=Webhook    # the 5 webhook validation layers
php tests/run.php --filter=Security   # env/log hardening rules
```

حاضنة التكامل المستخدمة أثناء اختبارات الانهيار (بوت حقيقي + سكربتات سيناريو) تسكن في `integration/TestBot/` — انظر README الخاص بها لما تفعله وكيف تلائمها لنشرتك الخاصة.

</div>
