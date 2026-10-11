# Plugins — écrire la logique de votre bot

Un plugin est une classe PHP dans `Plugin/` qui implémente `PluginInterface`. Pas d'enregistrement, pas de fichier de configuration, pas de service provider — déposez le fichier et le framework le découvre au prochain démarrage.

## Anatomie

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

Remarques :

- `$message` est l'**objet message** (pas l'update complet) — `$message->text`, `$message->chat->id`, `$message->photo`, etc.
- L'objet update complet (avec `update_id`) est disponible via `$Telegram->getInputData()`.
- Un plugin sans attribut `#[ChatType]` s'exécute pour tous les types de chat.

## Méthodes de handler

| Méthode | Déclenchée quand |
|--------|----------------|
| `onMessage` | n'importe quel message (texte, photo, document, ...) |
| `onCallback_query` | un bouton de clavier inline est pressé |
| `onCommand` | un message `/commande_slash` |
| `before` | avant chaque update (tout type) |
| `after` | après chaque update |

N'implémentez que les handlers dont vous avez besoin.

## Filtrage par type de chat

```php
#[ChatType(['private'])]                   // private chats only
#[ChatType(['group', 'supergroup'])]       // groups only
#[ChatType(['channel'])]                   // channel posts
```

L'attribut correspond aux types de chat de Telegram, résolus par le SDK (`$Telegram->chatType()`).

## Ordre et court-circuit

Les plugins s'exécutent dans l'ordre de priorité pour chaque handler. N'importe quel plugin peut arrêter la chaîne simplement en *ne* déclenchant *pas* la logique suivante — mais l'outil le plus propre pour des règles transversales de « rejet » (bans, limites de débit) est le **middleware**, qui s'exécute avant le dispatch des plugins pour chaque update. Voir [features.md](features.md).

## Règles de découverte

- Répertoire : `PLUGINS_DIR` dans `.env` (par défaut `/Plugin/`, relatif à la base du projet ; les chemins absolus sont pris en charge).
- Les fichiers sont analysés récursivement ; le nom de la classe doit correspondre au nom du fichier, à la manière PSR-4 (`Plugin/Admin/Ban.php` → classe `Ban` dans le namespace `…\Plugin\Admin`).
- Les métadonnées des plugins (l'ensemble découvert) sont mises en cache ; pendant le développement, mettez `PLUGIN_CACHE_ENABLED=false` ou utilisez `PLUGINS_RELOAD_INTERVAL` pour prendre en compte les nouveaux fichiers sans vider les caches manuellement.
- Vérifiez ce que le framework voit : `php bin/tgbase plugins` — affiche la priorité, les types de chat et les handlers enregistrés pour chaque plugin.

## Un exemple complet

[examples/HelloBot.php](../../examples/HelloBot.php) démontre les patterns courants dans un seul fichier :

- routage des commandes `/hello` et `/status`
- construction d'un clavier inline avec le builder fluide `Keyboard`
- état par chat avec `SessionManager`
- appels API sûrs avec `tg_retryable()` (lève `RetryableException` sur 429/5xx → file de retry automatique)
- réponse et actions sur les callback queries

Pour l'essayer : copiez le fichier dans `Plugin/`, changez le namespace en `alirezax5\TelegramBase\Plugin`, redémarrez le bot.

## Scaffolding

```bash
php bin/tgbase make:plugin users/register --type=private
php bin/tgbase make:plugin admin/ban      --type=group
```

Les segments du chemin deviennent des namespaces PSR-4 (`users/register` → `Plugin\Users\Register`).
