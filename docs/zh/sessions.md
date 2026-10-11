# 会话 — SessionManager

在消息之间存续的按聊天状态：多步骤表单向导、"按 1 继续……"、购物车、按用户的模式切换。会话通过 [CacheManager](caching.md) 存储，以聊天 id 为键，并带 TTL，空闲的对话会自动过期。

```php
use alirezax5\TelegramBase\App\Session\SessionManager;
```

## API

```php
// Start (or resume) a session — creates one when none exists
$session = SessionManager::start($chatId);

$session->set('step', 'waiting_email');        // persists immediately
$session->setMany(['cart' => ['item1'], 'lang' => 'fa']);
$step = $session->get('step');                  // null when unset
$step = $session->get('step', 'default');       // with fallback
$session->has('step');                          // true even when value is null
$session->all();                                // whole payload as array
$session->forget('cart');                       // remove one key
$session->regenerate();                         // new random session id (after login!)
$session->touch();                              // refresh last-activity
$session->destroy();                            // delete entirely

// Read WITHOUT creating (null when absent — use in read-only paths)
$existing = SessionManager::get($chatId);
SessionManager::has($chatId);
SessionManager::flush($chatId);                 // delete
```

在底层，整个载荷就是一条缓存条目：

| 缓存键 | `SESSION_PREFIX`（默认 `tgbase:session:`）+ 聊天 id |
|---|---|
| TTL | `SESSION_TTL`（默认 3600 秒，下限 60 秒）—— **每次 `set()` 都会重新持久化并重置该窗口** |
| 载荷 | `['id' => sessionId, 'data' => […], 'last_activity' => ts]` |

## 一个多步骤流程

```php
public function onMessage($message, Telegram $tg): void
{
    $text = trim($message->text ?? '');
    $chatId = $tg->chatId();

    $session = SessionManager::start($chatId);

    switch ($session->get('step')) {
        case null:
            if ($text === '/register') {
                $session->set('step', 'ask_email');
                $resp = $tg->sendMessage($chatId, 'ایمیلت را بفرست:');
                tg_retryable($resp, 'sendMessage');
            }
            return;

        case 'ask_email':
            if (!filter_var($text, FILTER_VALIDATE_EMAIL)) {
                $resp = $tg->sendMessage($chatId, 'ایمیل معتبر نیست — دوباره بفرست:');
                tg_retryable($resp, 'sendMessage');
                return;                              // stay on the same step
            }
            $session->setMany(['email' => $text, 'step' => 'done']);
            $resp = $tg->sendMessage($chatId, "ثبت شد: {$text}");
            tg_retryable($resp, 'sendMessage');
            return;
    }
}
```

每个用户都有独立的状态机，因为会话以聊天 id 为键。

## 我需要哪种缓存驱动？

- **轮询守护进程（`update_direct` / `update_queue` 的 fetcher）：** 一个长驻 PHP 进程 —— 即使 `array` 也能让状态在消息之间存活。但重启后状态即消失。
- **Webhook（`webhook_*`）与队列工作进程：** 状态必须跨越进程边界 → `file`、`redis` 或 `memcached`。

已实测验证：在进程 A 写入的一个会话（`sid d5dfbf11…`，step + cart）在全新进程 B 中、于 `file`、`redis` 和 `memcached` 上被完整读回 —— **同一会话 id、载荷完全一致**；在 `array` 上进程 B 什么都看不到（已文档化）。

## 安全说明

- 任何权限变更（登录）之后调用 `regenerate()` 以防会话固定攻击 —— id 是全新的 32 位十六进制随机值。
- 会话本身不携带身份：聊天 id 就是键。在群聊中，每个成员共享该聊天的会话——若要在群组中获得按用户状态，请改用自己的缓存键并以 `$tg->fromId()` 为键。
- 长时间空闲的会话会静默过期（`SESSION_TTL`）；请把流程设计为容忍缺失的会话（`SessionManager::get() === null` → 从头开始）。

## 测试命令（integration/TestBot）

`/session` 转储 id + 键 + 数据 · `/session-set <k> <v>` · `/session-get <k>` · `/session-clear`。这对 set/get 命令已作为 P-CACHE 验证的一部分，通过真实的插件管道在两个独立进程中执行过。
