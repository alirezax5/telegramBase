# Plugins — escrevendo a lógica do seu bot

Um plugin é uma classe PHP em `Plugin/` que implementa `PluginInterface`. Não há registro, nem arquivo de configuração, nem service provider — coloque o arquivo lá e o framework o descobre no próximo boot.

## Anatomia

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

Observações:

- `$message` é o **objeto de mensagem** (não o update completo) — `$message->text`, `$message->chat->id`, `$message->photo`, etc.
- O objeto de update completo (com `update_id`) está disponível como `$Telegram->getInputData()`.
- Um plugin sem o atributo `#[ChatType]` roda para todos os tipos de chat.

## Métodos handler

| Método | Disparado quando |
|--------|----------------|
| `onMessage` | qualquer mensagem (texto, foto, documento, ...) |
| `onCallback_query` | um botão de teclado inline é pressionado |
| `onCommand` | uma mensagem `/slash_command` |
| `before` | antes de cada update (de qualquer tipo) |
| `after` | depois de cada update |

Implemente apenas os handlers de que precisar.

## Filtragem por tipo de chat

```php
#[ChatType(['private'])]                   // private chats only
#[ChatType(['group', 'supergroup'])]       // groups only
#[ChatType(['channel'])]                   // channel posts
```

O atributo mapeia os tipos de chat do Telegram, resolvidos pelo SDK (`$Telegram->chatType()`).

## Ordenação e curto-circuito

Os plugins rodam em ordem de prioridade para cada handler. Qualquer plugin pode interromper a cadeia simplesmente *não* chamando a lógica seguinte — mas a ferramenta mais adequada para regras transversais de "rejeição" (banimentos, limites de taxa) é o **middleware**, que roda antes do despacho de plugins para cada update. Veja [features.md](features.md).

## Regras de descoberta

- Diretório: `PLUGINS_DIR` no `.env` (padrão `/Plugin/`, relativo à base do projeto; caminhos absolutos são suportados).
- Os arquivos são varridos recursivamente; o nome da classe deve corresponder ao nome do arquivo, estilo PSR-4 (`Plugin/Admin/Ban.php` → classe `Ban` no namespace `…\Plugin\Admin`).
- Os metadados dos plugins (o conjunto descoberto) são armazenados em cache; durante o desenvolvimento defina `PLUGIN_CACHE_ENABLED=false` ou use `PLUGINS_RELOAD_INTERVAL` para capturar novos arquivos sem despejar caches manualmente.
- Verifique o que o framework enxerga: `php bin/tgbase plugins` — mostra prioridade, tipos de chat e handlers registrados por plugin.

## Um exemplo completo

[examples/HelloBot.php](../../examples/HelloBot.php) demonstra os padrões comuns em um único arquivo:

- roteamento dos comandos `/hello` e `/status`
- construção de um teclado inline com o builder fluente `Keyboard`
- estado por chat com `SessionManager`
- chamadas seguras à API com `tg_retryable()` (lança `RetryableException` em 429/5xx → fila de retentativa automática)
- responder e agir sobre callback queries

Para experimentá-lo: copie o arquivo para `Plugin/`, mude o namespace para `alirezax5\TelegramBase\Plugin` e reinicie o bot.

## Scaffolding

```bash
php bin/tgbase make:plugin users/register --type=private
php bin/tgbase make:plugin admin/ban      --type=group
```

Os segmentos do caminho viram namespaces PSR-4 (`users/register` → `Plugin\Users\Register`).
