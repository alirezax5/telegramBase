# Primeiros passos

## 1. Instale

```bash
git clone https://github.com/alirezax5/telegramBase.git mybot
cd mybot
composer install
cp .env.example .env
```

## 2. Configure

Edite o `.env` — a única chave **obrigatória** é o token do bot:

```dotenv
BOT_TOKEN="123456789:AAEhBP0..."    # from @BotFather
BOT_MODE="update_direct"            # default: long-polling, process inline
```

Opcional, mas recomendado:

```dotenv
ADMIN_IDS="123456789"               # your Telegram user id (for AdminGuardMiddleware)
LOG_ENABLED=true
LOG_LEVEL=info
```

Execute a autoverificação a qualquer momento — ela nunca contata o Telegram, a menos que você passe `--telegram`:

```bash
php bin/tgbase doctor
```

## 3. Escreva seu primeiro plugin

Crie `Plugin/greet.php`:

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

É isso — nenhuma etapa de registro. O framework varre `Plugin/` no boot e descobre toda classe que implementa `PluginInterface`. Verifique com:

```bash
php bin/tgbase plugins
```

Scaffolding mais rápido (cria o arquivo com o namespace correto para você):

```bash
php bin/tgbase make:plugin admin/ban --type=private
# → Plugin/Admin/Ban.php  (namespace …\Plugin\Admin, class Ban)
```

## 4. Execute

Desenvolvimento (em primeiro plano):

```bash
php bot.php
```

Produção — **sempre sob um supervisor de processos** (systemd, supervisord, Docker `restart: always`). O worker de polling reinicia a si mesmo periodicamente para manter a memória limitada, e o supervisor o traz de volta após travadas:

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

Desligamento gracioso: `SIGINT`/`SIGTERM` interrompem o worker na próxima fronteira do loop (pior caso ~`POLLING_TIMEOUT` segundos). Nenhum update é perdido — o Telegram reentrega tudo o que você não confirmou.

## 5. Ou mude para o modo webhook

```bash
# .env
BOT_MODE="webhook_direct"
BOT_WEBHOOK_URL="https://yourdomain.com/webhook.php"
BOT_WEBHOOK_SECRET="$(openssl rand -hex 32)"   # long random string
WEBHOOK_CHECK_IP=true                           # default; disable behind Cloudflare/proxies
```

Registre o webhook uma única vez:

```bash
curl "https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://yourdomain.com/webhook.php&secret_token=<SECRET>"
```

Implante o `webhook.php` atrás de HTTPS — nenhum supervisor é necessário para a própria entrada de webhook; os POSTs do Telegram são requisições sem estado. Detalhes (camadas de validação, proxies, modo fila) em [webhook.md](webhook.md).

## 6. Próximos passos

- Entenda os seis `BOT_MODE`s e quando usar cada um → [bot-modes.md](bot-modes.md)
- Adicione sessões, teclados, middleware → [features.md](features.md)
- Transfira handlers pesados para uma fila → [queue-pipeline.md](queue-pipeline.md)
- Veja o que acontece quando as coisas travam → [reliability.md](reliability.md)
