# Prise en main

## 1. Installer

```bash
git clone https://github.com/alirezax5/telegramBase.git mybot
cd mybot
composer install
cp .env.example .env
```

## 2. Configurer

Éditez `.env` — la seule clé **obligatoire** est le token du bot :

```dotenv
BOT_TOKEN="123456789:AAEhBP0..."    # from @BotFather
BOT_MODE="update_direct"            # default: long-polling, process inline
```

Optionnel mais recommandé :

```dotenv
ADMIN_IDS="123456789"               # your Telegram user id (for AdminGuardMiddleware)
LOG_ENABLED=true
LOG_LEVEL=info
```

Lancez l'auto-diagnostic à tout moment — il ne contacte jamais Telegram sauf si vous passez `--telegram` :

```bash
php bin/tgbase doctor
```

## 3. Écrire votre premier plugin

Créez `Plugin/greet.php` :

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

C'est tout — aucune étape d'enregistrement. Le framework analyse `Plugin/` au démarrage et découvre chaque classe qui implémente `PluginInterface`. Vérifiez avec :

```bash
php bin/tgbase plugins
```

Scaffolding plus rapide (crée le fichier avec le bon namespace pour vous) :

```bash
php bin/tgbase make:plugin admin/ban --type=private
# → Plugin/Admin/Ban.php  (namespace …\Plugin\Admin, class Ban)
```

## 4. Exécuter

Développement (au premier plan) :

```bash
php bot.php
```

Production — **toujours sous un superviseur de processus** (systemd, supervisord, Docker `restart: always`). Le worker de polling se redémarre périodiquement de lui-même pour garder la mémoire bornée, et le superviseur le relance après un plantage :

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

Arrêt propre : `SIGINT`/`SIGTERM` arrêtent le worker à la prochaine frontière de boucle (au pire ~`POLLING_TIMEOUT` secondes). Aucun update n'est perdu — Telegram re-livre tout ce que vous n'avez pas confirmé.

## 5. Ou passer en mode webhook

```bash
# .env
BOT_MODE="webhook_direct"
BOT_WEBHOOK_URL="https://yourdomain.com/webhook.php"
BOT_WEBHOOK_SECRET="$(openssl rand -hex 32)"   # long random string
WEBHOOK_CHECK_IP=true                           # default; disable behind Cloudflare/proxies
```

Enregistrez le webhook une seule fois :

```bash
curl "https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://yourdomain.com/webhook.php&secret_token=<SECRET>"
```

Déployez `webhook.php` derrière HTTPS — pas besoin de superviseur pour le point d'entrée webhook lui-même ; les POST de Telegram sont des requêtes sans état. Détails (couches de validation, proxys, mode file) dans [webhook.md](webhook.md).

## 6. Étapes suivantes

- Comprendre les six `BOT_MODE` et quand utiliser chacun → [bot-modes.md](bot-modes.md)
- Ajouter sessions, claviers, middleware → [features.md](features.md)
- Décharger les handlers lourds vers une file → [queue-pipeline.md](queue-pipeline.md)
- Voir ce qui se passe quand les choses plantent → [reliability.md](reliability.md)
