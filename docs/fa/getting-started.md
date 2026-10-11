<div dir="rtl">

# شروع به کار

## 1. نصب

```bash
git clone https://github.com/alirezax5/telegramBase.git mybot
cd mybot
composer install
cp .env.example .env
```

## 2. پیکربندی

فایل `.env` را ویرایش کنید — تنها کلیدِ **لازم** توکن ربات است:

```dotenv
BOT_TOKEN="123456789:AAEhBP0..."    # from @BotFather
BOT_MODE="update_direct"            # default: long-polling, process inline
```

اختیاری اما توصیه‌شده:

```dotenv
ADMIN_IDS="123456789"               # your Telegram user id (for AdminGuardMiddleware)
LOG_ENABLED=true
LOG_LEVEL=info
```

خودآزمایی را هر زمان بخواهید اجرا کنید — این دستور هرگز با تلگرام تماس نمی‌گیرد مگر اینکه `--telegram` را پاس بدهید:

```bash
php bin/tgbase doctor
```

## 3. اولین پلاگین خود را بنویسید

فایل `Plugin/greet.php` را بسازید:

```php
<?php
namespace alirezax5\TelegramBase\Plugin;

use alirezax5\TelegramBase\App\Attributes\ChatType;
use alirezax5\TelegramBase\App\Plugin\Contract\PluginInterface;
use telegramBotApiPhp\Telegram;

#[ChatType(['private'])]
class greet implements PluginInterface
{
    public function getPriority(): int
    {
        return 10;
    }

    public function onMessage($message, Telegram $Telegram): void
    {
        if (($message->text ?? '') === '/start') {
            $Telegram->sendMessage($Telegram->fromId(), '👋 Hello from TelegramBase!');
        }
    }
}
```

همین! هیچ مرحلهٔ ثبت‌نامی وجود ندارد. فریمورک هنگام بوت دایرکتوری `Plugin/` را اسکن می‌کند و هر کلاسی که `PluginInterface` را پیاده‌سازی کرده باشد کشف می‌کند. با این دستور بررسی کنید:

```bash
php bin/tgbase plugins
```

اسکفولدینگ سریع‌تر (فایل را با namespace درست برای شما می‌سازد):

```bash
php bin/tgbase make:plugin admin/ban --type=private
# → Plugin/Admin/Ban.php  (namespace …\Plugin\Admin, class Ban)
```

## 4. اجرا

محیط توسعه (foreground):

```bash
php bot.php
```

پروداکشن — **همیشه زیر یک پروسه‌سوپروایزر** (systemd، supervisord، Docker با `restart: always`). ورکر polling هر از گاهی خودش را ری‌استارت می‌کند تا حافظه محدود بماند و سوپروایزر هم بعد از کرش‌ها آن را دوباره بالا می‌آورد:

```ini
# /etc/systemd/system/mybot.service
[Service]
WorkingDirectory=/var/www/mybot
ExecStart=/usr/bin/php bot.php
Restart=always
RestartSec=3
[Install]
WantedBy=multi-user.target
```

خاموشی آرام: `SIGINT`/`SIGTERM` ورکر را در مرز بعدی حلقه متوقف می‌کنند (بدترین حالت حدود `POLLING_TIMEOUT` ثانیه). هیچ آپدیتی از دست نمی‌رود — تلگرام هر چیزی را که تأیید نکرده باشید دوباره تحویل می‌دهد.

## 5. یا به حالت وبهوک سوئیچ کنید

```bash
# .env
BOT_MODE="webhook_direct"
BOT_WEBHOOK_URL="https://yourdomain.com/webhook.php"
BOT_WEBHOOK_SECRET="$(openssl rand -hex 32)"   # long random string
WEBHOOK_CHECK_IP=true                           # default; disable behind Cloudflare/proxies
```

وبهوک را یک بار ثبت کنید:

```bash
curl "https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://yourdomain.com/webhook.php&secret_token=<SECRET>"
```

فایل `webhook.php` را پشت HTTPS دیپلوی کنید — برای خودِ نقطهٔ ورود وبهوک به سوپروایزر نیازی نیست؛ POSTهای تلگرام درخواست‌های stateless هستند. جزئیات (لایه‌های اعتبارسنجی، پروکسی‌ها، حالت صف) در [webhook.md](webhook.md) آمده است.

## 6. گام‌های بعدی

- شش حالت `BOT_MODE` و اینکه هر کدام را کِی به کار ببرید را بشناسید → [bot-modes.md](bot-modes.md)
- سشن، کیبورد و میدلور اضافه کنید → [features.md](features.md)
- هندلرهای سنگین را به صف بسپارید → [queue-pipeline.md](queue-pipeline.md)
- ببینید وقتی چیزها کرش می‌کنند چه اتفاقی می‌افتد → [reliability.md](reliability.md)

</div>
