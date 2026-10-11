<div dir="rtl">

# الأزرار — باني Keyboard و ButtonManager

أداتان متكاملتان:

- **`Keyboard`** — ترميز رد مبني بالكود وبأسلوب سلس للقوائم *الديناميكية* (تعتمد الصفوف على بيانات وقت التشغيل).
- **`ButtonManager` / `btn()`** — قوائم ثابتة *معرَّفة في ملفات* داخل `Button/`، بتسميات مترجمة، قابلة للتخزين المؤقت، وقابلة للمشاركة بين الإضافات.

```php
use alirezax5\TelegramBase\App\Button\Keyboard;
```

## باني لوحات المفاتيح

### لوحة المفاتيح المضمنة (أزرار callback)

```php
$kb = Keyboard::inline()
    ->button('✅ تایید', 'order_confirm')      // text, callback payload
    ->button('❌ انصراف', 'order_cancel')
    ->row()                                     // next buttons go on a new row
    ->button('راهنما', 'https://example.com', Keyboard::URL)
    ->toArray();

$resp = $tg->sendMessage($chatId, 'سفارش را تایید می‌کنید؟', ['reply_markup' => $kb]);
tg_retryable($resp, 'sendMessage');
```

### لوحة رد (لوحة المفاتيح الافتراضية للمستخدم)

```php
$kb = Keyboard::reply()
    ->button('🔍 جستجو')
    ->button('💳 خرید')
    ->row()
    ->button('⚙️ تنظیمات')
    ->resize()        // fit text height
    ->oneTime()       // hide after first tap
    // ->selective()  // show only to mentioned users
    ->toArray();
```

أزل لوحة الرد بحمولة خام (أو بتعريف `btn()`، انظر أدناه):

```php
$tg->sendMessage($chatId, 'باشه', ['reply_markup' => ['remove_keyboard' => true]]);
```

### واجهة API للباني

| الدالة | تنطبق على | التأثير |
|---|---|---|
| `inline()` / `reply()` | المُنشئ | لوحة callback / لوحة مخصصة |
| `button($text, $value = '', $type = CALLBACK)` | كلاهما | إلحاق بالصف الجاري؛ تكون `$type` إحدى `Keyboard::CALLBACK` (‏`callback_data`)، أو `Keyboard::URL` (‏`url`)، أو `Keyboard::SWITCH_INLINE`، أو `Keyboard::SWITCH_INLINE_CURRENT` |
| `row()` | كلاهما | بدء صف جديد |
| `resize($v)` | الرد | `resize_keyboard` |
| `oneTime($v)` | الرد | `one_time_keyboard` |
| `selective($v)` | الرد | `selective` |
| `inputPlaceholder($text)` | المضمنة | `input_field_placeholder` |
| `toArray()` / `toJson()` | كلاهما | قيمة `reply_markup` النهائية (مصفوفة) / سلسلة JSON |

تُهمَل الصفوف الفارغة تلقائيًا؛ ويعيد `toArray()` البنية التي يتوقعها Bot API لـ `reply_markup` بالضبط.

### التعامل مع النقرات

تصل الأزرار المضمنة بوصفها تحديثات من نوع `callback_query` — طبّق `onCallback_query()` في إضافتك ووجّه بناءً على `callback_data` (انظر [plugins.md](plugins.md))؛ وأجب عنها بـ `$tg->answerCallbackQuery($callbackQueryId)`. أما نقرات لوحة الرد فهي رسائل نصية عادية.

## ButtonManager — أزرار معرَّفة في ملفات

التعريفات تسكن في `BUTTONS_DIR` (الافتراضي `/Button`) — الملف الرئيسي `BUTTONS_FILE` (الافتراضي `btn.php`) زائد أي ملف `*.php` آخر في ذلك المجلد، ويُعطى بادئة مفتاح `'<filename>.'` (النمط نفسه المستخدم في محمّل اللغة).

```php
// Button/btn.php
return [
    "remove" => ['KeyboardRemove' => [], 'remove_keyboard' => true],

    "start" => [
        'keyboard' => [
            [
                ['text' => __('start')],           // translations work inside definitions
                ['text' => __('messages.help')],
            ],
        ],
        'resize_keyboard' => true,
    ],
];
```

```php
use function alirezax5\TelegramBase\App\Button\btn;   // or the global btn() helper

$kb  = btn('start');                       // whole definition → reply_markup
$kb2 = btn('profile', ['ID' => 12]);       // {ID} and {{ID}} placeholders replaced everywhere
$raw = btn('remove');                      // raw Telegram payload (KeyboardRemove)
ButtonManager::has('start');               // exists?
ButtonManager::getAll();                   // every definition
ButtonManager::reload();                   // re-read files, refresh cache
```

التعريفات هي بالضبط «أي شيء يعيده الملف» — ومعظمها مصفوفات `reply_markup` جاهزة للإرسال، لذا فإن `$tg->sendMessage($chatId, 'متن', ['reply_markup' => btn('start')])` هي القصة كلها.

## التخزين المؤقت (مُتحقَّق منه)

تُخزَّن التعريفات المُصرَّفة في الكاش تحت المفتاح `buttons:all` مع `BUTTONS_CACHE_TTL` (الافتراضي 300 ثانية) بحيث تُقرأ الملفات مرة واحدة لكل نافذة TTL، لا لكل تحديث. يُلغي `BUTTONS_CACHE_ENABLED=false` الكاش كليًا (تُعاد قراءة التعريفات من الملفات في كل مرة) — وقد وُصّل هذا المفتاح من طرف إلى طرف منذ P-CACHE-F002 (كان يُقرأ سابقًا في الإعدادات دون أن يؤخذ به قطع؛ وتحقّق من الإصلاح مباشرة في الحالتين). يجبر `ButtonManager::reload()` على تحديث في وقت التشغيل.

يتبع مفتاح الكاش / نطاق الوصول [caching.md](caching.md): على `array` يكون الكاش لكل عملية؛ وعلى `file`/`redis`/`memcached` تتشارك العمال التعريفات.

## أوامر الاختبار (integration/TestBot)

‏`/btn` (إن وُجد في مجموعة إضافاتك) — يعرض `StartPlugin` المشحون ‏`btn('start')` أثناء العمل؛ وتحققت مجموعة P-CACHE من أن `btn('start')` يُحلّ (`rows=1`) وحمولته تدوم عبر العمليات تحت `buttons:all`.

</div>
