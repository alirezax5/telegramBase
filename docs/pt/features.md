# Funcionalidades — a caixa de ferramentas

Tudo neste documento vem com o framework e é configurado através do `.env` (veja [configuration.md](configuration.md)). Aprofundamentos: [caching.md](caching.md) · [sessions.md](sessions.md) · [i18n.md](i18n.md) · [buttons.md](buttons.md) · [multi-bot.md](multi-bot.md).

## Sessões

Estado por chat que sobrevive entre mensagens, com suporte do seu driver de cache — guia completo em [sessions.md](sessions.md):

```php
use alirezax5\TelegramBase\App\Session\SessionManager;

$session = SessionManager::start($chatId);

$session->set('step', 'waiting_email');
$step = $session->get('step');            // null when unset
$step = $session->get('step', 'default'); // with fallback

// TTL via SESSION_TTL (default 3600s); state auto-expires after silence.
```

Uso típico: fluxos de múltiplas etapas (assistentes de formulário, "pressione 1 para..."). A sessão é chaveada pelo id do chat, então cada usuário tem uma máquina de estados independente.

## Construtor de teclados

Construção fluente de teclados inline e de resposta — guia completo em [buttons.md](buttons.md) (incl. menus definidos em arquivos via `ButtonManager`/`btn()`):

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

Os rótulos dos botões podem vir dos arquivos de tradução por meio das definições em `Button/` (`BUTTONS_DIR`, `BUTTONS_FILE`).

## Middleware

Um pipeline que roda **antes do despacho de plugins** para cada update — tanto no polling, quanto no webhook e na fila. Registre na instância do `Core` antes do `run()`:

```php
$core = new Core();
$core->middleware()->add(new RateLimitMiddleware(10, 60)); // 10 msgs / 60 s per chat
$core->middleware()->add(new AdminGuardMiddleware());      // only ADMINS_WHITELIST ids pass
$core->run();
```

Os middlewares executam em ordem de registro (modelo onion). Cada um pode **passar adiante** (chamando `$next()`) ou **fazer curto-circuito** (retornar sem chamar `$next()` — o update é rejeitado). Registro por class-string é suportado e instanciado de forma lazy.

| Built-in | Finalidade |
|----------|---------|
| `RateLimitMiddleware(max, window)` | limite de taxa de janela deslizante por chat id |
| `AdminGuardMiddleware` | restringe os updates ao `ADMINS_WHITELIST` |
| `AuditLogMiddleware` | registra cada update com latência |

Middleware customizado:

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

## Cache

O `CacheManager` expõe uma única API sobre quatro drivers (`CACHE_DRIVER`): `array` (por processo), `file`, `redis`, `memcached`. Sessões, metadados de descoberta de plugins, traduções e rótulos de botões todos rodam sobre ele — trocar o driver muda a história de persistência do framework inteiro sem mudanças de código. Guia completo incl. a matriz cross-processo verificada e o alcance do `flush()` por driver: [caching.md](caching.md).

## i18n

Os arquivos de tradução ficam em `Language/` (padrão `fa`). Use o helper `__()` nos plugins — guia completo em [i18n.md](i18n.md):

```php
$Telegram->sendMessage($chatId, __('welcome_message'));
```

Os arquivos de idioma são arrays PHP (crie um com `php bin/tgbase make:language en`). Cacheados com `LANG_CACHE_TTL`. ⚠️ O `LANG_DRIVER` deve corresponder ao formato do seu arquivo (`php` para os arquivos incluídos — veja [i18n.md](i18n.md)).

## Multi-bot

Execute vários bots a partir de um processo: registro do `BotManager` + env `BOT_EXTRA_BOTS` (entradas `name:token`) + a pilha de tokens da biblioteca. Ambos os cenários do mundo real (checagem de membros executada pelo bot #2, envio delegado como bot #2) estão documentados com evidências ao vivo em [multi-bot.md](multi-bot.md).

## Agendador (tarefas cron)

Registre tarefas em `bin/tasks.php`, execute a cada minuto pelo crontab:

```php
// bin/tasks.php
$scheduler->call('prune_old_sessions', '0 3 * * *');   // daily 3am
$scheduler->call('send_summary',      '*/5 * * * *');  // every 5 min
```

```bash
* * * * * cd /path/to/project && php bin/scheduler.php
```

As tarefas são protegidas por locks (`SCHEDULER_LOCK_*`) para que ticks sobrepostos não executem em duplicidade. A mesma entrada comanda os modos de bot `cronjob_*`: o `cronjob_update` faz uma busca de polling por tick; o `cronjob_queue` roda o worker da fila por até `CRON_MAX_TIME` segundos nos slots com file-lock do `CRON_WORKER`.

## Banco de dados e migrações

Integração Eloquent opcional (`DATABASE_ENABLE=true` + credenciais em `Database/Connections.php`):

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

Os comandos de migração nunca exigem `BOT_TOKEN` — o validador pula as checagens relacionadas ao bot para eles (`TGBASE_SKIP_BOT_VALIDATION`).

## Comandos CLI

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

Exemplo de saída do `doctor`:

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

Código de saída `0` = todas as checagens passam, `1` = ao menos uma falha.
