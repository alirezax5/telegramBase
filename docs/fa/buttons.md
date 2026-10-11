<div dir="rtl">

# دکمه‌ها — سازندهٔ Keyboard و ButtonManager

دو ابزار مکمل:

- **`Keyboard`** — reply markup ساخته‌شده با کد و به سبک fluent برای منوهای *پویا* (سطرها به دادهٔ زمان اجرا وابسته‌اند).
- **`ButtonManager` / `btn()`** — منوهای ثابتِ *تعریف‌شده در فایل* در `Button/`، با برچسب‌های ترجمه‌شده، قابل کش و قابل اشتراک بین پلاگین‌ها.

```php
use alirezax5\TelegramBase\App\Button\Keyboard;
```

## سازندهٔ Keyboard

### کیبورد inline (دکمه‌های callback)

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

### کیبورد reply (کیبورد مجازی کاربر)

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

حذف کیبورد reply با یک payload خام (یا یک تعریف `btn()`، پایین‌تر را ببینید):

```php
$tg->sendMessage($chatId, 'باشه', ['reply_markup' => ['remove_keyboard' => true]]);
```

### API سازنده

| متد | اعمال می‌شود به | اثر |
|---|---|---|
| `inline()` / `reply()` | سازنده | کیبورد callback / کیبورد سفارشی |
| `button($text, $value = '', $type = CALLBACK)` | هر دو | اضافه به سطر جاری؛ `$type` یکی از `Keyboard::CALLBACK` (`callback_data`)، `Keyboard::URL` (`url`)، `Keyboard::SWITCH_INLINE`، `Keyboard::SWITCH_INLINE_CURRENT` |
| `row()` | هر دو | شروع یک سطر جدید |
| `resize($v)` | reply | `resize_keyboard` |
| `oneTime($v)` | reply | `one_time_keyboard` |
| `selective($v)` | reply | `selective` |
| `inputPlaceholder($text)` | inline | `input_field_placeholder` |
| `toArray()` / `toJson()` | هر دو | مقدار نهایی `reply_markup` (آرایه) / رشتهٔ JSON |

سطرهای خالی به‌طور خودکار حذف می‌شوند؛ `toArray()` دقیقاً همان شکلی را برمی‌گرداند که Bot API برای `reply_markup` انتظار دارد.

### هندل‌کردن لمس‌ها

دکمه‌های inline به‌صورت آپدیت‌هایی از نوع `callback_query` می‌رسند — در پلاگین خود `onCallback_query()` را پیاده‌سازی کنید و بر اساس `callback_data` مسیریابی کنید (به [plugins.md](plugins.md) نگاهی بیندازید)؛ با `$tg->answerCallbackQuery($callbackQueryId)` به آن‌ها پاسخ بدهید. لمس‌های کیبورد reply پیام‌های متنی معمولی‌اند.

## ButtonManager — دکمه‌های تعریف‌شده در فایل

تعاریف در `BUTTONS_DIR` (پیش‌فرض `/Button`) قرار دارند — فایل اصلی `BUTTONS_FILE` (پیش‌فرض `btn.php`) به‌علاوهٔ هر فایل `*.php` دیگری در آن دایرکتوری، که پیشوند کلید `'<filename>.'` می‌گیرد (همان الگوی لودر زبان).

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

تعاریف دقیقاً «هر چیزی که فایل برمی‌گرداند» هستند — بیشترشان آرایه‌های `reply_markup` آمادهٔ ارسال‌اند، پس `$tg->sendMessage($chatId, 'متن', ['reply_markup' => btn('start')])` کل ماجرا را می‌سازد.

## کش (تأییدشده)

تعاریف کامپایل‌شده زیر کلید `buttons:all` با `BUTTONS_CACHE_TTL` (پیش‌فرض 300 ثانیه) کش می‌شوند تا فایل‌ها یک بار در هر پنجرهٔ TTL خوانده شوند، نه به ازای هر آپدیت. `BUTTONS_CACHE_ENABLED=false` کش را کاملاً خاموش می‌کند (تعاریف هر بار دوباره از فایل خوانده می‌شوند) — این کلید از نسخهٔ رفع P-CACHE-F002 به بعد سرتاسری سیم‌کشی شده است (قبلاً در پیکربندی خوانده می‌شد اما هرگز ملاک عمل نبود؛ رفع آن در هر دو حالت به‌صورت زنده تأیید شد). `ButtonManager::reload()` در زمان اجرا یک تازه‌سازی اجباری انجام می‌دهد.

کلید کش / دامنه از [caching.md](caching.md) پیروی می‌کند: روی `array` کش مخصوص هر پروسه است؛ روی `file`/`redis`/`memcached` تعاریف بین ورکرها به اشتراک گذاشته می‌شوند.

## دستورهای تست (integration/TestBot)

`/btn` (اگر در مجموعه پلاگین‌هایتان موجود باشد) — پلاگین همراه یعنی `StartPlugin` عملاً `btn('start')` را نشان می‌دهد؛ مجموعهٔ P-CACHE تأیید کرد که `btn('start')` resolve می‌شود (`rows=1`) و payload آن به‌صورت بین‌پروسه‌ای زیر `buttons:all` پایدار می‌ماند.

</div>
