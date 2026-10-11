<div dir="rtl">

# الجلسات — SessionManager

حالة لكل محادثة تنجو بين الرسائل: معالجات نماذج متعددة الخطوات، و«اضغط 1 من أجل...»، وسلال الشراء، ومفاتيح الأوضاع لكل مستخدم. تُخزَّن الجلسات عبر [CacheManager](caching.md)، مُفتّحة بمعرف المحادثة، ومع مهلة TTL تنتهي بها المحادثات الخاملة تلقائيًا.

```php
use alirezax5\TelegramBase\App\Session\SessionManager;
```

## واجهة الـ API

```php
// Start (or resume) a session — creates one when none exists
$session = SessionManager::start($chatId);

$session->set('step', 'waiting_email');        // persists immediately
$session->setMany(['cart' => ['item1'], 'lang' => 'fa']);
$step = $session->get('step');                  // null when unset
$step = $session->get('step', 'default');       // with fallback
$session->has('step');                          // true even when value is null
$session->all();                                // whole payload as array
$session->forget('cart');                       // remove one key
$session->regenerate();                         // new random session id (after login!)
$session->touch();                              // refresh last-activity
$session->destroy();                            // delete entirely

// Read WITHOUT creating (null when absent — use in read-only paths)
$existing = SessionManager::get($chatId);
SessionManager::has($chatId);
SessionManager::flush($chatId);                 // delete
```

تحت الغطاء، الحمولة كلها مدخلة كاش واحدة:

| مفتاح الكاش | `SESSION_PREFIX` (الافتراضي `tgbase:session:`) + معرف المحادثة |
|---|---|
| TTL | `SESSION_TTL` (الافتراضي 3600 ثانية، الحد الأدنى 60 ثانية) — **كل `set()` يعيد التخزين ويصفّر النافذة** |
| الحمولة | `['id' => sessionId, 'data' => […], 'last_activity' => ts]` |

## تدفق متعدد الخطوات

```php
public function onMessage($message, Telegram $tg): void
{
    $text = trim($message->text ?? '');
    $chatId = $tg->chatId();

    $session = SessionManager::start($chatId);

    switch ($session->get('step')) {
        case null:
            if ($text === '/register') {
                $session->set('step', 'ask_email');
                $resp = $tg->sendMessage($chatId, 'ایمیلت را بفرست:');
                tg_retryable($resp, 'sendMessage');
            }
            return;

        case 'ask_email':
            if (!filter_var($text, FILTER_VALIDATE_EMAIL)) {
                $resp = $tg->sendMessage($chatId, 'ایمیل معتبر نیست — دوباره بفرست:');
                tg_retryable($resp, 'sendMessage');
                return;                              // stay on the same step
            }
            $session->setMany(['email' => $text, 'step' => 'done']);
            $resp = $tg->sendMessage($chatId, "ثبت شد: {$text}");
            tg_retryable($resp, 'sendMessage');
            return;
    }
}
```

يملك كل مستخدم آلة حالات مستقلة لأن الجلسة مُفتّحة بمعرف المحادثة.

## أي مُشغِّل كاش أحتاج؟

- **شيطان الاستطلاع (جالب `update_direct` / `update_queue`):** عملية PHP واحدة طويلة العمر — حتى `array` يُبقي الحالة حية بين الرسائل. لكنها تموت عند إعادة التشغيل.
- **الويب هوك (`webhook_*`) وعمال قائمة الانتظار:** يجب أن تعبر الحالة حدود العمليات ← `file` أو `redis` أو `memcached`.

تحقّق مباشرة: جلسة كُتبت في العملية A (بمعرف `sid d5dfbf11…`، مع خطوة وسلة) قُرئت مجددًا في عملية B جديدة على `file` و`redis` و`memcached` — **معرف الجلسة نفسه، والحمولة مطابقة**؛ وعلى `array` لم يرَ في العملية B شيئًا (كما هو موثّق).

## ملاحظات أمنية

- نفّذ `regenerate()` بعد أي تغيير في الامتيازات (تسجيل الدخول) لمنع تثبيت الجلسة — المعرف عشوائي جديد من 32 حرفًا سادس عشريًا.
- لا تحمل الجلسات هوية من نفسها: مفتاحها هو معرف المحادثة. في المحادثات الجماعية يتشارك كل الأعضاء جلسة المحادثة — أما حالة كل مستخدم في المجموعات فافتح بها عبر `$tg->fromId()` في مفاتيح الكاش الخاصة بك بدلًا من ذلك.
- تنتهي الجلسات طويلة الخمول بصمت (`SESSION_TTL`)؛ صمّم تدفقاتك لتتسامح مع جلسة مفقودة (`SessionManager::get() === null` ← ابدأ من جديد).

## أوامر الاختبار (integration/TestBot)

‏`/session` يعرض المعرف + المفاتيح + البيانات · `/session-set <k> <v>` · `/session-get <k>` · `/session-clear`. نُفِّذ ثنائي set/get عبر خط أنابيب الإضافات الحقيقي في عمليتين منفصلتين ضمن التحقق P-CACHE.

</div>
