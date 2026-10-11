# Архитектура — как части складываются в целое

## Последовательность загрузки

Каждая точка входа (CLI или веб) создаёт один объект: `Core`.

```php
$core = new Core(CoreMode::FULL);   // FULL = boot plugins too
$core->run();
```

Конструктор запускает цепочку загрузки в следующем порядке:

| Шаг | Класс | Что происходит |
|------|-------|----------------|
| 1 | `Bootstrap::boot()` | Загружает `.env` (через `EnvHandler`), валидирует окружение (`EnvironmentValidator`), подготавливает пути (`Paths`, `AppData/`), подключает кэш |
| 2 | `BotManager::getInstance()->get()` | Создаёт экземпляр SDK `Telegram` из `BOT_TOKEN` (синглтон — все компоненты используют один общий экземпляр) |
| 3 | `PluginsBootstrap::boot()` *(только FULL)* | Сканирует `PLUGINS_DIR`, инстанцирует классы плагинов, сортирует по приоритету, строит реестр `PluginHandler` |

`CoreMode::UPDATES_ONLY` (используется в `queue.php`) пропускает шаг 3 — фетчер лишь отправляет обновления в очередь, сам он плагины никогда не диспетчеризует.

## Поток обновления

```
                 ┌──────────────────────────────────────────────┐
                 │                  Core::run()                 │
                 └──────────────────────────────────────────────┘
                                    │
              route by BOT_MODE + SAPI (CLI vs HTTP) — see bot-modes.md
                                    │
        ┌───────────────┬───────────┴──────────┬─────────────────┐
        ▼               ▼                      ▼                 ▼
   PollingLoop    WebhookDispatcher        QueueWorker       CronManager
   (getUpdates    (validate + dispatch     (pop from queue   (cron slots →
    in a loop)     one webhook payload)     + process)        poll/worker)
        │               │                      │
        └───────────────┴──────────┬───────────┘
                                   ▼
                              Processor::handle($update)
                                   │
                     normalize to object (shape-preserving —
                     list arrays like photo sizes stay arrays)
                                   │
                                   ▼
                       MiddlewarePipeline (optional)
                                   │
                                   ▼
                     PluginHandler::runAll($update, $telegram)
```

Ключевые моменты:

- **Один `Processor` на каждый этап конвейера.** Пути polling, webhook и queue каждый строят свой собственный экземпляр; при этом все они подключают один и тот же конвейер промежуточного ПО (мидлварь), зарегистрированный на `Core`.
- **Нормализация с сохранением формы.** Обновления, приходящие в виде массивов (от драйверов очереди), рекурсивно преобразуются в объекты — *списковые* массивы (размеры фото, entities, строки inline-клавиатуры) остаются массивами, ассоциативные отображения становятся объектами. Это важно, потому что Telegram моделирует поля вроде `photo` как массивы, а плагины выполняют по ним `foreach`.
- **Сбои внутри `handle()`** перехватываются, логируются с контекстом обновления, а — если ошибка выглядит временной (`429`, `5xx`, timeout, ошибка cURL) — отправляются в `RetryQueue` с экспоненциальной задержкой.

## Карта компонентов `App/`

| Каталог | Зона ответственности | Ключевые классы |
|---------|----------------------|-----------------|
| `Attributes/` | PHP 8 attributes | `ChatType` (фильтрует плагины по private/group/channel) |
| `Bootstrap/` | Цепочка загрузки | `Bootstrap`, `PluginsBootstrap`, `QueueBootstrap` |
| `Bot/` | Экземпляр SDK | `BotManager` (синглтон), `BotConfig` |
| `Button/` | Клавиатуры | `Keyboard` (флюент-билдер), `ButtonManager` (i18n-подписи) |
| `Cache/` | Кэширование | `CacheManager` — драйверы array / file / redis / memcached / database |
| `Cli/` | CLI-инструментарий | `Console`, `Maker` (скаффолдинг), `Migrate`, `Doctor`, `PluginsList`, `RetryDrain` |
| `Config/` | Типизированный конфиг | `Config` (реестр), `AppConfig`, `SessionConfig`, ... |
| `Connection/` | Брокеры | `ConnectionManager` (Redis / Memcached / RabbitMQ, синглтоны с проверками живости) |
| `Core.php` | Входная точка | Маршрутизация режимов, регистрация middleware, `run()`, `runFetchQueueUpdate()` |
| `Cron/` | Режимы cron | `CronManager`, `CronWorker`, `WorkerLocker` (слоты с файловой блокировкой) |
| `Database/` | Персистентность | `DatabaseManager` (Eloquent), `MigrationRunner` |
| `Enum/` | Перечисления | `CoreMode` (FULL / UPDATES_ONLY) |
| `Environment/` | Окружение | `EnvHandler` (типизированные геттеры), `EnvironmentValidator` (валидация при загрузке) |
| `Language/` | i18n | Загрузчик `Language` + хелпер `__()` |
| `Logger/` | Логирование | `LogHandler` (обёртка Monolog), `LoggerConfig` (защита от path traversal) |
| `Middleware/` | Конвейер | `MiddlewareInterface`, `MiddlewarePipeline`, встроенные: `RateLimitMiddleware`, `AdminGuardMiddleware`, `AuditLogMiddleware` |
| `Plugin/` | Диспетчеризация | `PluginHandler` (обнаружение + запуск), `PluginInterface` (реализуется вашими плагинами) |
| `Queue/` | Очереди | `QueueManager` (фасад драйверов), драйверы в `Drivers/` (json/redis/rabbitmq/memcached), `RetryQueue`, `RetryableException`, `QueueWorker` |
| `Scheduler/` | Задачи | `Scheduler` (cron-выражения), `TaskLock` |
| `Session/` | Состояние | `SessionManager::start($chatId)` → `Session` (с опорой на кэш, TTL) |
| `Shared/` | Черновое состояние на обновление | `SharedManagement` (очищается между итерациями) |
| `Storage/` | Файлы состояния | `OffsetStorage` (смещение getUpdates, запись с `LOCK_EX`) |
| `Update/` | Поток обновлений | `Fetcher`, `PollingLoop`, `Processor`, `WebhookDispatcher`, `WebhookResponse` |

## Три вида долгоживущих процессов

1. **Polling-воркер** (`bot.php`, режим `update_direct`) — цикл `getUpdates` → `Processor::handle()` → продвижение смещения. Самоперезапускается каждые `POLLING_RESTART_INTERVAL` батчей, чтобы память оставалась в ограниченных пределах; запускайте его под супервизором.
2. **Webhook-воркер** (`webhook.php`, FPM/Apache) — не хранит состояния между запросами; Telegram нужен лишь своевременный код статуса. Тяжёлая работа должна уходить в очередь (режим `webhook_queue`).
3. **Воркеры очереди** (`queue.php` продюсер + `bot.php` консюмер, режимы `*_queue`) — фетчер отправляет, воркеры забирают. Процессов-консюмеров может быть сколько угодно; брокер распределяет обновления между ними.

## Где живёт состояние

| Состояние | Местоположение | Примечания |
|-----------|----------------|------------|
| Смещение getUpdates | `AppData/lastupdate.txt` | `OffsetStorage`, атомарная запись; затрагивается только режимами polling |
| Полезная нагрузка очереди | брокер (или `AppData/queue/` для json-драйвера) | |
| Элементы повторных попыток | `AppData/retry/` или Redis (согласно `RETRY_QUEUE_DRIVER`) | |
| Сессии | драйвер кэша (согласно `CACHE_DRIVER`) | TTL через `SESSION_TTL` |
| Логи | `logs/` (согласно `LOG_DIR`) | path traversal отклоняется валидацией конфигурации |

`AppData/` существует только во время выполнения и занесён в gitignore — чтобы воссоздать его, достаточно просто запустить бота.
