# Кнопки — конструктор Keyboard и ButtonManager

Два дополняющих друг друга инструмента:

- **`Keyboard`** — флюент-разметка reply-клавиатуры, собираемая кодом, для *динамических* меню (строки зависят от данных времени выполнения).
- **`ButtonManager` / `btn()`** — *задаваемые файлами* фиксированные меню в `Button/`, с переводимыми подписями, кэшируемые, разделяемые между плагинами.

```php
use alirezax5\TelegramBase\App\Button\Keyboard;
```

## Конструктор клавиатур

### Inline-клавиатура (callback-кнопки)

```php
$kb = Keyboard::inline()
    ->button('✅ تایید', 'order_confirm')      // text, callback payload
    ->button('❌ انصراف', 'order_cancel')
    ->row()                                     // next buttons go on a new row
    ->button('راهنما', 'https://example.com', Keyboard::URL)
    ->toArray();

$resp = $tg->sendMessage($chatId, 'سفارش را تایید می‌کنید؟', ['reply_markup' => $kb]);
tg_retryable($resp, 'sendMessage');
```

### Reply-клавиатура (виртуальная клавиатура пользователя)

```php
$kb = Keyboard::reply()
    ->button('🔍 جستجو')
    ->button('💳 خرید')
    ->row()
    ->button('⚙️ تنظیمات')
    ->resize()        // fit text height
    ->oneTime()       // hide after first tap
    // ->selective()  // show only to mentioned users
    ->toArray();
```

Уберите reply-клавиатуру сырой полезной нагрузкой (или определением `btn()`, см. ниже):

```php
$tg->sendMessage($chatId, 'باشه', ['reply_markup' => ['remove_keyboard' => true]]);
```

### API конструктора

| Метод | Применяется к | Эффект |
|---|---|---|
| `inline()` / `reply()` | конструктор | callback-клавиатура / произвольная клавиатура |
| `button($text, $value = '', $type = CALLBACK)` | обеим | добавляет в текущую строку; `$type` — один из `Keyboard::CALLBACK` (`callback_data`), `Keyboard::URL` (`url`), `Keyboard::SWITCH_INLINE`, `Keyboard::SWITCH_INLINE_CURRENT` |
| `row()` | обеим | начинает новую строку |
| `resize($v)` | reply | `resize_keyboard` |
| `oneTime($v)` | reply | `one_time_keyboard` |
| `selective($v)` | reply | `selective` |
| `inputPlaceholder($text)` | inline | `input_field_placeholder` |
| `toArray()` / `toJson()` | обеим | финальное значение `reply_markup` (массив) / JSON-строка |

Пустые строки отбрасываются автоматически; `toArray()` возвращает ровно ту форму, которую Bot API ожидает для `reply_markup`.

### Обработка нажатий

Inline-кнопки приходят как обновления типа `callback_query` — реализуйте `onCallback_query()` в вашем плагине и выполняйте маршрутизацию по `callback_data` (см. [plugins.md](plugins.md)); отвечайте через `$tg->answerCallbackQuery($callbackQueryId)`. Нажатия reply-клавиатуры — это обычные текстовые сообщения.

## ButtonManager — кнопки, задаваемые файлами

Определения лежат в `BUTTONS_DIR` (по умолчанию `/Button`) — главный файл `BUTTONS_FILE` (по умолчанию `btn.php`) плюс любой другой файл `*.php` в этом каталоге, который получает префикс ключа `'<имя файла>.'` (тот же паттерн, что у загрузчика языков).

```php
// Button/btn.php
return [
    "remove" => ['KeyboardRemove' => [], 'remove_keyboard' => true],

    "start" => [
        'keyboard' => [
            [
                ['text' => __('start')],           // translations work inside definitions
                ['text' => __('messages.help')],
            ],
        ],
        'resize_keyboard' => true,
    ],
];
```

```php
use function alirezax5\TelegramBase\App\Button\btn;   // or the global btn() helper

$kb  = btn('start');                       // whole definition → reply_markup
$kb2 = btn('profile', ['ID' => 12]);       // {ID} and {{ID}} placeholders replaced everywhere
$raw = btn('remove');                      // raw Telegram payload (KeyboardRemove)
ButtonManager::has('start');               // exists?
ButtonManager::getAll();                   // every definition
ButtonManager::reload();                   // re-read files, refresh cache
```

Определения — это буквально «что бы ни вернул файл»; большинство представляет собой готовые к отправке массивы `reply_markup`, поэтому `$tg->sendMessage($chatId, 'متن', ['reply_markup' => btn('start')])` — вся история.

## Кэширование (проверено)

Скомпилированные определения кэшируются под ключом `buttons:all` с `BUTTONS_CACHE_TTL` (по умолчанию 300 с), так что файлы читаются один раз за окно TTL, а не на каждое обновление. `BUTTONS_CACHE_ENABLED=false` полностью выключает кэш (определения перечитываются из файлов каждый раз) — этот переключатель подключён от начала до конца начиная с P-CACHE-F002 (раньше он считывался в конфиг, но никогда не учитывался; исправление проверено вживую в обоих состояниях). `ButtonManager::reload()` форсирует обновление во время выполнения.

Ключ кэша / охват следуют [caching.md](caching.md): на `array` кэш локален для процесса; на `file`/`redis`/`memcached` определения разделяются между воркерами.

## Тестовые команды (integration/TestBot)

`/btn` (если присутствует в вашем наборе плагинов) — поставляемый `StartPlugin` показывает `btn('start')` в действии; набор P-CACHE проверил, что `btn('start')` разрешается (`rows=1`) и его полезная нагрузка переживает смену процесса под `buttons:all`.
