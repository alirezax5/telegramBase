<div dir="rtl">

# الإضافات — كتابة منطق بوتك

الإضافة هي صنف PHP واحد داخل `Plugin/` يطبّق `PluginInterface`. لا يوجد تسجيل، ولا ملف إعدادات، ولا مزوّد خدمات — ضع الملف في مكانه وسيكتشفه الإطار عند الإقلاع التالي.

## التشريح

```php
<?php
namespace alirezax5\TelegramBase\Plugin;

use alirezax5\TelegramBase\App\Attributes\ChatType;
use alirezax5\TelegramBase\App\Plugin\Contract\PluginInterface;
use telegramBotApiPhp\Telegram;

#[ChatType(['private'])]
class orderStatus implements PluginInterface
{
    // Lower runs first. Default 10. Use 1 for "always handle first".
    public function getPriority(): int
    {
        return 10;
    }

    // Called for every message in an allowed chat type.
    public function onMessage($message, Telegram $Telegram): void
    {
        $chatId = $Telegram->fromId();          // sender's user id
        $text   = trim($message->text ?? '');

        if ($text === '/status') {
            $Telegram->sendMessage($chatId, 'All systems nominal ✅');
        }
    }

    // Called when an inline button attached to one of your messages is pressed.
    public function onCallback_query($callbackQuery, Telegram $Telegram): void
    {
        // always answer, or the sender's client shows a loading spinner:
        $Telegram->answerCallbackQuery($callbackQuery->id);
    }
}
```

ملاحظات:

- `$message` هو **كائن الرسالة** (وليس التحديث الكامل) — `$message->text`، و`$message->chat->id`، و`$message->photo`، وهكذا.
- كائن التحديث الكامل (مع `update_id`) متاح عبر `$Telegram->getInputData()`.
- الإضافة التي لا تحمل سمة `#[ChatType]` تعمل مع كل أنواع المحادثات.

## دوال المعالجة

| الدالة | متى تُستدعى |
|--------|----------------|
| `onMessage` | أي رسالة (نص، صورة، مستند، ...) |
| `onCallback_query` | عند الضغط على زر لوحة مفاتيح مضمنة |
| `onCommand` | رسالة `/slash_command` |
| `before` | قبل كل تحديث (من أي نوع) |
| `after` | بعد كل تحديث |

طبّق فقط دوال المعالجة التي تحتاجها.

## التصفية حسب نوع المحادثة

```php
#[ChatType(['private'])]                   // private chats only
#[ChatType(['group', 'supergroup'])]       // groups only
#[ChatType(['channel'])]                   // channel posts
```

تُترجم السمة إلى أنواع محادثات Telegram، ويفصلها الـ SDK (`$Telegram->chatType()`).

## الترتيب والقطع المبكر

تعمل الإضافات بترتيب الأولوية لكل دالة معالجة. يستطيع أي إضافة إيقاف السلسلة بمجرد *عدم* استدعاء المزيد من المنطق — لكن الأداة الأنظف لقواعد «الرفض» العابرة للأصناف (الحظر، حدود المعدل) هي **الوسيط البرمجي**، الذي يعمل قبل توزيع الإضافات لكل تحديث. انظر [features.md](features.md).

## قواعد الاكتشاف

- المجلد: `PLUGINS_DIR` في `.env` (الافتراضي `/Plugin/` نسبةً إلى جذر المشروع؛ المسارات المطلقة مدعومة).
- تُمسح الملفات بشكل تكراري؛ ويجب أن يطابق اسم الصنف اسم الملف على طريقة PSR-4 (`Plugin/Admin/Ban.php` ← الصنف `Ban` في الـ namespace ‏`…\Plugin\Admin`).
- بيانات الإضافات الوصفية (المجموعة المكتشفة) تُخزَّن في الكاش؛ وأثناء التطوير اضبط `PLUGIN_CACHE_ENABLED=false` أو استخدم `PLUGINS_RELOAD_INTERVAL` لالتقاط الملفات الجديدة دون تفريغ الكاش يدويًا.
- تحقّق مما يراه الإطار: `php bin/tgbase plugins` — يعرض الأولوية وأنواع المحادثات ودوال المعالجة المسجلة لكل إضافة.

## مثال كامل

يستعرض [examples/HelloBot.php](../../examples/HelloBot.php) الأنماط الشائعة في ملف واحد:

- توجيه الأمرين `/hello` و`/status`
- بناء لوحة مفاتيح مضمنة عبر الباني السلس `Keyboard`
- حالة لكل محادثة باستخدام `SessionManager`
- استدعاءات API آمنة عبر `tg_retryable()` (تُلقي `RetryableException` عند 429/5xx ← قائمة إعادة محاولة تلقائية)
- الإجابة عن استعلامات الـ callback والتصرف بناءً عليها

لتجربته: انسخ الملف إلى `Plugin/`، وغيّر الـ namespace إلى `alirezax5\TelegramBase\Plugin`، ثم أعد تشغيل البوت.

## توليد الهياكل

```bash
php bin/tgbase make:plugin users/register --type=private
php bin/tgbase make:plugin admin/ban      --type=group
```

تتحول مقاطع المسار إلى namespaces على طريقة PSR-4 (`users/register` ← `Plugin\Users\Register`).

</div>
