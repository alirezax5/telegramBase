# Mini Apps — валидация `initData`

Фронтенд мини-приложения получает от Telegram **подписанную** строку идентичности — `Telegram.WebApp.initData`, которая доказывает, какой именно пользователь Telegram открыл страницу. Бэкенд обязан проверять подпись перед выдачей любых данных пользователя.

- Фреймворк: `alirezax5\TelegramBase\App\MiniApp\InitData`
- Проекты только с библиотекой (SDK): `telegramBotApiPhp\WebApp\InitData`

> **Никогда не доверяйте `initDataUnsafe`** — те же поля, но без подписи; проверить против токена бота можно только подписанную `initData`. Трафик мини-приложения **не проходит** через вебхук — браузер пользователя обращается к вашему домену напрямую.

## Использование на бэкенде

```php
use alirezax5\TelegramBase\App\MiniApp\InitData;

$raw = $_POST['initData'] ?? null;
if (!is_string($raw) || $raw === '') {
    $json = json_decode((string) file_get_contents('php://input'), true);
    $raw  = is_array($json) ? (string) ($json['initData'] ?? '') : '';
}

// токен берётся автоматически из BOT_TOKEN; для нескольких ботов передайте явно:
$res = InitData::validate((string) $raw, null, ['max_age' => 3600]);

if (!$res['ok']) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => $res['error']]);
    exit;
}

$user       = $res['user'];        // ['id' => ..., 'first_name' => ...]
$startParam = $res['start_param']; // из ?startapp=
```

## API

| Метод | Назначение |
|---|---|
| `validate()` | Полный результат: `ok`, `user`, `auth_date`, `start_param`, `fields`, `hash` |
| `valid()` | Только boolean |
| `user()` | Массив пользователя или `null` |
| `build()` | Генерация подписанной initData (только для тестов и разработки) |

Опции: `max_age` — максимальный возраст `auth_date` в секундах (по умолчанию `86400`, `0` — не проверять). Коды ошибок: `empty_init_data`, `bot_token_missing`, `malformed_init_data`, `hash_missing`, `bad_signature`, `auth_date_missing`, `auth_date_expired`.

## Чек-лист безопасности

- Проверяйте на сервере **каждый** привилегированный запрос; держите `max_age` коротким (`3600` или меньше).
- Сравнение через `hash_equals` (constant-time) — никогда не используйте `===`.
- Не пишите `initData` и токены ботов в логи.
- Алгоритм: `secret = HMAC_SHA256("WebAppData", token)`, затем `HMAC_SHA256(secret, data_check_string)` — полное руководство: [mini-apps.md](mini-apps.md) (на английском).
