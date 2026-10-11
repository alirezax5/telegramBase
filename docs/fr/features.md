# Fonctionnalités — la boîte à outils

Tout ce qui figure dans ce document est livré avec le framework et se configure via `.env` (voir [configuration.md](configuration.md)). Approfondissements : [caching.md](caching.md) · [sessions.md](sessions.md) · [i18n.md](i18n.md) · [buttons.md](buttons.md) · [m[multi-bot.md](multi-bot.md).

## Sessions

Un état par chat qui survit entre les messages, adossé à votre driver de cache — guide complet dans [sessions.md](sessions.md) :

```php
use alirezax5\TelegramBase\App\Session\SessionManager;

$session = SessionManager::start($chatId);

$session->set('step', 'waiting_email');
$step = $session->get('step');            // null when unset
$step = $session->get('step', 'default'); // with fallback

// TTL via SESSION_TTL (default 3600s); state auto-expires after silence.
```

Usage typique : flux multi-étapes (assistants de formulaire, « tapez 1 pour... »). La session est indexée par l'id du chat, donc chaque utilisateur dispose d'une machine à états indépendante.

## Constructeur de clavier

Construction fluide des claviers inline et reply — guide complet dans [buttons.md](buttons.md) (y compris les menus définis en fichier via `ButtonManager`/`btn()`) :

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

Les libellés des boutons peuvent venir des fichiers de traduction via les définitions `Button/` (`BUTTONS_DIR`, `BUTTONS_FILE`).

## Middleware

Un pipeline qui s'exécute **avant le dispatch des plugins** pour chaque update — polling, webhook et file d'attente confondus. Enregistrez sur l'instance `Core` avant `run()` :

```php
$core = new Core();
$core->middleware()->add(new RateLimitMiddleware(10, 60)); // 10 msgs / 60 s per chat
$core->middleware()->add(new AdminGuardMiddleware());      // only ADMINS_WHITELIST ids pass
$core->run();
```

Les middleware s'exécutent dans l'ordre d'enregistrement (modèle oignon). Chacun peut **passer au suivant** (appeler `$next()`) ou **court-circuiter** (retourner sans appeler `$next()` — l'update est rejeté). L'enregistrement par chaîne de classe est pris en charge et instancié paresseusement.

| Intégré | Rôle |
|----------|---------|
| `RateLimitMiddleware(max, window)` | limite de débit à fenêtre glissante par id de chat |
| `AdminGuardMiddleware` | restreint les updates à `ADMINS_WHITELIST` |
| `AuditLogMiddleware` | journalise chaque update avec sa latence |

Middleware personnalisé :

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

`CacheManager` expose une seule API par-dessus quatre drivers (`CACHE_DRIVER`) : `array` (par processus), `file`, `redis`, `memcached`. Les sessions, les métadonnées de découverte des plugins, les traductions et les libellés de boutons s'appuient tous dessus — changer de driver change le mode de persistance de tout le framework sans modifier le code. Guide complet, y compris la matrice inter-processus vérifiée et la portée du `flush()` par driver : [caching.md](caching.md).

## i18n

Les fichiers de traduction se trouvent dans `Language/` (par défaut `fa`). Utilisez le helper `__()` dans les plugins — guide complet dans [i18n.md](i18n.md) :

```php
$Telegram->sendMessage($chatId, __('welcome_message'));
```

Les fichiers de langue sont des tableaux PHP (générez-en un avec `php bin/tgbase make:language en`). Mis en cache avec `LANG_CACHE_TTL`. ⚠️ `LANG_DRIVER` doit correspondre au format de vos fichiers (`php` pour les fichiers fournis — voir [i18n.md](i18n.md)).

## Multi-bot

Exécutez plusieurs bots depuis un seul processus : registre `BotManager` + variable d'env `BOT_EXTRA_BOTS` (entrées `name:token`) + pile de tokens de la librairie. Les deux scénarios réels (vérification d'adhésion exécutée par le bot n°2, envoi délégué en tant que bot n°2) sont documentés avec des preuves en conditions réelles dans [m[multi-bot.md](multi-bot.md).

## Planificateur (tâches cron)

Enregistrez les tâches dans `bin/tasks.php`, exécutez chaque minute depuis le crontab :

```php
// bin/tasks.php
$scheduler->call('prune_old_sessions', '0 3 * * *');   // daily 3am
$scheduler->call('send_summary',      '*/5 * * * *');  // every 5 min
```

```bash
* * * * * cd /path/to/project && php bin/scheduler.php
```

Les tâches sont protégées par verrou (`SCHEDULER_LOCK_*`) afin que des ticks qui se chevauchent ne s'exécutent pas en double. Le même point d'entrée pilote les modes de bot `cronjob_*` : `cronjob_update` fait une récupération par polling par tick ; `cronjob_queue` exécute le worker de file pendant jusqu'à `CRON_MAX_TIME` secondes à travers les slots `CRON_WORKER` à verrou fichier.

## Base de données & migrations

Intégration Eloquent optionnelle (`DATABASE_ENABLE=true` + identifiants dans `Database/Connections.php`) :

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

Les commandes de migration n'exigent jamais `BOT_TOKEN` — le validateur ignore les vérifications liées au bot pour elles (`TGBASE_SKIP_BOT_VALIDATION`).

## Commandes CLI

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

Exemple de sortie de `doctor` :

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

Code de sortie `0` = toutes les vérifications passent, `1` = au moins un échec.
