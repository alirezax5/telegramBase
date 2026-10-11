<div dir="rtl">

# خط أنابيب قائمة الانتظار — عندما لا تكفي عملية واحدة

## لماذا قائمة انتظار أصلًا؟

المعالجة المباشرة لها ضعف بسيط: العملية التي *تستقبل* التحديث يجب أيضًا أن *تُنهي* معالجته. إذا حمّلت إحدى المعالجات فيديو بحجم 50 MB أو انتظرت واجهة API خارجية بطيئة، انحبست العملية المستقبِلة (وفي وضع الويب هوك، يكون Telegram منتظرًا 200 الخاصة به). يقسّم خط أنابيب قائمة الانتظار العمل:

```
producer                          broker                    consumers (N processes)
──────                            ──────                    ────────
queue.php / webhook.php  ──push──► Redis / RabbitMQ ──pop──► bot.php workers
(pick up updates fast,            (holds updates while       (do the slow work,
 advance offset / 200)             nobody is around)          ack after processing)
```

الفوائد: استلام سريع (لا ينتظر Telegram أبدًا معالجاتك)، وتوسّع أفقي (شغّل عمالًا أكثر)، وعزل الانهيارات (العامل المحتضر لا يؤثر على المستقبل).

## اختيار المُشغِّل

| المُشغِّل | `QUEUE_SAVE_TYPE` | الاحتياجات | سلوك السحب | الضمانة عند انهيار العامل |
|--------|-------------------|-------|---------------|---------------------------|
| JSON | `json` *(الافتراضي)* | لا شيء | قراءة ملف مُدمِّر | **at-most-once** — تُفقد المهمة إذا حدث انهيار أثناء المعالجة |
| Redis | `redis` | امتداد `redis` + خادم | `LPOP` (مُدمِّر) | **at-most-once** — تُفقد المهمة إذا حدث انهيار أثناء المعالجة |
| Memcached | `memcached` | امتداد `memcached` + خادم | get/delete مُدمِّر | **at-most-once** |
| RabbitMQ | `rabbitmq` | امتداد `amqp` + خادم | `basic_get` + **إقرار بعد المعالجة** (v2.4.0) | **at-least-once** — تُعاد تسليم المهمة غير المُقرَّ بها |

اختر RabbitMQ عندما يكون فقدان تحديث واحد غير مقبول؛ واختر Redis/JSON من أجل معدل النقل والبساطة عندما يكون الفقد العرضي محتملًا (أو عندما تكون إعادة بناء التحديثات زهيدة).

## عقد الإقرار في RabbitMQ (v2.4.0)

منذ v2.4.0 يستخدم مُشغِّل RabbitMQ دلالات **الإقرار المتأخر (ack-late)**:

1. تجلب `pop()` رسالة وتحتفظ بوسم تسليمها — الرسالة *ليست* مُقرَّ بها بعد.
2. تعمل معالجتك.
3. يستدعي العامل `ack()` **بعد** المعالجة. عندها فقط يحذف الوسيط الرسالة.
4. إذا مات العامل في أي نقطة بين 1 و3، يعيد الوسيط تسليم الرسالة إلى العامل التالي.

على مستوى الكود، يفعل العامل هذا بالضبط:

```php
$update = $queue->pop();
try {
    $processor->handle($update);
} finally {
    $queue->ack();   // success → done; handled exception → discarded (no poison loops)
}
```

نتائج يستحق أن تعرفها:

- **تكرار المعالجة ممكن** (انهيار بعد المعالجة وقبل الإقرار — لا يستطيع الوسيط التمييز). اجعل معالجاتك قابلة لإعادة التنفيذ بأمان: خزّن `update_id` قبل التصرف، وتجاوز إن كان مرئيًا سابقًا. انظر [reliability.md](reliability.md).
- الرسالة التي يتعذّر فك ترميزها تُقرَّب فورًا (تُهمَل) كي لا تسمّم قائمة الانتظار.
- مُشغِّلات قوائم الانتظار المخصصة لا تحتاج إلى تطبيق `ack()` — فالتابع `QueueManager::ack()` لا يعمل شيئًا (no-op) مع المُشغِّلات التي لا تملك إقرارًا من جانب الوسيط.

تسكن الإعدادات تحت القسم `rabbitmq` في `.env` (المضيف، المنفذ، المستخدم، كلمة المرور، vhost، اسم القائمة) — انظر [.env.example](../../.env.example).

## تشغيل خط الأنابيب

`update_queue` (منتج بالاستطلاع):

```bash
# terminal 1 — fetcher: long-polls Telegram, pushes to the broker
php queue.php

# terminal 2..N — consumers: pop + process + ack
php bot.php
```

`webhook_queue` (منتج بالويب هوك):

```bash
# web server receives Telegram POSTs → validates → enqueues → 200
# (webhook.php handles this automatically when BOT_MODE=webhook_queue)

# terminal 1..N — consumers
php bot.php
```

شغّلها كلها تحت مشرف عمليات. يعيد العمال الاتصال بالوسيط تلقائيًا بتراجع أُسّي (`BACKOFF_INITIAL=1s` … `BACKOFF_MAX=60s`)، فتستطيع إعادة تشغيل Redis/RabbitMQ دون لمس العمال.

## قوائم انتظار محدودة

تُسقّف `QUEUE_MAX_SIZE` (الافتراضي في [.env.example](../../.env.example)) عدد التحديثات التي يجوز أن تتراكم في الوسيط. عند بلوغ الحد، تُرفض عمليات الدفع الجديدة **وتُسجَّل** (WARNING)، ولا تُهمَل أبدًا بصمت في كومة غير محدودة. هذا يحمي القرص/الذاكرة عندما يكون العمال متوقفين لفترة طويلة. القيمة 0 تُلغي السقف.

## قائمة إعادة المحاولة (منفصلة عن القائمة الرئيسية)

تتولى قائمة إعادة المحاولة *الإخفاقات العابرة للمعالجات* (HTTP ‏`429`، و`5xx`، وانتهاء المهل، وأخطاء curl) لكل الأوضاع، وليس الأوضاع الموجّهة إلى قائمة الانتظار فقط:

- تلتقط `Processor::handle()` خطأً قابلًا لإعادة المحاولة ← تدفع التحديث إلى `RetryQueue`.
- يستنزف العمال طويلو الأمد العناصر المستحقة في نبضات صيانتهم (كل تكرار لحلقة في عمال قائمة الانتظار).
- يتضاعف التراجع مع كل محاولة؛ وعندما يزوّد Telegram قيمة `retry_after`، تُحترم تلك القيمة بالضبط (`RetryableException::retryAfter`).
- بعد `RETRY_MAX_ATTEMPTS` يُهمَل العنصر ويُسجَّل ذلك.

تفعّل معالجاتك هذا السلوك عبر الدالة المساعدة `tg_retryable()`:

```php
use function alirezax5\TelegramBase\App\Queue\tg_retryable;

$resp = tg_retryable($tg->sendMessage($chatId, 'Hello!'), 'sendMessage');
// ok=true            → returned unchanged
// 429 / 5xx          → RetryableException thrown → retry queue
// other 4xx          → returned unchanged (permanent errors are not retried)
```

الإعدادات: `RETRY_ENABLED`، و`RETRY_QUEUE_DRIVER` (`json`|`redis`)، و`RETRY_MAX_ATTEMPTS`، و`RETRY_BASE_DELAY`، و`RETRY_MAX_SIZE` — القيم الافتراضية في [.env.example](../../.env.example).

</div>
