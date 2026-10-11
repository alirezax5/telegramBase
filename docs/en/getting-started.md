# Getting started

## 1. Install

```bash
git clone https://github.com/alirezax5/telegramBase.git mybot
cd mybot
composer install
cp .env.example .env
```

## 2. Configure

Edit `.env` — the only **required** key is the bot token:

```dotenv
BOT_TOKEN="123456789:AAEhBP0..."    # from @BotFather
BOT_MODE="update_direct"            # default: long-polling, process inline
```

Optional but recommended:

```dotenv
ADMIN_IDS="123456789"               # your Telegram user id (for AdminGuardMiddleware)
LOG_ENABLED=true
LOG_LEVEL=info
```

Run the self-check at any time — it never contacts Telegram unless you pass `--telegram`:

```bash
php bin/tgbase doctor
```

## 3. Write your first plugin

Create `Plugin/greet.php`:

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

That's it — no registration step. The framework scans `Plugin/` at boot and discovers every class implementing `PluginInterface`. Verify with:

```bash
php bin/tgbase plugins
```

Faster scaffolding (creates the file with the right namespace for you):

```bash
php bin/tgbase make:plugin admin/ban --type=private
# → Plugin/Admin/Ban.php  (namespace …\Plugin\Admin, class Ban)
```

## 4. Run

Development (foreground):

```bash
php bot.php
```

Production — **always under a process supervisor** (systemd, supervisord, Docker `restart: always`). The polling worker self-restarts periodically to keep memory bounded, and the supervisor brings it back after crashes:

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

Graceful shutdown: `SIGINT`/`SIGTERM` stop the worker at the next loop boundary (worst case ~`POLLING_TIMEOUT` seconds). No update is lost — Telegram redelivers anything you didn't confirm.

## 5. Or switch to webhook mode

```bash
# .env
BOT_MODE="webhook_direct"
BOT_WEBHOOK_URL="https://yourdomain.com/webhook.php"
BOT_WEBHOOK_SECRET="$(openssl rand -hex 32)"   # long random string
WEBHOOK_CHECK_IP=true                           # default; disable behind Cloudflare/proxies
```

Register the webhook once:

```bash
curl "https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://yourdomain.com/webhook.php&secret_token=<SECRET>"
```

Deploy `webhook.php` behind HTTPS — no supervisor needed for the webhook entry itself; Telegram's POSTs are stateless requests. Details (validation layers, proxies, queue mode) in [webhook.md](webhook.md).

## 6. Next steps

- Understand the six `BOT_MODE`s and when to use each → [bot-modes.md](bot-modes.md)
- Add sessions, keyboards, middleware → [features.md](features.md)
- Offload heavy handlers to a queue → [queue-pipeline.md](queue-pipeline.md)
- Check what happens when things crash → [reliability.md](reliability.md)
