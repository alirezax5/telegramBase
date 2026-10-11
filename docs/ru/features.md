# Возможности — ящик с инструментами

Всё, что описано в этом документе, поставляется вместе с фреймворком и настраивается через `.env` (см. [configuration.md](configuration.md)). Подробные разборы: [caching.md](caching.md) · [sessions.md](sessions.md) · [i18n.md](i18n.md) · [buttons.md](buttons.md) · [m[multi-bot.md](multi-bot.md).

## Сессии

Состояние по чатам, сохраняющееся между сообщениями, на основе вашего драйвера кэша — полное руководство в [sessions.md](sessions.md):

```php
use alirezax5\TelegramBase\App\Session\SessionManager;

$session = SessionManager::start($chatId);

$session->set('step', 'waiting_email');
$step = $session->get('step');            // null when unset
$step = $session->get('step', 'default'); // with fallback

// TTL via SESSION_TTL (default 3600s); state auto-expires after silence.
```

Типичное применение: многошаговые сценарии (мастера форм, «нажмите 1 для...»). Сессия привязана к chat id, поэтому у каждого пользователя своя независимая машина состояний.

## Конструктор клавиатур

Флюент-построение inline- и reply-клавиатур — полное руководство в [buttons.md](buttons.md) (включая меню, задаваемые файлами, через `ButtonManager`/`btn()`):

```php
use alirezax5\TelegramBase\App\Button\Keyboard;

// Inline keyboard — two rows
$kb = Keyboard::inline()
    ->button('Help', 'btn_help')
    ->button('Settings', 'btn_settings')
    ->row()                       // next buttons go on a new row
    ->button('Close', 'btn_close')
    ->toArray();

$Telegram->sendMessage($chatId, 'Choose:', ['reply_markup' => $kb]);

// Reply keyboard — replaces the user's virtual keyboard
$reply = Keyboard::reply()
    ->button('Option A')
    ->button('Option B')
    ->resize()      // fit text
    ->oneTime()     // hide after first tap
    ->toArray();
```

Подписи кнопок могут браться из файлов переводов через определения в `Button/` (`BUTTONS_DIR`, `BUTTONS_FILE`).

## Промежуточное ПО (мидлварь)

Конвейер, выполняющийся **до диспетчеризации плагинов** для каждого обновления — будь то polling, вебхук или очередь. Регистрируйте на экземпляре `Core` до `run()`:

```php
$core = new Core();
$core->middleware()->add(new RateLimitMiddleware(10, 60)); // 10 msgs / 60 s per chat
$core->middleware()->add(new AdminGuardMiddleware());      // only ADMINS_WHITELIST ids pass
$core->run();
```

Мидлвари выполняются в порядке регистрации (модель «луковицы»). Каждая может **пропустить дальше** (вызвать `$next()`) или **коротко замкнуть** (вернуть управление, не вызывая `$next()` — обновление отклонено). Поддерживается регистрация строкой класса с ленивым инстанцированием.

| Встроенная | Назначение |
|------------|------------|
| `RateLimitMiddleware(max, window)` | лимит запросов со скользящим окном по chat id |
| `AdminGuardMiddleware` | ограничивает обновления идентификаторами из `ADMINS_WHITELIST` |
| `AuditLogMiddleware` | логирует каждое обновление с латентностью |

Пользовательское middleware:

```php
class MyMiddleware implements MiddlewareInterface {
    public function handle(object $update, Telegram $tg, callable $next): mixed {
        // pre-process
        $result = $next();
        // post-process
        return $result;
    }
}
```

## Кэширование

`CacheManager` предоставляет один API над четырьмя драйверами (`CACHE_DRIVER`): `array` (на процесс), `file`, `redis`, `memcached`. Сессии, метаданные обнаружения плагинов, переводы и подписи кнопок — всё это работает поверх него; смена драйвера меняет историю персистентности всего фреймворка без изменения кода. Полное руководство, включая верифицированную кросс-процессную матрицу и охват `flush()` по каждому драйверу: [caching.md](caching.md).

## i18n

Файлы переводов лежат в `Language/` (по умолчанию `fa`). Используйте хелпер `__()` в плагинах — полное руководство в [i18n.md](i18n.md):

```php
$Telegram->sendMessage($chatId, __('welcome_message'));
```

Файлы языков — PHP-массивы (создайте заготовку командой `php bin/tgbase make:language en`). Кэшируются с `LANG_CACHE_TTL`. ⚠️ `LANG_DRIVER` должен соответствовать формату ваших файлов (`php` для поставляемых файлов — см. [i18n.md](i18n.md)).

## Мульти-бот

Запускайте несколько ботов из одного процесса: реестр `BotManager` + env `BOT_EXTRA_BOTS` (записи `name:token`) + стек токенов библиотеки. Оба реальных сценария (проверка членства, выполняемая ботом #2, и делегированная отправка от имени бота #2) документированы с живыми доказательствами в [m[multi-bot.md](multi-bot.md).

## Планировщик (cron-задачи)

Регистрируйте задачи в `bin/tasks.php`, запускайте каждую минуту из crontab:

```php
// bin/tasks.php
$scheduler->call('prune_old_sessions', '0 3 * * *');   // daily 3am
$scheduler->call('send_summary',      '*/5 * * * *');  // every 5 min
```

```bash
* * * * * cd /path/to/project && php bin/scheduler.php
```

Задачи защищены блокировками (`SCHEDULER_LOCK_*`), поэтому перекрывающиеся тики не выполнят их дважды. Та же точка входа обслуживает режимы бота `cronjob_*`: `cronjob_update` делает один polling-фетч за тик; `cronjob_queue` запускает воркер очереди до `CRON_MAX_TIME` секунд в рамках `CRON_WORKER` слотов с файловой блокировкой.

## База данных и миграции

Необязательная интеграция с Eloquent (`DATABASE_ENABLE=true` + учётные данные в `Database/Connections.php`):

```bash
php bin/tgbase make:migration create_users_table
# → Database/Migrations/2026_08_05_203306_create_users_table.php

php bin/tgbase migrate            # run pending
php bin/tgbase migrate:status     # what's applied
php bin/tgbase migrate:rollback   # undo last batch
```

```php
use alirezax5\TelegramBase\App\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration {
    public function up(\Illuminate\Database\Schema\Builder $schema): void {
        $schema->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }
    public function down(\Illuminate\Database\Schema\Builder $schema): void {
        $schema->dropIfExists('users');
    }
};
```

Команды миграции никогда не требуют `BOT_TOKEN` — валидатор пропускает для них проверки, связанные с ботом (`TGBASE_SKIP_BOT_VALIDATION`).

## CLI-команды

```bash
php bin/tgbase list
# Scaffolding
make:plugin <path>          # --type=private|group|both
make:middleware <name>
make:session <name>
make:language <code>
make:button <code>
make:migration <name>
# Database
migrate / migrate:rollback / migrate:status
# Diagnostics
doctor [--telegram]         # local checks; --telegram adds one getMe call
plugins                     # discovered plugins + handlers
retry:drain                 # drain due retry-queue items on demand
```

Пример вывода `doctor`:

```
PHP                 OK      8.4.24
Extensions          OK      curl, json, mbstring (optional: pdo_sqlite)
Configuration       OK      .env readable
Storage             OK      AppData/ + subdirs writable
Logs                OK      /path/to/logs
BOT_TOKEN           OK      format valid
Telegram API        SKIP    use --telegram to test getMe
Plugins             OK      2 plugin file(s) discovered

RESULT: OK
```

Код выхода `0` = все проверки пройдены, `1` = минимум один сбой.
