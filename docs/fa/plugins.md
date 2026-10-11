<div dir="rtl">

# پلاگین‌ها — نوشتن منطق ربات شما

یک پلاگین یک کلاس PHP در `Plugin/` است که `PluginInterface` را پیاده‌سازی می‌کند. هیچ ثبت‌نامی، هیچ فایل پیکربندی و هیچ service providerای در کار نیست — فایل را همان‌جا بگذارید و فریمورک در بوت بعدی آن را کشف می‌کند.

## ساختار

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

نکته‌ها:

- `$message` همان **شیء پیام** است (نه آپدیت کامل) — یعنی `$message->text`، `$message->chat->id`، `$message->photo` و غیره.
- شیء کامل آپدیت (همراه با `update_id`) از طریق `$Telegram->getInputData()` در دسترس است.
- پلاگینی که اتریبیوت `#[ChatType]` نداشته باشد برای همهٔ انواع چت اجرا می‌شود.

## متدهای هندلر

| متد | چه زمانی فراخوانی می‌شود |
|--------|----------------|
| `onMessage` | هر پیامی (متن، عکس، فایل و ...) |
| `onCallback_query` | وقتی یکی از دکمه‌های کیبورد inline فشرده می‌شود |
| `onCommand` | پیامی که `/slash_command` است |
| `before` | قبل از هر آپدیت (هر نوعی) |
| `after` | بعد از هر آپدیت |

فقط هندلرهایی را که لازم دارید پیاده‌سازی کنید.

## فیلتر بر اساس نوع چت

```php
#[ChatType(['private'])]                   // private chats only
#[ChatType(['group', 'supergroup'])]       // groups only
#[ChatType(['channel'])]                   // channel posts
```

این اتریبیوت به انواع چت تلگرام نگاشت می‌شود و توسط SDK Resolve می‌شود (`$Telegram->chatType()`).

## ترتیب اجرا و قطع زنجیره

پلاگین‌ها برای هر هندلر به ترتیب اولویت اجرا می‌شوند. هر پلاگینی می‌تواند زنجیره را به‌سادگی با *اجرا نکردن* منطق بعدی متوقف کند — اما ابزار تمیزتر برای قواعد سراسریِ «رد کردن» (بن‌ها، محدودیت نرخ) **میدلور** است که قبل از توزیع پلاگین‌ها برای هر آپدیت اجرا می‌شود. جزئیات در [features.md](features.md) آمده است.

## قواعد کشف

- دایرکتوری: `PLUGINS_DIR` در `.env` (پیش‌فرض `/Plugin/`، نسبت به ریشهٔ پروژه؛ مسیرهای مطلق هم پشتیبانی می‌شوند).
- فایل‌ها به‌صورت بازگشتی اسکن می‌شوند؛ نام کلاس باید با نام فایل مطابقت داشته باشد، به سبک PSR-4 (یعنی `Plugin/Admin/Ban.php` → کلاس `Ban` در namespace `…\Plugin\Admin`).
- فرادادهٔ پلاگین‌ها (مجموعهٔ کشف‌شده) کش می‌شود؛ در زمان توسعه `PLUGIN_CACHE_ENABLED=false` را تنظیم کنید یا از `PLUGINS_RELOAD_INTERVAL` استفاده کنید تا فایل‌های جدید بدون پاک‌کردن دستی کش‌ها دیده شوند.
- ببینید فریمورک چه می‌بیند: `php bin/tgbase plugins` — اولویت، انواع چت و هندلرهای ثبت‌شدهٔ هر پلاگین را نشان می‌دهد.

## یک مثال کامل

[examples/HelloBot.php](../../examples/HelloBot.php) الگوهای رایج را در یک فایل نشان می‌دهد:

- مسیریابی دستورهای `/hello` و `/status`
- ساختن کیبورد inline با سازندهٔ fluent یعنی `Keyboard`
- وضعیت هر چت با `SessionManager`
- فراخوانی‌های امن API با `tg_retryable()` (روی 429/5xx خطای `RetryableException` پرتاب می‌کند → صف تلاش مجدد خودکار)
- پاسخ‌دادن و عمل‌کردن روی callback queryها

برای امتحانش: فایل را در `Plugin/` کپی کنید، namespace را به `alirezax5\TelegramBase\Plugin` تغییر دهید و ربات را ری‌استارت کنید.

## اسکفولدینگ

```bash
php bin/tgbase make:plugin users/register --type=private
php bin/tgbase make:plugin admin/ban      --type=group
```

بخش‌های مسیر به namespaceهای PSR-4 تبدیل می‌شوند (`users/register` → `Plugin\Users\Register`).

</div>
