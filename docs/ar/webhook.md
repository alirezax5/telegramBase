<div dir="rtl">

# الويب هوك — استقبال التحديثات من Telegram

## الإعداد

```dotenv
# .env
BOT_MODE="webhook_direct"          # or webhook_queue
BOT_WEBHOOK_URL="https://yourdomain.com/webhook.php"
BOT_WEBHOOK_SECRET="a-long-random-string"   # openssl rand -hex 32
WEBHOOK_CHECK_IP=true
```

التسجيل مرة واحدة (يشترط Telegram وجود HTTPS بشهادة صالحة):

```bash
curl "https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://yourdomain.com/webhook.php&secret_token=<SECRET>"
curl "https://api.telegram.org/bot<TOKEN>/getWebhookInfo"   # verify: pending_update_count=0, no last_error
```

إلغاء التسجيل (عند العودة إلى الاستطلاع — افعل هذا *قبل* تشغيل `bot.php`، إذ يتنازع مصدران للتحديثات على نفس التدفق):

```bash
curl "https://api.telegram.org/bot<TOKEN>/deleteWebhook"
```

## طبقات التحقق الخمس

كل طلب يصل إلى `webhook.php` يمر عبر `WebhookDispatcher` بهذا الترتيب:

1. **حجم الجسم** — فوق 1 MB ← `413`. (لا يرسل Telegram أبدًا حمولات أكبر؛ أي شيء أكبر ليس من Telegram.)
2. **القائمة البيضاء لعناوين IP** — مع `WEBHOOK_CHECK_IP=true`، الطلبات القادمة من عناوين IP خارج الشبكات الفرعية الرسمية لـ Telegram ← `403`. النطاقات هي المنشورة في وثائق Telegram (`149.154.160.0/20`، `91.108.4.0/22`، `91.108.8.0/22`، `91.108.56.0/22`، `91.108.16.0/22`، `95.161.64.0/20`).
3. **الرمز السري** — مع ضبط `BOT_WEBHOOK_SECRET`، يجب أن يطابق الترويسة `X-Telegram-Bot-Api-Secret-Token`، وتتم المقارنة عبر `hash_equals()` (آمنة ضد هجمات التوقيت). عدم التطابق ← `403`.
4. **التحقق من JSON** — يجب أن يُحلَّل الجسم إلى كائن يحتوي `update_id`. وإلا ← `400`.
5. **التوجيه** — يشغّل `webhook_direct` الإضافات بشكل متزامن ثم يجيب `200` فقط إذا لم يرمَ أي شيء؛ أما `webhook_queue` فيضيف إلى قائمة الانتظار ويجيب `200` فورًا.

الفشل في التحقق لا يستهلك التحديث أبدًا بطريقة تفقده — فـ Telegram يعيد إرسال التحديثات التي لم يتلقَّ لها `2xx`.

## خلف Cloudflare / وكلاء nginx (مهم)

إذا كانت طلبات Telegram تصل إلى PHP عبر Cloudflare (أو أي وكيل عكسي)، يكون `REMOTE_ADDR` في PHP هو عنوان IP *للوكيل* وليس عنوان Telegram — لذا سيرفض فحص الـ IP كل طلب. إعدادان عمليان:

- **الوكيل في المقدمة:** أبقِ `WEBHOOK_CHECK_IP=false` واعتمد على **الرمز السري** بصفتك مصادقة الدخول (اجعله طويلًا — 32 حرفًا سادس عشريًا أو أكثر). هذا بالضبط ما جرى التحقق منه في الاختبارات الإنتاجية للإطار.
- **الوكيل يمرر الـ IP الحقيقي:** اضبط وكيلك ليُرسل `X-Forwarded-For` / `X-Real-IP` *و*ليثق PHP بها — حينها يعمل `WEBHOOK_CHECK_IP=true`. لا تفعل ذلك إلا إذا كنت تتحكم بالوكيل، وإلا يمكن تزييف الترويسة.

قاعدة عامة: **فحص الـ IP = تعرّض مباشر؛ والرمز السري = تعرّض عبر وكيل.** واستخدام كليهما معًا جيد أيضًا.

## سلوك الأوضاع على نقطة دخول الويب هوك

| `BOT_MODE` | ما يفعله `webhook.php` |
|------------|--------------------------|
| `webhook_direct` | تحقق ← `Processor::handle()` ← `200` عند الانتهاء |
| `webhook_queue` | تحقق ← إضافة إلى قائمة الانتظار ← `200` فورًا |
| `update_direct` | تحقق ← `Processor::handle()` ← `200` (يعمل، لكنه يسجّل تحذيرًا — يُفضَّل تحديد `webhook_direct` صراحة) |
| `update_queue`، `cronjob_*` | `500` + رسالة توضيحية — هذه الأوضاع تعمل بـ CLI فقط |

الصف الأخير هو حارس SAPI في v2.4.0 ([bot-modes.md](bot-modes.md)): لم يعد وضع CLI على نقطة الدخول الويب قادرًا على بدء حلقة لا نهائية داخل عامل PHP-FPM لديك (ما كان ينتهي برسالة `Read timeout expired` من جانب Telegram و502 من خادم الويب لديك).

## دلالات الاستجابة — لماذا تُهم 200

يعيد Telegram محاولة أي تسليم ويب هوك لا يحصل على `2xx`، مع تراجع تدريجي، ولمدة تصل إلى عدة ساعات. يستغل الإطار ذلك بصدق:

- تُرسَل `200` **فقط بعد** أن تُعالَج العملية أو يُضمن وضعها في القائمة بأمان.
- استثناء أثناء المعالجة ← `500` ← يعيد Telegram التسليم لاحقًا ← التحديث **لا يُفقد**، لكنه *ستُعالَج مرتين* إذا كانت المحاولة الأولى قد اكتملت فعلًا قبل الانهيار. هذا هو تعريف التسليم at-least-once — انظر [reliability.md](reliability.md) لجعل المعالجات قابلة لإعادة التنفيذ بأمان.
- العامل الذي يستغرق دقائق للإجابة خطأ وليس ميزة: مهلة عميل Telegram هي ~60 ثانية. انقل العمل البطيء إلى `webhook_queue`.

## التسجيل مع أنواع تحديثات مسموحة (اختياري)

قيّد ما يرسله Telegram إليك بأنواع التحديثات التي تعالجها إضافاتك فعلًا:

```bash
curl "https://api.telegram.org/bot<TOKEN>/setWebhook?url=...&secret_token=...&allowed_updates=[\"message\",\"edited_message\",\"callback_query\",\"channel_post\",\"edited_channel_post\"]"
```

## فحوصات الصحة

`getWebhookInfo` هو لوحة معلوماتك — راقب:

- `pending_update_count` — التحديثات المرتبة في Telegram لأن نقطة النهاية لديك فاشلة/بطيئة. النمو المستمر = حادثة.
- `last_error_message` / `last_error_date` — تفشل التسليم بالضبط (انتهاء المهل، أخطاء TLS، رمز سري خاطئ).

قائمة تحقق للنشر في وضع الويب هوك:

1. `webhook.php` قابل للوصول عبر HTTPS (اختبر بمتصفح: طلب GET فارغ يجب أن يعطي `400 empty` — هذه إجابة الموزّع).
2. `BOT_WEBHOOK_SECRET` بطول 32 حرفًا عشوائيًا أو أكثر، متطابق في `.env` و`setWebhook`.
3. `WEBHOOK_CHECK_IP` يطابق بنية شبكتك (انظر قسم الوكلاء).
4. `getWebhookInfo` نظيف بعد رسالة تجريبية؛ والأثر الجانبي لإضافتك ظاهر.

</div>
