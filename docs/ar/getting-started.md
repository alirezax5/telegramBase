<div dir="rtl">

# البدء

## 1. التثبيت

```bash
git clone https://github.com/alirezax5/telegramBase.git mybot
cd mybot
composer install
cp .env.example .env
```

## 2. الإعدادات

عدّل `.env` — المفتاح **الإلزامي** الوحيد هو رمز البوت:

```dotenv
BOT_TOKEN="123456789:AAEhBP0..."    # from @BotFather
BOT_MODE="update_direct"            # default: long-polling, process inline
```

اختيارية لكن موصى بها:

```dotenv
ADMIN_IDS="123456789"               # your Telegram user id (for AdminGuardMiddleware)
LOG_ENABLED=true
LOG_LEVEL=info
```

شغّل الفحص الذاتي في أي وقت — فهو لا يتصل بـ Telegram إطلاقًا إلا إذا مررت `--telegram`:

```bash
php bin/tgbase doctor
```

## 3. اكتب أول إضافة لك

أنشئ `Plugin/greet.php`:

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

هذا كل شيء — لا توجد خطوة تسجيل. يمسح الإطار مجلد `Plugin/` عند الإقلاع ويكتشف كل صنف يطبّق `PluginInterface`. تحقّق بـ:

```bash
php bin/tgbase plugins
```

توليد أسرع للهياكل (ينشئ الملف لك بـ namespace الصحيح):

```bash
php bin/tgbase make:plugin admin/ban --type=private
# → Plugin/Admin/Ban.php  (namespace …\Plugin\Admin, class Ban)
```

## 4. التشغيل

التطوير (في المقدمة):

```bash
php bot.php
```

الإنتاج — **دائمًا تحت مشرف عمليات** (systemd، supervisord، Docker مع `restart: always`). يعيد عامل الاستطلاع تشغيل نفسه دوريًا لإبقاء الذاكرة محدودة، والمشرف يعيده بعد أي انهيار:

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

الإيقاف الآمن: يوقف `SIGINT`/`SIGTERM` العامل عند حد الحلقة التالي (أسوأ حالة ~`POLLING_TIMEOUT` ثانية). لا يُفقد أي تحديث — فـ Telegram يعيد تسليم أي شيء لم تؤكده.

## 5. أو انتقل إلى وضع الويب هوك

```bash
# .env
BOT_MODE="webhook_direct"
BOT_WEBHOOK_URL="https://yourdomain.com/webhook.php"
BOT_WEBHOOK_SECRET="$(openssl rand -hex 32)"   # long random string
WEBHOOK_CHECK_IP=true                           # default; disable behind Cloudflare/proxies
```

سجّل الويب هوك مرة واحدة:

```bash
curl "https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://yourdomain.com/webhook.php&secret_token=<SECRET>"
```

انشر `webhook.php` خلف HTTPS — لا حاجة إلى مشرف لنقطة دخول الويب هوك نفسها؛ فطلبات POST من Telegram عديمة الحالة. التفاصيل (طبقات التحقق، والوكلاء الوسطاء، ووضع قائمة الانتظار) في [webhook.md](webhook.md).

## 6. الخطوات التالية

- افهم أوضاع `BOT_MODE` الستة ومتى تستخدم كلًّا منها ← [bot-modes.md](bot-modes.md)
- أضف الجلسات ولوحات المفاتيح والوسطاء البرمجيين ← [features.md](features.md)
- انقل المعالجات الثقيلة إلى قائمة انتظار ← [queue-pipeline.md](queue-pipeline.md)
- اطّلع على ما يحدث عند الانهيارات ← [reliability.md](reliability.md)

</div>
