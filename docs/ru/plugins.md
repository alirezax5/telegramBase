# Плагины — логика вашего бота

Плагин — это один PHP-класс в `Plugin/`, реализующий `PluginInterface`. Никакой регистрации, ни конфиг-файла, ни service provider — кладёте файл в папку, и фреймворк обнаруживает его при следующей загрузке.

## Анатомия

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

Примечания:

- `$message` — это **объект сообщения** (не полное обновление) — `$message->text`, `$message->chat->id`, `$message->photo` и т.д.
- Полный объект обновления (с `update_id`) доступен как `$Telegram->getInputData()`.
- Плагин без атрибута `#[ChatType]` выполняется для всех типов чатов.

## Методы-хендлеры

| Метод | Когда срабатывает |
|-------|-------------------|
| `onMessage` | любое сообщение (текст, фото, документ, ...) |
| `onCallback_query` | нажата кнопка inline-клавиатуры |
| `onCommand` | сообщение с `/slash_command` |
| `before` | перед каждым обновлением (любого типа) |
| `after` | после каждого обновления |

Реализуйте только те хендлеры, которые вам нужны.

## Фильтрация по типу чата

```php
#[ChatType(['private'])]                   // private chats only
#[ChatType(['group', 'supergroup'])]       // groups only
#[ChatType(['channel'])]                   // channel posts
```

Атрибут отображается на типы чатов Telegram, которые разрешает SDK (`$Telegram->chatType()`).

## Порядок и короткое замыкание

Плагины выполняются в порядке приоритета для каждого хендлера. Любой плагин может остановить цепочку, просто *не* вызывая дальнейшую логику — но более чистый инструмент для сквозных правил «отклонить» (баны, лимиты запросов) — это **промежуточное ПО (мидлварь)**, которое выполняется до диспетчеризации плагинов для каждого обновления. См. [features.md](features.md).

## Правила обнаружения

- Каталог: `PLUGINS_DIR` в `.env` (по умолчанию `/Plugin/`, относительно базовой папки проекта; поддерживаются абсолютные пути).
- Файлы сканируются рекурсивно; имя класса должно совпадать с именем файла, в стиле PSR-4 (`Plugin/Admin/Ban.php` → класс `Ban` в неймспейсе `…\Plugin\Admin`).
- Метаданные плагинов (обнаруженный набор) кэшируются; во время разработки установите `PLUGIN_CACHE_ENABLED=false` или используйте `PLUGINS_RELOAD_INTERVAL`, чтобы подхватывать новые файлы без ручного сброса кэшей.
- Проверить, что видит фреймворк: `php bin/tgbase plugins` — показывает приоритет, типы чатов и зарегистрированные хендлы для каждого плагина.

## Полный пример

[examples/HelloBot.php](../../examples/HelloBot.php) демонстрирует типовые паттерны в одном файле:

- маршрутизацию команд `/hello` и `/status`
- построение inline-клавиатуры флюент-билдером `Keyboard`
- состояние по чатам с `SessionManager`
- безопасные вызовы API через `tg_retryable()` (выбрасывает `RetryableException` при 429/5xx → автоматическая очередь повторов)
- ответ на callback queries и действия по ним

Чтобы попробовать: скопируйте файл в `Plugin/`, поменяйте неймспейс на `alirezax5\TelegramBase\Plugin`, перезапустите бота.

## Скаффолдинг

```bash
php bin/tgbase make:plugin users/register --type=private
php bin/tgbase make:plugin admin/ban      --type=group
```

Сегменты пути становятся PSR-4 неймспейсами (`users/register` → `Plugin\Users\Register`).
