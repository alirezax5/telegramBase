# 功能特性 — 工具箱

本文档中的所有内容都随框架提供，并通过 `.env` 配置（见 [configuration.md](configuration.md)）。深入指南：[caching.md](caching.md) · [sessions.md](sessions.md) · [i18n.md](i18n.md) · [buttons.md](buttons.md) · [m[multi-bot.md](multi-bot.md).

## 会话

在消息之间存续的按聊天状态，由你的缓存驱动支撑——完整指南见 [sessions.md](sessions.md)：

```php
use alirezax5\TelegramBase\App\Session\SessionManager;

$session = SessionManager::start($chatId);

$session->set('step', 'waiting_email');
$step = $session->get('step');            // null when unset
$step = $session->get('step', 'default'); // with fallback

// TTL via SESSION_TTL (default 3600s); state auto-expires after silence.
```

典型用途：多步骤流程（表单向导、"按 1 继续……"）。会话以聊天 id 为键，因此每个用户都有独立的状态机。

## 键盘构建器

内联键盘与回复键盘的流式构建——完整指南见 [buttons.md](buttons.md)（含通过 `ButtonManager`/`btn()` 使用文件定义的菜单）：

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

按钮标签可以通过 `Button/` 定义来自翻译文件（`BUTTONS_DIR`、`BUTTONS_FILE`）。

## 中间件

在**插件分发之前**为每次更新运行的管道——轮询、Webhook、队列一视同仁。在 `run()` 之前注册到 `Core` 实例上：

```php
$core = new Core();
$core->middleware()->add(new RateLimitMiddleware(10, 60)); // 10 msgs / 60 s per chat
$core->middleware()->add(new AdminGuardMiddleware());      // only ADMINS_WHITELIST ids pass
$core->run();
```

中间件按注册顺序执行（洋葱模型）。每个中间件可以**放行**（调用 `$next()`）或**短路**（不调用 `$next()` 直接返回——该更新被拒绝）。支持类名字符串注册，并延迟实例化。

| 内置 | 用途 |
|----------|---------|
| `RateLimitMiddleware(max, window)` | 按聊天 id 的滑动窗口限流 |
| `AdminGuardMiddleware` | 将更新限制为 `ADMINS_WHITELIST` |
| `AuditLogMiddleware` | 带延迟记录每次更新 |

自定义中间件：

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

## 缓存

`CacheManager` 在四个驱动（`CACHE_DRIVER`）之上提供统一的 API：`array`（按进程）、`file`、`redis`、`memcached`。会话、插件发现元数据、翻译和按钮标签都建立在它之上——切换驱动即改变整个框架的持久化方式，无需改代码。完整指南（含已验证的跨进程矩阵和各驱动的 `flush()` 覆盖范围）：[caching.md](caching.md)。

## i18n

翻译文件位于 `Language/`（默认 `fa`）。在插件中使用 `__()` 辅助函数——完整指南见 [i18n.md](i18n.md)：

```php
$Telegram->sendMessage($chatId, __('welcome_message'));
```

语言文件是 PHP 数组（用 `php bin/tgbase make:language en` 生成脚手架）。以 `LANG_CACHE_TTL` 缓存。⚠️ `LANG_DRIVER` 必须与你的文件格式匹配（随附文件为 `php` —— 见 [i18n.md](i18n.md)）。

## 多机器人

从一个进程运行多个机器人：`BotManager` 注册表 + `BOT_EXTRA_BOTS` 环境变量（`name:token` 条目）+ 库的 token 栈。两个真实场景（由 2 号机器人执行成员检查、以 2 号机器人身份委托发送）均在 [m[multi-bot.md](multi-bot.md) 中附实测证据记录。

## 调度器（cron 任务）

在 `bin/tasks.php` 中注册任务，由 crontab 每分钟运行：

```php
// bin/tasks.php
$scheduler->call('prune_old_sessions', '0 3 * * *');   // daily 3am
$scheduler->call('send_summary',      '*/5 * * * *');  // every 5 min
```

```bash
* * * * * cd /path/to/project && php bin/scheduler.php
```

任务有锁保护（`SCHEDULER_LOCK_*`），重叠的 tick 不会重复运行。同一入口也驱动 `cronjob_*` 机器人模式：`cronjob_update` 每个 tick 做一次轮询抓取；`cronjob_queue` 在 `CRON_WORKER` 个文件锁槽位上将队列工作进程运行至多 `CRON_MAX_TIME` 秒。

## 数据库与迁移

可选的 Eloquent 集成（`DATABASE_ENABLE=true` + 凭据在 `Database/Connections.php`）：

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

migrate 命令从不要求 `BOT_TOKEN` —— 校验器会为它们跳过机器人相关的检查（`TGBASE_SKIP_BOT_VALIDATION`）。

## CLI 命令

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

`doctor` 输出示例：

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

退出码 `0` = 全部检查通过，`1` = 至少一项失败。
