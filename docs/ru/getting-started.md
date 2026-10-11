# Начало работы

## 1. Установка

```bash
git clone https://github.com/alirezax5/telegramBase.git mybot
cd mybot
composer install
cp .env.example .env
```

## 2. Настройка

Отредактируйте `.env` — единственный **обязательный** ключ — токен бота:

```dotenv
BOT_TOKEN="123456789:AAEhBP0..."    # from @BotFather
BOT_MODE="update_direct"            # default: long-polling, process inline
```

Необязательно, но рекомендуется:

```dotenv
ADMIN_IDS="123456789"               # your Telegram user id (for AdminGuardMiddleware)
LOG_ENABLED=true
LOG_LEVEL=info
```

В любой момент можно запустить самопроверку — она не обращается к Telegram, если вы не передадите `--telegram`:

```bash
php bin/tgbase doctor
```

## 3. Напишите первый плагин

Создайте `Plugin/greet.php`:

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

Это всё — никакого шага регистрации. Фреймворк сканирует `Plugin/` при загрузке и обнаруживает каждый класс, реализующий `PluginInterface`. Проверьте с помощью:

```bash
php bin/tgbase plugins
```

Более быстрое скаффолдинг-решение (создаёт файл с правильным неймспейсом за вас):

```bash
php bin/tgbase make:plugin admin/ban --type=private
# → Plugin/Admin/Ban.php  (namespace …\Plugin\Admin, class Ban)
```

## 4. Запуск

Разработка (на переднем плане):

```bash
php bot.php
```

Продакшен — **всегда под супервизором процессов** (systemd, supervisord, Docker `restart: always`). Polling-воркер периодически перезапускает сам себя, чтобы память оставалась в ограниченных пределах, а супервизор возвращает его в строй после падений:

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

Корректное завершение работы: `SIGINT`/`SIGTERM` останавливают воркера на ближайшей границе цикла (в худшем случае ~`POLLING_TIMEOUT` секунд). Ни одно обновление не теряется — Telegram повторно доставит всё, что вы не подтвердили.

## 5. Или переключитесь на режим вебхука

```bash
# .env
BOT_MODE="webhook_direct"
BOT_WEBHOOK_URL="https://yourdomain.com/webhook.php"
BOT_WEBHOOK_SECRET="$(openssl rand -hex 32)"   # long random string
WEBHOOK_CHECK_IP=true                           # default; disable behind Cloudflare/proxies
```

Зарегистрируйте вебхук один раз:

```bash
curl "https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://yourdomain.com/webhook.php&secret_token=<SECRET>"
```

Разверните `webhook.php` за HTTPS — для самой webhook-точки входа супервизор не нужен; POST-запросы Telegram не сохраняют состояния. Подробности (слои валидации, прокси, режим очереди) в [webhook.md](webhook.md).

## 6. Дальнейшие шаги

- Разобраться с шестью значениями `BOT_MODE` и тем, когда какое использовать → [bot-modes.md](bot-modes.md)
- Добавить сессии, клавиатуры, middleware → [features.md](features.md)
- Вынести тяжёлые хендлеры в очередь → [queue-pipeline.md](queue-pipeline.md)
- Проверить, что происходит при падениях → [reliability.md](reliability.md)
