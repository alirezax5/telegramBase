# MenuFlow — صفحه‌های پنل‌محور با بازگشت/کنسل داخلی

موتور Conversation (ببینید [conversations.md](conversations.md)) برای
فرم‌های خطی ساخته شده. پنل ادمین فرق دارد: گرافی از صفحه‌ها که کاربر بین
آن‌ها می‌پرد، کیبورد Reply ماندگار دارد، چند صفحه input می‌گیرد و انتظار
جهانی این است که «بازگشت» / «منوی اصلی» همیشه کار کنند — و وقتی فلوی تمام
شد کیبورد مرده جا نماند.

`App\Conversation\MenuFlow` یک ماشین وضعیت سبک و بی‌DB دقیقاً برای همین شکل
است. از پنل واقعی رلهٔ پشتیبانی استخراج شد، بعد از آن‌که مالک گزارش کرد
(TM-25) پنل‌های دست‌ساز مدام صفحهٔ input **بدون کنسلِ پیدا** و کیبورد رهاشده
تحویل می‌دهند.

## چه چیزهایی مجانی دارید

- **صفحه‌های نام‌دار** با متن + کیبورد Reply (بیلدر Keyboard)
- **ناوبری برچسب → صفحه** — بدون زنجیرهٔ switch/case دست‌ساز
- هندل داخلی **«↩️ بازگشت»** (می‌رود به `parent` صفحه) و **«🏠 منوی اصلی»**
  (پرش به خانه) — برچسب‌ها قابل تغییرند
- **صفحه‌های input** (`'parse' => true`) که متن تایپی را به `onInput()`
  می‌دهند
- وضعیت هر چت با **CacheManager** (بدون اسکیمای DB، بدون مایگریشن)
- **`exit()`** با `remove_keyboard` — هیچ کیبورد مرده‌ای باقی نمی‌ماند (TM-25)

## پنل حداقلی

```php
use alirezax5\TelegramBase\App\Button\Keyboard;
use alirezax5\TelegramBase\App\Conversation\MenuFlow;
use telegramBotApiPhp\Telegram;

$this->menu = MenuFlow::make('myapp.panel')
    ->screen('main', [
        'text' => '🛠 پنل مدیریت',
        'kb'   => fn () => Keyboard::reply()
            ->button('🚫 بلاک')->row()
            ->button('🏠 منوی اصلی')
            ->resize()->toArray(),
    ])
    ->screen('block', [
        'parent' => 'main',                 // مقصد «بازگشت»
        'text'   => 'آیدی عددی کاربر را بفرستید:',
        'parse'  => true,                   // منتظر input متنی
        'kb'     => fn () => Keyboard::reply()
            ->button('↩️ بازگشت')->row()
            ->button('🏠 منوی اصلی')
            ->resize()->toArray(),
        'onInput' => function (Telegram $tg, int|string $chatId, string $text, MenuFlow $flow): void {
            if (!ctype_digit($text)) {
                $flow->say($tg, $chatId, '❌ فقط آیدی عددی.');
                return;                      // در همان صفحه بمان — کنسل یک تپ فاصله دارد
            }
            // ...عملیات بلاک...
            $flow->say($tg, $chatId, '✅ بلاک شد.');
            $flow->go($tg, $chatId, 'main');
        },
    ])
    ->nav('🚫 بلاک', 'block');               // برچسب → صفحه
```

سمت پلاگین — یک خط، هر پیام را به فلو می‌دهد:

```php
#[ChatType(['private'])]
public function onMessage($message, Telegram $tg): void
{
    if ($this->menu->handle($message, $tg)) {
        \alirezax5\TelegramBase\App\Update\UpdateState::markHandled('myapp.panel');
    }
}
```

`handle()` اگر پیام را مصرف کرد `true` برمی‌گرداند (برچسب ناوبری، بازگشت/خانه،
یا input) و اگر پلاگین باید خودش هندل کند `false`. عمداً **مدل دسترسی
ندارد** — گیت ادمین خودتان را داخل `onInput()` و قبل از `->go()` بگذارید.

## مرجع API

| متد | کار |
|---|---|
| `make($name)` | ساخت فلو (نام، کلید کشِ وضعیت را namespace می‌کند) |
| `screen($key, $spec)` | تعریف صفحه — `text`، `kb`، `parent`، `parse`، `onInput`، `onEnter`؛ text/kb کلوژر هم قبول می‌کنند |
| `nav($label, $screen)` | پرش برچسبِ کیبورد به صفحه |
| `home($key)` | کلید صفحهٔ اصلی (پیش‌فرض `main`) |
| `backLabel()/homeLabel($text)` | تغییر برچسب‌های رزرو ناوبری |
| `handle($message, $tg): bool` | مسیریابی یک پیام؛ true = مصرف شد |
| `go($tg, $chatId, $screen)` | رندر صفحه (مارکاپ جدید جایگزین کیبورد قبلی می‌شود) |
| `say($tg, $chatId, $text)` | پیام بدون ترک صفحه |
| `exit($tg, $chatId, $text)` | خروج از فلو — متن با `remove_keyboard` + پاک‌سازی وضعیت |
| `stateOf()/setData()/data()` | هلپرهای وضعیت هر چت |

## نکات چرخهٔ کیبورد

- پرش به خانه/عقب کیبورد را **جایگزین** می‌کند (مارکاپ صفحهٔ جدید عهده‌دار
  است) — وسط فلو به `remove_keyboard` اضافه نیاز نیست.
- فقط `exit()` واقعاً کیبورد را حذف می‌کند، چون کاربر کل فلو را ترک می‌کند —
  همین قرارداد TM-25 است.
- TTL وضعیت: `MENUFLOW_STATE_TTL` (پیش‌فرض 86400 ثانیه).

## نمونهٔ کامل

ببینید [examples/PanelBot.php](../../examples/PanelBot.php) — پنل ادمین کامل
با صفحهٔ input گیت‌دار، ناوبری بازگشت/خانه و گیت `ADMIN_IDS`.
