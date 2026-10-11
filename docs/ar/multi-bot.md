<div dir="rtl">

# تعدد البوتات — تشغيل بوتين في عملية واحدة

يدعم TelegramBase تشغيل تعدد البوتات على مستويين: توفر
[مكتبة `telegramBotPhp`](https://github.com/alirezax5/telegramBotPhp)
الأدوات الأساسية (نسخ مستقلة، ومجموعة رموز، واستدعاءات خام)، بينما يضيف الإطار
سجل **`BotManager`** كي تصل الإضافات إلى البوتات الثانوية بالاسم.

جرى التحقق من كلا السيناريوهين المطلوبين من المالك مباشرةً (2026-10-11) مع بوتين حقيقيين:

| السيناريو | ما الذي يحدث | النتيجة المُتحقَّقة |
|---|---|---|
| **أ — فحص العضوية عبر البوت رقم 2** | يسأل المستخدم البوت رقم 1؛ ينفّذ البوت رقم 1 ‏`getChatMember()` **برمز البوت رقم 2** | `ok=true`، ‏`status=member`، 13 مللي ثانية |
| **ب — إرسال مفوَّض** | يسأل المستخدم البوت رقم 1؛ يجعل البوت رقم 1 **البوت رقم 2 يرسل** الرسالة، داخل عملية البوت رقم 1 | سُلِّمت، 239 مللي ثانية |

---

## تسجيل بوتات إضافية: `BOT_EXTRA_BOTS`

أضف متغير البيئة قبل الإقلاع (في `.env` أو بيئة العملية):

```dotenv
BOT_EXTRA_BOTS="second:398172019:AAxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
```

قواعد التنسيق:

- المدخلات **مفصولة بفواصل**؛ وكل مدخلة بصيغة `name:token`؛
- يُفصل عند **أول** نقطتين فقط (رمز البوت نفسه يحتوي نقطتين)؛
- الاسم `main` محجوز للبوت الأساسي ويُتجاهَل في هذه القائمة؛
- المدخلات المشوّهة تُسجَّل (تحذير) وتُتخطَّى — لا يفشل الإقلاع أبدًا.

‏`BotManager` نمط أحادي يُنشأ عند أول استخدام؛ ويُبنى البوت الافتراضي (`main`)
من `Config::bot()` (أي `BOT_TOKEN`)، ثم تُسجَّل البوتات الإضافية.

## استخدام البوتات من إضافة

```php
use alirezax5\TelegramBase\App\Bot\BotManager;
use alirezax5\TelegramBase\App\Plugin\Contract\PluginInterface;
use telegramBotApiPhp\Telegram;

class MultiBotPlugin implements PluginInterface
{
    public function getPriority(): int { return 1; }

    public function onMessage($message, Telegram $tg): void
    {
        if ($message->text !== '/inchannel') {
            return;
        }

        $bm  = BotManager::getInstance();
        $userId = $tg->fromId();
        $chatId = $tg->chatId();

        // Scenario A — membership check executed by bot #2's identity
        if ($bm->has('second')) {
            $member = $bm->get('second')->getChatMember('@mychannel', $userId);
            $status = $member->result->status ?? 'left';   // member|administrator|creator|left|kicked
            $tg->sendMessage($chatId, "وضعیت عضویت شما (چک توسط ربات ۲): {$status}");
        } else {
            $tg->sendMessage($chatId, 'ربات دوم ثبت نشده — BOT_EXTRA_BOTS را تنظیم کنید');
        }

        // Scenario B — bot #1 sends AS bot #2 (token stack on the main instance)
        if ($bm->has('second')) {
            $tg->switchToken($bm->get('second')->getToken());
            try {
                $tg->sendMessage($chatId, 'این پیام با هویت ربات ۲ ارسال شد (داخل پروسه ربات ۱)');
            } finally {
                $tg->restoreToken();
            }
        }
    }
}
```

## واجهة API لـ `BotManager`

| الدالة | السلوك |
|---|---|
| `getInstance(): BotManager` | أداة الوصول للنمط الأحادي |
| `get(?string $name): Telegram` | يعيد البوت المسماى؛ **الأسماء المجهولة تعود إلى `main`** (مع تسجيل تحذير) |
| `has(string $name): bool` | هل الاسم مسجل؟ — **تحقق دائمًا قبل `get()` لأي بوت غير افتراضي** |
| `add(string $name, Telegram $bot): void` | تسجيل/الكتابة فوق بوت في وقت التشغيل (الكتابة فوق تسجّل تحذيرًا) |
| `names(): string[]` | كل الأسماء المسجلة (‏`main` أولًا) |
| `setDefaultToken(string $token): Telegram` | يتجاوز رمز البوت **الرئيسي** عالميًا — يؤثر على كل مستدعٍ |

## لماذا يهم `has()`

لا يرمي `get('typo-name')` استثناءً — بل يعيد بصمت البوت **الرئيسي**. عندها سينفَّذ
`getChatMember()` المخصص للبوت رقم 2 بهوية البوت رقم 1 ويعيد
`chat member not found` أو أسطرًا، إجابةً خاطئة لكنها تبدو معقولة. احرس كل وصول
غير افتراضي بـ `has()`.

## إدارة التوكنات: عدة روبوتات على ملف واحد

يمكن لنشر واحد (ملف دخول واحد + ملف `.env` واحد) أن يخدم أي عدد من هويات
البوتات. تُدعَم ثلاث استراتيجيات — مرتّبة حسب التوصية:

| # | الاستراتيجية | أين تُخزَّن الرموز | الأنسب لـ | تم التحقق |
|---|---|---|---|---|
| 1 | **سجل ثابت** — ‏`BOT_TOKEN` + `BOT_EXTRA_BOTS` في `.env` | ملف `.env` واحد، أزواج `name:token` | مجموعة بوتات ثابتة تُنشر معًا | 23/23 فحصًا (parse / identity / degrade / scale) |
| 2 | **سجل ديناميكي** — ‏`BotManager::add($name, new Telegram($token))` في وقت التشغيل | لوحة قاعدة البيانات/الإعدادات لديك؛ تُمرَّر إلى العملية | بوتات تُوفَّر عبر لوحة إدارة، وبوتات لكل مستأجر | فحوصات الهوية + الإرسال المفوَّض |
| 3 | **الروبوتات المُدارة (Managed Bots)** — رموز أبناء تُجلب مباشرةً من Telegram (القسم التالي) | **لا شيء على القرص** | بوت مدير يُنشئ بوتات أبناء نيابةً عن المستخدمين | مجموعة TM-2، 8/8 |

قواعد تُثبتها مجموعة الاختبارات المباشرة (2026-10-11، بوتان حقيقيان + مدخلات اصطناعية):

- مدخلات `BOT_EXTRA_BOTS` المشوّهة تُسجَّل وتُتخطَّى — **لا يفشل الإقلاع أبدًا**؛
- الرمز الميت/المُلغى يفشل فشلًا نظيفًا (`ok=false`، `Unauthorized`) ولا يُفسد
  البوتات الأخرى أبدًا: يفشل `get('dead')` بينما يبقى `get('alive')` و`main` سليمين؛
- الاسم `main` محجوز؛ الأسماء المكررة تُحلّ لصالح الاسم الأخير مع تحذير؛
- نقطتا الرمز نفسه تنجوان من فصل `name:token` (عند أول نقطتين فقط)؛
- عزل الهوية: يطابق `getMe()` لكل نسخة مسجّلة بادئة معرّف رمزها؛ ومجموعة الرموز
  حالة خاصة بكل نسخة (التبديل/الاستعادة على `main` يترك `second` دون مساس)؛
- عمليات البحث في السجل هي O(1): عشرة آلاف استدعاء `get()` مع 32 بوتًا مسجّلًا
  في نحو 1 مللي ثانية.

ملاحظة الويب هوك: في أوضاع `webhook_*` يُعالَج كل تحديث يصل إلى `webhook.php`
تحت هوية البوت **الرئيسي** (سر `BOT_WEBHOOK_SECRET` مشترك واحد). لخدمة عدة
بوتات عبر الويب هوك، **لا** تحتاج إلى مجلد واحد لكل بوت — فنمط `secret_token`
في القسم التالي يشغّل **أي عدد من البوتات من ملف ويب هوك واحد**، وقد أُثبت ذلك
في التشغيل المباشر.

## ملف ويب هوك واحد، وبوتات كثيرة — نمط `secret_token`

لست بحاجة إلى مجلد لكل بوت. **ملف ويب هوك واحد يستطيع خدمة أي عدد من
البوتات.** الحيلة في معامل `secret_token` الخاص بـ `setWebhook`:

1. اختر **سرًّا عشوائيًا مختلفًا لكل بوت** (تُسمح فقط بالحروف والأرقام و`_` و`-`).
2. سجِّل **عنوان الويب هوك نفسه** لكل بوت، وكل بوت بسرّه الخاص:
   `setWebhook?url=https://example.com/hook.php&secret_token=<this-bots-secret>`
3. في كل تحديث يعيد Telegram هذا السر في الترويسة
   `X-Telegram-Bot-Api-Secret-Token`.
4. يقرأ ملفك الترويسة، ويبحث عن السر في سجل صغير
   (`secret → bot token`)، ثم يعالج التحديث تحت هوية ذلك البوت.

```text
             ┌─ bot A: setWebhook(url=hook.php, secret=SECRET_A)
 Telegram ───┤
             └─ bot B: setWebhook(url=hook.php, secret=SECRET_B)
                         │   POST + header X-Telegram-Bot-Api-Secret-Token
                         ▼
               ┌───────────────────┐   SECRET_A → token A → act as bot A
               │     hook.php      │
               │  registry:        │
               │  secret → token   │   SECRET_B → token B → act as bot B
               └───────────────────┘
```

لماذا هذه هي الطريقة الموصى بها لتشغيل مزرعة بوتات:

- **رمز البوت لا يظهر في أي عنوان URL أبدًا** — فلا يمكن أن يتسرب إلى سجلات
  الوصول أو سجل المتصفح أو البروكسي؛
- السر يشتغل في الوقت نفسه **كفحص أصالة** — أي طلب لا يحمل السر الصحيح ليس من
  Telegram؛ أجب بـ `403` وتجاهله؛
- **إضافة البوت رقم N+1 تكلف استدعاء `setWebhook` واحدًا وسطرًا واحدًا في
  السجل** — بلا ملفات جديدة وبلا نشر جديد.

إثبات مباشر (2026-10-11، بوتان حقيقيان، ملف واحد، حركة مرور مستخدمين حقيقية):

| الحدث | الهوية الموجَّه إليها | النتيجة |
|---|---|---|
| `/start` حقيقي على البوت ب | @instaroonxbot | رُدّ عليه، `ok:true` |
| ضغطة حقيقية على الزر inline للبوت ب (×2) | @instaroonxbot | `answerCallbackQuery` + رد، `ok:true` |
| ضغطة حقيقية على الزر inline للبوت أ | @emojiCustom_bot | `answerCallbackQuery` + رد، `ok:true` |
| POST اصطناعي بالسر أ / بالسر ب | البوت الصحيح في كل مرة | رُدّ عليه، `ok:true` |

7/7 عمليات تسليم — كل تحديث وصل إلى الملف نفسه، وكل رد حمل هوية البوت
الصحيحة. لم يظهر رمز البوت في أي عنوان URL أو ترويسة قط.

قواعد التحصين للإنتاج:

- السر **هو مفتاح التوجيه** — يجب أن يكون فريدًا لكل بوت؛
- ولِّده عشوائيًا، 32+ محرفًا من `[A-Za-z0-9_-]` (يرفض Telegram المحارف الأخرى)؛
  السر المكون من محرفين هو عمليًا لا سر؛
- ارفض الأسرار المجهولة أو الغائبة بـ `403` قبل لمس أي شيء آخر؛
- لا تسجِّل الرموز قط؛ سجِّل على الأكثر بادئة قصيرة من السر؛
- تدوير السر = إعادة تشغيل `setWebhook` لذلك البوت (يستغرق ثوانٍ).

## الروبوتات المُدارة (Managed Bots — Bot API 9.6)

أضاف Telegram Bot API 9.6 (2026-04-03) ميزة **الروبوتات المُدارة
(Managed Bots)**: البوت الذي يملك `can_manage_bots` (يُفعَّل في Mini App
الخاص بـ @BotFather) يمكن أن تُنشأ تحته بوتات أخرى وتُدار. لا حاجة أبدًا
لتخزين رمز البوت الابن — يجلب البوت المدير الرمز مباشرةً عبر
`getManagedBotToken(user_id)`.

دعم الإطار (v2.4.x):

| عنصر الـ API (Bot API 9.6) | واجهة الإطار |
|---|---|
| ‏`User.can_manage_bots` (getMe) | ‏`$tg->callRaw('getMe')->result->can_manage_bots` |
| تحديث `managed_bot` (‏`ManagedBotUpdated`) | يُرسَل إلى `onManagedBot($managedBot, $tg)` — أُضيف إلى القائمة البيضاء للإضافات |
| ‏`KeyboardButtonRequestManagedBot` | ‏`Keyboard::reply()->requestManagedBot($text, $requestId, $suggestedName, $suggestedUsername)` |
| ‏`getManagedBotToken` / `replaceManagedBotToken` / `get·setManagedBotAccessSettings` | ‏`BotManager::registerManagedBot()` / `refreshManagedBot()` / `managedBotAccessSettings()` |
| حقل الرسالة `ManagedBotCreated` | مرئي في الحمولة داخل `onMessage` |

التدفق من البداية إلى النهاية (مسودة إضافة):

```php
use alirezax5\TelegramBase\App\Bot\BotManager;
use alirezax5\TelegramBase\App\Button\Keyboard;

// 1) offer creation (private chats only)
$tg->sendMessage($chatId, 'Create your own bot:', [
    'reply_markup' => Keyboard::reply()
        ->requestManagedBot('➕ Create my bot', 1, 'MyHelperBot', 'my_helper_bot')
        ->toArray(),
]);

// 2) the user completes the flow in Telegram → managed_bot update arrives
public function onManagedBot($managedBot, $tg): void
{
    // fetches the child token live (getManagedBotToken) and registers it
    BotManager::getInstance()->registerManagedBotFromUpdate($managedBot);
}

// 3) use the child bot — registered under its bot id
BotManager::getInstance()->get((string) $managedBot->bot->id)
    ->sendMessage($chatId, 'hello from the child bot');
```

المتطلبات المسبقة (إجراءات يتخذها المالك، لمرة واحدة):

1. فعِّل **إدارة البوتات الأخرى** للبوت المدير في Mini App الخاص بـ
   @BotFather — وحتى ذلك الحين يُبلغ `getMe` عن `can_manage_bots: false`
   ويفشل طلب الإنشاء.
2. إنشاء البوت الابن نفسه يحدث دائمًا على جانب Telegram (بموافقة المستخدم)؛
   لا يتلقى الإطار سوى تحديث `managed_bot` ويجلب الرمز.

نظافة الرموز: الرموز المُدارة تبقى في الذاكرة فقط (في السجل) — لا يُكتب أي
شيء إلى `.env` أو السجلات. تدوير رمز الابن عبر
`BotManager::refreshManagedBot($userId, $name)` يُلغي الرمز القديم فورًا
ويعيد تسجيل الرمز الجديد.

### الروبوتات المُدارة — تشغيل مباشر من البداية إلى النهاية (2026-10-11)

بعد أن فعّل المالك **إدارة البوتات الأخرى** في Mini App الخاص بـ @BotFather،
انقلب `getMe` إلى `can_manage_bots: true` على كلا البوتين التجريبيين، ونُفِّذ
التدفق الكامل مباشرةً:

- زر الإنشاء الذي بُني عبر `Keyboard::reply()->requestManagedBot(...)` أُرسل في
  محادثة خاصة — وقَبِل Telegram بشكل لوحة المفاتيح الذي ينتجه الإطار؛
- تفصيلة جديرة بالمعرفة في الحقول: في `KeyboardButtonRequestManagedBot` يجب أن
  يكون `request_id` **رقمًا (Number)** (بخلاف `request_chat` / `request_users`
  حيث يكون سلسلة نصية — يرفض Telegram السلسلة مع `Bad Request: can't parse KeyboardButton: Field "request_id" must be a valid Number`)؛
- وبمجرد أن يُكمل المستخدم نموذج الإنشاء، يستقبل البوت المدير تحديث
  `managed_bot` ويجلب رمز الابن مباشرةً عبر `getManagedBotToken(user_id)` —
  ثم جعل العرض التجريبي **البوت الابن يتحدث في محادثة المالك** برمزه المجلوب
  حديثًا (صفر على القرص).

تفعيل مفاتيح Mini App نفسها قلب أيضًا، على كلا البوتين التجريبيين:
`supports_guest_queries`، و`has_topics_enabled`،
`supports_join_request_queries`، و`can_connect_to_business` → كلها `true`.

- الشكل الحقيقي للتحديث (التُقط في 2026-10-11): ‏`managed_bot.user` = الشخص الذي أنشأ البوت الابن (مستخدم عادي)، و`managed_bot.bot` = البوت الابن نفسه (`{id, is_bot: true, username, ...}`). مرّر `managed_bot.bot.id` إلى `getManagedBotToken` — فمعرّف المنشئ يُرجع `Bad Request: user is not a bot`. في العرض التجريبي تم جلب رمز البوت الابن مباشرةً وأرسل الابن رسالة حقيقية إلى منشئه — صفر رموز على القرص.

### أين تُحفظ الرموز والأسرار

| مكان التخزين | متى يناسب | انتبه إلى |
|---|---|---|
| **`.env`** (`BOT_TOKEN` + `BOT_EXTRA_BOTS`) | مجموعة صغيرة وثابتة من البوتات | للقراءة بامتياز الجذر (root) فقط؛ التغييرات تتطلب إعادة تشغيل |
| **جدول سجل في قاعدة البيانات** (`bots`: id, name, token, secret, status) | بوتات تُضاف/تُحذف في وقت التشغيل، لوحات الإدارة | شفِّر عمود الرمز في حالة السكون (at rest) (باستخدام sodium مثلًا)؛ خبِّئ السجل حتى لا يقابل كل تحديث استعلام قاعدة بيانات |
| **الروبوتات المُدارة (صفر على القرص)** | بوت مدير يُنشئ أبناءً نيابةً عن المستخدمين | يتطلب `can_manage_bots`؛ يُجلب الرمز مباشرةً عبر `getManagedBotToken` |
| **أسرار مشتقة (HMAC)** — لا يُخزَّن شيء | تتحكم بمفتاح رئيسي واحد | `secret = base64url(hmac_sha256(master_key, 'bot:' . bot_id))`؛ تدوير المفتاح الرئيسي يعني إعادة تسجيل كل ويب هوك |

التوصية لمزرعة بوتات متنامية: **سجل في قاعدة البيانات (الرمز مشفَّر في حالة
السكون) + بحث مخبَّأ + توجيه `secret_token`**، و**الروبوتات المُدارة** للأبناء
الذين ينشئهم المستخدمون. أبقِ `.env` للبوت المدير الأول فقط.

### إبقاء بيانات كل بوت منفصلة

عندما تتشارك بوتات كثيرة نشرًا واحدًا، يجب أن تبقى بيانات كل بوت حصرية له.
ضع بادئة مساحة أسماء **بمعرّف البوت في كل طبقة تخزين** (`b{bot_id}`):

| الطبقة | القاعدة |
|---|---|
| ذاكرة التخزين المؤقت | بادئة المفتاح `b{bot_id}:` (اضبط `CACHE_PREFIX` لكل بوت عند الإقلاع) |
| الجلسات | النطاق حسب **معرّف البوت + معرّف المستخدم** — الشخص نفسه وهو يتحدث إلى بوتين هو جلستان مختلفتان |
| إزاحة getUpdates | **ملف/مفتاح إزاحة واحد لكل بوت** — إزاحة مشتركة ستُغذي تحديثات بوتٍ إلى بوتٍ آخر |
| قائمة الانتظار | اسم قائمة انتظار لكل بوت (`tgbase_b{bot_id}_updates`) أو وسم الحمولات بـ `bot_id` |
| تخزين الملفات | `data/bots/{bot_id}/…` |
| ملفات السجل | ملف سجل واحد لكل بوت (`logs/bot_{id}.log`) |
| قاعدة البيانات | الجداول المشتركة تحصل على عمود `bot_id`؛ العزل الحقيقي = قاعدة بيانات منفصلة لكل بوت |

يحدّد وحدة التحكم الأمامية للويب هوك `secret → bot` أولًا، ثم يُقلع الإطار
برمز ذلك البوت ومساحة أسمائه — فتبقى كل عمليات القراءة/الكتابة اللاحقة
تلقائيًا داخل الصندوق الرملي الخاص بذلك البوت.

## دلالات التسليم

تركب استدعاءات تعدد البوتات على عميل HTTP نفسه الذي تستخدمه كل استدعاءات الـ API الأخرى:

- تنطبق إعادات المحاولة/التراجع ومعالجة `retry_after` على كل نسخة على حدة؛
- مجموعة الرموز **حالة لكل نسخة** — في عمال قائمة الانتظار يعالج كل تحديث
  بتوزيع جديد، فتعيش أزواج switch/restore داخل عمر تحديث واحد؛ ولا تعتمد أبدًا
  على حالة المجموعة عبر التحديثات؛
- في أوضاع `webhook_*` تنطبق القواعد نفسها داخل طلب FPM الواحد.

## حاضنة التحقق المباشر

‏`integration/TestBot/Plugin/MultiBotTestPlugin.php` (حاضنة اختبار، محادثات خاصة):

- `/multibot` — المجموعة الكاملة: الهوية، والعزل، والإرسال العابر (السيناريو ب)،
  والعضوية عبر البوت رقم 2 (السيناريو أ)، وتبديل/استعادة مجموعة الرموز؛
- `/mbchannel <chat>` — ‏`getChatMember` من البوت رقم 2 على `@channel`/معرف محادثة صريح
  (يجب أن يكون البوت رقم 2 مشرفًا/عضوًا في تلك المحادثة).

سجل الأدلة: `runtime/p331/multibot.log` — التشغيل المباشر النهائي
(2026-10-11 12:26:19): الهوية ✔، العزل ✔، الإرسال العابر ✔ (239 مللي ثانية)،
العضوية ✔ (13 مللي ثانية)، مجموعة الرموز ✔ — **5/5 PASS في 393 مللي ثانية**.

تشغيلات إضافية للحاضنة (2026-10-11، بيئة التشغيل المباشرة بعد المزامنة):

- **مجموعة إدارة التوكنات TM-1** — 23/23 PASS عبر أربع حالات:
  ‏`parse` (7)، ‏`identity` (8، بوتان حقيقيان)، ‏`degrade` (5، رمز مُلغى)،
  ‏`scale` (3، 32 بوتًا / 10 آلاف عملية بحث).
- **مجموعة الروبوتات المُدارة TM-2** — 8/8 PASS: يكشف `getMe` المباشر
  عن `can_manage_bots`؛ ويمكن الوصول إلى `getManagedBotToken` / `getManagedBotAccessSettings`
  بعقود أخطاء نظيفة؛ وتحديث `managed_bot` الاصطناعي يُرسَل إلى
  `before → onManagedBot`؛ والاستدعاء غير الصالح لـ `registerManagedBot()` يرمي
  استثناءً نظيفًا دون إفساد السجل؛ وشكل لوحة المفاتيح من `requestManagedBot()`
  يطابق `KeyboardButtonRequestManagedBot`.

</div>
