# مینی‌اپ تلگرام — اعتبارسنجی `initData`

مینی‌اپ‌های تلگرام (Web Appهای سابق) صفحات وب عادی‌اند که داخل تلگرام باز می‌شوند. فرانت‌اند یک **رشتهٔ هویتی امضاشده** دریافت می‌کند — `Telegram.WebApp.initData` — که ثابت می‌کند *کدام کاربر تلگرام* صفحه را می‌بیند. بک‌اند باید این امضا را قبل از سرو هر دادهٔ مختص کاربر تأیید کند. این راهنما نیمهٔ بک‌اند را پوشش می‌دهد که به‌صورت یک کلاس کوچک اعتبارسنج عرضه شده:

- فریم‌ورک: `alirezax5\TelegramBase\App\MiniApp\InitData`
- پروژه‌های فقط-SDK (بدون فریم‌ورک): `telegramBotApiPhp\WebApp\InitData`

> **هرگز به `initDataUnsafe` اعتماد نکنید.** همان فیلدها را دارد اما *بدون امضا* است. هر کسی می‌تواند JSON دلخواه به endpoint شما POST کند — تنها `initData` امضاشده است که می‌توان در برابر توکن ربات راستی‌آزمایی کرد.

## جریان کار

```
┌─────────────┐  ۱. بازکردن مینی‌اپ    ┌──────────────────┐
│ تلگرام       │ ──────────────────► │ صفحهٔ مینی‌اپ      │  هاست HTTPS شما
│ چت           │                     │ (HTML/JS استاتیک) │
└─────────────┘                     └────────┬─────────┘
       ▲                                    │  ۲. POST { initData } (fetch/form)
       │  ۴. رندر JSON تأییدشده              ▼
┌──────┴───────┐                     ┌──────────────────┐
│ UI مینی‌اپ    │ ◄────────────────── │ endpoint بک‌اند    │  InitData::validate()
└─────────────┘                     └──────────────────┘
```

ترافیک مینی‌اپ از وب‌هوک ربات **عبور نمی‌کند** — مرورگرِ کاربر مستقیماً دامنهٔ شما را صدا می‌زند. `BOT_MODE`، صف و `allowed_updates` هیچ دخالتی ندارند. تنها اثبات هویت، `initData` امضاشده است؛ پس آن را روی **هر** درخواست مهم اعتبارسنجی کنید.

## استفاده در بک‌اند

```php
use alirezax5\TelegramBase\App\MiniApp\InitData;

// پذیرش هم POST فرم معمول، هم بدنهٔ JSON
$raw = $_POST['initData'] ?? null;
if (!is_string($raw) || $raw === '') {
    $json = json_decode((string) file_get_contents('php://input'), true);
    $raw  = is_array($json) ? (string) ($json['initData'] ?? '') : '';
}

// توکن از BOT_TOKEN محیط تشخیص داده می‌شود؛ برای چند-رباتی صریح بدهید:
//   InitData::validate($raw, $extraBotToken, ['max_age' => 3600])
$res = InitData::validate((string) $raw, null, ['max_age' => 3600]);

if (!$res['ok']) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $res['error']], JSON_UNESCAPED_UNICODE);
    exit;
}

$user       = $res['user'];        // ['id' => 555000111, 'first_name' => 'علی', 'username' => ...]
$startParam = $res['start_param']; // بارِ لینک عمیق از ?startapp=ref_7
$authDate   = $res['auth_date'];   // زمان صدور امضا
```

یک endpoint و صفحهٔ کامل و اجرایی در [examples/MiniAppEndpoint.php](../examples/MiniAppEndpoint.php) هست.

## API

| متد | امضا | خروجی |
|---|---|---|
| `validate()` | `validate(string $initData, ?string $botToken = null, array $options = [])` | آرایهٔ کامل نتیجه (پایین) |
| `valid()` | همان آرگومان‌ها | `bool` |
| `user()` | همان آرگومان‌ها | `?array` — کاربر مینی‌اپ، یا `null` در صورت ناموفق بودن |
| `build()` | `build(array $fields, ?string $botToken = null)` | رشتهٔ initData **امضاشدهٔ** درست — برای تست، فیکسچر و تست با curl |

**نتیجهٔ موفق:** `ok`، `user` (آرایهٔ دیکودشده یا `null`)، `auth_date`، `start_param`، `fields` (همهٔ فیلدها بدون hash)، `hash`.

**نتیجهٔ ناموفق:** `ok: false` + `error` با یکی از مقادیر: `empty_init_data`، `bot_token_missing`، `malformed_init_data`، `hash_missing`، `bad_signature`، `auth_date_missing`، `auth_date_expired`.

**آپشن‌ها:** `max_age` — بیشینهٔ عمر پذیرفتهٔ `auth_date` به ثانیه. پیش‌فرض `86400` (۲۴ ساعت)؛ `0` یعنی بدون بررسی. برای عملیات حساس پنجرهٔ کوتاه (مثلاً `3600`) بگذارید.

## اسنیپت فرانت‌اند

```html
<script src="https://telegram.org/js/telegram-web-app.js"></script>
<script>
  fetch('/miniapp/shop.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ initData: Telegram.WebApp.initData })
  })
    .then(r => r.json())
    .then(d => { /* d.ok ? رندر(d) : نمایش(d.error) */ });
</script>
```

بازکردن مینی‌اپ از ربات: `Keyboard::reply()->webApp('فروشگاه', 'https://your.domain/miniapp/shop.php')` (ببینید [buttons.md](buttons.md)) یا تنظیم Menu Button ربات از BotFather. پارامترهای لینک عمیق به‌صورت `start_param` از `https://t.me/yourbot?startapp=ref_7` می‌رسند.

## تست محلی بدون تلگرام

`build()` یک رشتهٔ فیکسچر را با توکن شما امضا می‌کند تا endpointها را قبل از ساختن فرانت‌اند با curl باز کنید:

```bash
php -r '
require "vendor/autoload.php";
use alirezax5\TelegramBase\App\MiniApp\InitData;
echo InitData::build(["user" => ["id" => 555000111, "first_name" => "علی"], "start_param" => "ref_7"], getenv("BOT_TOKEN"));
' > /tmp/init.txt

curl -s https://your.domain/miniapp/shop.php --data-urlencode "initData@/tmp/init.txt"
```

> `build()` فقط برای **توسعه** است — برای تست اعتبارسنج ساخته شده؛ در تولید لازم نیست و بدیهی است که هرگز نباید به‌صورت endpoint در دسترس باشد.

## چک‌لیست امنیتی

- اعتبارسنجی سمت سرور روی **هر** درخواست مهم؛ امضا فقط «کاربر» را ثابت می‌کند، نه درستیِ منطق درخواست.
- `max_age` را کوتاه نگه دارید (`3600` یا کمتر) — `initData` خودبه‌خود منقضی نمی‌شود.
- مقایسه با `hash_equals` (زمان-ثابت) انجام می‌شود؛ هرگز hash را با `===` مقایسه نکنید.
- `initData` و توکن‌های ربات را لاگ نکنید؛ این‌ها اعتبارنامهٔ bearer هستند.
- فیلد `signature` (Ed25519) که تلگرام هم می‌فرستد برای اعتبارسنجی مینی‌اپ‌های **شخص ثالث** است؛ برای مینی‌اپ ربات خودتان، بررسی HMAC `hash` بالا طرح رسمی و کافی است.
- فرانت‌اند فقط روی HTTPS سرو شود — تلگرام هم الزامش دارد و `initData` فقط به‌اندازهٔ کانالی که از آن عبور می‌کند قابل‌اعتماد است.

## سازوکار اعتبارسنجی (مرجع)

1. `initData` را به‌صورت query string پارس کنید؛ `hash` را جدا کنید.
2. Data-check-string = هر فیلد باقی‌مانده به‌شکل `key=value`، مرتب‌شده الفبایی بر اساس کلید، چسبیده با `\n` (0x0A).
3. `secret_key = HMAC_SHA256(key="WebAppData", message=BOT_TOKEN)`
4. `computed   = HMAC_SHA256(key=secret_key, message=data_check_string)` — hex.
5. معتبر است اگر `computed == hash` (زمان-ثابت) و `auth_date` داخل `max_age` باشد.

سویت تست با یک **بردار بین-زبانی** این را قفل می‌کند: `tests/Unit/MiniAppInitDataTest.php` رشتهٔ initData را که مستقلاً در پایتون تولید شده اعتبارسنجی می‌کند — تا باگ کلاسیکِ ساکتِ جابه‌جایی آرگومان‌های key/message در HMAC رخ ندهد.
