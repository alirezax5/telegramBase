# گفتگوها (فلوهای چندمرحله‌ای)

موتور Conversation یک چت خطی (ویزارد ثبت‌نام، فرم تیکت، محاسبه‌گر سفارش،
نظرسنجی و …) را به یک کلاس کوچکِ اعلانی تبدیل می‌کند. این نسخه، نسخهٔ
ارتقایافته به هستهٔ همان موتوری است که در دموها به‌صورت زنده محک خورد
(TM-14 تا TM-16): ذخیرهٔ ماندگار state، مسیریابی ورودی، اعتبارسنجی با retry،
رد کردن مرحله، شاخه‌بندی، ناوبری (بازگشت / منوی اصلی)، مرحله‌های فقط-نمایش با
محاسبه، و هر دو سبک کیبورد (اینلاین و Reply Keyboard) همه به‌صورت خودکار
مدیریت می‌شوند.

## فعال‌سازی موتور

کلاس‌های موتور بیرون از پوشهٔ پلاگین‌ها (`Plugin/`) هستند و به‌صورت
**پلاگین هسته‌ای** ثبت می‌شوند:

```dotenv
CORE_PLUGINS="alirezax5\TelegramBase\App\Conversation\ConversationManager"
```

سپس مایگریشن idempotent جدول `conversations`
(`Database/Migrations/2026_10_12_000003_create_conversations_table.php`) را با
روال همیشگی مایگریشن اجرا کنید. `ConversationManager` در اولویت ۲ اجرا
می‌شود — بعد از لودر زبان (اولویت ۰) و قبل از پلاگین‌های معمولی (۵ به بالا)؛
بنابراین ورودیِ خورده‌شده توسط یک گفتگوی فعال هرگز به پلاگین‌های دیگر یا
Fallback نشت نمی‌کند.

## نوشتن یک فلو

```php
use alirezax5\TelegramBase\App\Conversation\Conversation;
use alirezax5\TelegramBase\App\Conversation\Step;
use alirezax5\TelegramBase\App\Attributes\ChatType;

#[ChatType('private')]
class RegisterWizard extends Conversation
{
    public function flowKey(): string { return 'register'; }

    protected function steps(): array
    {
        return [
            'name' => Step::text()
                ->ask('wiz.ask_name')
                ->validate(fn ($v) => mb_strlen($v) >= 2)
                ->retry('wiz.bad_name'),

            'phone' => Step::text()
                ->ask('wiz.ask_phone')
                ->pattern('#^\+?\d{10,14}$#')
                ->retry('wiz.bad_phone')
                ->skipable(),

            'confirm' => Step::callback()
                ->ask('wiz.confirm')
                ->buttons(['yes' => 'wiz.yes', 'no' => 'wiz.no'])
                ->menu(),          // دکمهٔ ناوبری «منوی اصلی» (TM-16)
        ];
    }

    public function onFinish(array $answers, Telegram $tg, int $chatId): void
    {
        // $answers = ['name' => ..., 'phone' => ..., 'confirm' => 'yes'|'no']
    }
}
```

پلاگین فلو، خودش تریگر شروع را هندل می‌کند (دستور یا متن دکمهٔ منو) و با
`ConversationManager::begin(new self(), $tg, $chatId)` فلو را باز می‌کند.

## انواع Step

| کارخانه            | ورودی مورد انتظار                                        |
|--------------------|----------------------------------------------------------|
| `Step::text()`     | متن آزاد، یا تپ Reply Keyboard (با `->buttons()`)        |
| `Step::photo()`    | بزرگ‌ترین file_id از `photo`                             |
| `Step::callback()` | تپ دکمهٔ شیشه‌ای (با `->buttons()`)                      |
| `Step::display()`  | هیچ — متن `->compute()` را رندر و خودکار جلو می‌رود      |

### تغییردهنده‌های Step

| تغییردهنده                | اثر                                                                     |
|---------------------------|--------------------------------------------------------------------------|
| `ask($key)` / `withAsk()` | متن سؤال (کلید ترجمه) / جایگذاری‌های پویا از پاسخ‌های قبلی              |
| `pattern($regex)`         | اعتبارسنجی regex برای استپ‌های متنی                                     |
| `validate($fn)`           | اعتبارسنجی سفارشی؛ امضا `fn(string $value, array $answers): bool` — آرگومان دوم اختیاری است (TM-16) |
| `retry($key)`             | کلید پیام خطا (پیش‌فرض `conv.retry_default`)                            |
| `optional()`              | مرحله با `/skip` رد می‌شود (مقدار `null` ثبت می‌شود)                    |
| `when($fn)`               | مرحلهٔ شرطی: `fn(array $answers): bool` (شاخه‌بندی)                     |
| `buttons($map)`           | `value => label`؛ **آرایه یا closure** برای فهرست پویا (TM-16)          |
| `columns($n)`             | تعداد ستون‌های کیبورد (Reply Keyboard همیشه؛ Inline اگر > ۱)            |
| `onDone($fn)`             | هوک بلافاصله بعد از ثبت این مرحله                                       |
| `backable()`              | رندر دکمهٔ ناوبری **بازگشت** (TM-16)                                    |
| `menu()`                  | رندر دکمهٔ ناوبری **منوی اصلی** (TM-16)                                 |
| `compute($fn)`            | متن استپ‌های display: رشتهٔ خام یا `[کلید, جایگذاری‌ها]`                |

## ناوبری (TM-16)

* **بازگشت** (`backable()`): به اولین مرحلهٔ *ورودیِ* قبلی در مسیر مؤثر
  (رد کردن استپ‌های display و مراحل حذف‌شده با `when`) برمی‌گردد، پاسخ هدف و
  همهٔ مراحل بعدش را پاک می‌کند (پاسخ یتیم از شاخه‌های `when` نمی‌ماند) و سؤال
  را دوباره می‌پرسد. روی اولین مرحلهٔ ورودی هدفی برای برگشت نیست، پس دکمهٔ
  برگشت اصلاً رندر نمی‌شود؛ در همین حالت، دستور متنی برگشت به منوی اصلی خروج
  می‌کند.
* **منوی اصلی** (`menu()`): گفتگو را لغو و منوی اصلی را رندر می‌کند.

دکمه‌های ناوبری با هر دو نوع کیبورد یکسان کار می‌کنند:

* اینلاین → `callback_data` برابر `conv:{flow}:__nav__:{back|menu}`
* Reply Keyboard → برچسب بومی‌شده؛ تطبیق locale-aware از طریق
  `navBackTexts()` / `exitTexts()` انجام می‌شود (کلیدهای پیش‌فرض
  `conv.nav_back` و متن‌های خروج اپ). تایپ دستی همان برچسب هم پذیرفته است.

برچسب‌ها حین گفتگو رزرو هستند: تطبیق ناوبری قبل از تطبیق دکمه‌های مرحله
انجام می‌شود.

## استپ‌های Reply Keyboard (TM-15)

برای استپ متنی با `->buttons(['yes' => 'wiz.yes'])` سؤال با یک Reply Keyboard
موقت پرسیده می‌شود. **برچسب** تپ‌شده از طریق مترجم به **value** کانونی نگاشت
معکوس می‌شود؛ پس در DB همیشه مقدار منطقی ذخیره می‌شود، مستقل از زبان کاربر.
خودِ value خام هم پذیرفته است (تایپ دستی). متنِ خارج از فهرست پیام retry
می‌گیرد.

هر پیام تلگرام فقط یک `reply_markup` دارد؛ موتور گذارهای Reply Keyboard ↔
اینلاین بین مرحله‌ها را مدیریت می‌کند (کیبورد موقت استپ بعدی جمع می‌شود اگر
مرحلهٔ بعد کیبورد ندارد؛ شروع گفتگو کیبورد منو را جمع می‌کند اگر مرحلهٔ اول
ندارد؛ `/cancel` و timeout هم جمع می‌کنند).

## دستورات کنترلی

| دستور     | رفتار                                                            |
|-----------|-------------------------------------------------------------------|
| `/cancel` | لغو صریح گفتگو (همیشه کار می‌کند)                                |
| `/start`  | لغو بی‌صداست و پلاگین استارت عادی منو را رندر می‌کند             |
| `/skip`   | رد کردن مرحلهٔ جاری — فقط روی مراحل `optional()`                 |
| سایر `/دستورها` | راهنمای «گفتگوی فعال داری» (`conv.busy`) حین گفتگو         |

## مهلت زمانی

`timeoutMinutes()` (پیش‌فرض ۱۵) دقیقه بی‌تحرکی، گفتگو را با پیام
`conv.timeout` می‌بندد (شامل دستور تریگرِ فلو). اولین آپدیت بعد از timeout به‌صورت عادی عبور می‌کند.

## هوک‌های اپلیکیشن

```php
// زبان هر کاربر برای ترجمهٔ حین گفتگو (اختیاری؛ پیش‌فرض DEFAULT_LANG)
Conversation::$langResolver = fn (int $chatId) => Users::langOf($chatId);

// markup همراه پیام «خروج به منوی اصلی» (اختیاری)
ConversationManager::$mainMenuMarkup = fn () => ['inline_keyboard' => [...]];
```

## ذخیره‌سازی

گفتگوهای فعال در جدول `conversations` هستند (یک ردیف به ازای هر چت):
`bot_id` (ایزوله‌سازی مولتی‌بات از طریق `TGBASE_BOT_ID`)، `chatid`، `flow`،
`flow_class`، `step`، `answers` (JSON)، `status` (`active|done|cancelled`)،
`created_at`، `updated_at`. ردیف‌های پایان‌یافته/لغوشده برای بازبینی نگه داشته
می‌شوند.

## رشته‌های زبان هسته

به‌صورت `Language/{fa,en}/conv.php` **و** `Language/{fa,en}/conv.json`
(بارگذاری با پیشوند `conv.` بسته به درایور): `conv.cancelled`، `conv.timeout`،
`conv.busy`، `conv.use_buttons`، `conv.stale`، `conv.skip_not_allowed`،
`conv.retry_default`، `conv.nav_back`، `conv.exit_menu`.
