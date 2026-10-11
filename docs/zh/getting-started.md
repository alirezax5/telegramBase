# 快速开始

## 1. 安装

```bash
git clone https://github.com/alirezax5/telegramBase.git mybot
cd mybot
composer install
cp .env.example .env
```

## 2. 配置

编辑 `.env` —— 唯一**必需**的键是机器人 token：

```dotenv
BOT_TOKEN="123456789:AAEhBP0..."    # from @BotFather
BOT_MODE="update_direct"            # default: long-polling, process inline
```

可选但推荐：

```dotenv
ADMIN_IDS="123456789"               # your Telegram user id (for AdminGuardMiddleware)
LOG_ENABLED=true
LOG_LEVEL=info
```

随时可以运行自检——除非你传入 `--telegram`，否则它绝不会连接 Telegram：

```bash
php bin/tgbase doctor
```

## 3. 编写你的第一个插件

创建 `Plugin/greet.php`：

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

就是这样——没有注册步骤。框架在启动时扫描 `Plugin/`，并发现每一个实现了 `PluginInterface` 的类。用以下命令验证：

```bash
php bin/tgbase plugins
```

更快的脚手架（为你创建带有正确命名空间的文件）：

```bash
php bin/tgbase make:plugin admin/ban --type=private
# → Plugin/Admin/Ban.php  (namespace …\Plugin\Admin, class Ban)
```

## 4. 运行

开发环境（前台运行）：

```bash
php bot.php
```

生产环境——**务必在进程监督器下运行**（systemd、supervisord、Docker `restart: always`）。轮询工作进程会周期性地自我重启以保持内存有界，监督器则在崩溃后把它拉起来：

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

优雅停机：`SIGINT`/`SIGTERM` 会让工作进程在下一个循环边界处停止（最坏情况约 `POLLING_TIMEOUT` 秒）。不会有更新丢失——Telegram 会重新投递任何你没有确认的内容。

## 5. 或者切换到 Webhook 模式

```bash
# .env
BOT_MODE="webhook_direct"
BOT_WEBHOOK_URL="https://yourdomain.com/webhook.php"
BOT_WEBHOOK_SECRET="$(openssl rand -hex 32)"   # long random string
WEBHOOK_CHECK_IP=true                           # default; disable behind Cloudflare/proxies
```

注册一次 Webhook：

```bash
curl "https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://yourdomain.com/webhook.php&secret_token=<SECRET>"
```

把 `webhook.php` 部署在 HTTPS 之后——Webhook 入口本身不需要监督器；Telegram 的 POST 是无状态请求。详细内容（校验层、代理、队列模式）见 [webhook.md](webhook.md)。

## 6. 下一步

- 理解六种 `BOT_MODE` 以及各自何时使用 → [bot-modes.md](bot-modes.md)
- 添加会话、键盘、中间件 → [features.md](features.md)
- 把繁重的处理器卸载到队列 → [queue-pipeline.md](queue-pipeline.md)
- 查看崩溃时会发生什么 → [reliability.md](reliability.md)
