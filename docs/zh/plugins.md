# 插件 — 编写你的机器人逻辑

插件是 `Plugin/` 中一个实现 `PluginInterface` 的 PHP 类。没有注册、没有配置文件、没有服务提供者——把文件放进去，框架就会在下一次启动时发现它。

## 结构剖析

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

说明：

- `$message` 是**消息对象**（不是完整更新）—— `$message->text`、`$message->chat->id`、`$message->photo` 等。
- 完整的更新对象（含 `update_id`）可通过 `$Telegram->getInputData()` 获得。
- 没有 `#[ChatType]` 注解的插件会对所有聊天类型运行。

## 处理器方法

| 方法 | 触发时机 |
|--------|----------------|
| `onMessage` | 任何消息（文本、照片、文档等） |
| `onCallback_query` | 内联键盘按钮被按下 |
| `onCommand` | 一条 `/slash_command` 消息 |
| `before` | 每次更新（任何类型）之前 |
| `after` | 每次更新之后 |

只实现你需要的处理器即可。

## 聊天类型过滤

```php
#[ChatType(['private'])]                   // private chats only
#[ChatType(['group', 'supergroup'])]       // groups only
#[ChatType(['channel'])]                   // channel posts
```

该注解映射到 Telegram 的聊天类型，由 SDK 解析（`$Telegram->chatType()`）。

## 排序与短路

插件按优先级顺序运行每个处理器。任何插件都可以通过*不再调用后续逻辑*来停止链——但对于横切的"拒绝"规则（封禁、限流），更干净的工具是**中间件**，它在每次更新的插件分发之前运行。见 [features.md](features.md)。

## 发现规则

- 目录：`.env` 中的 `PLUGINS_DIR`（默认 `/Plugin/`，相对于项目根目录；支持绝对路径）。
- 文件被递归扫描；类名必须与文件名一致，PSR-4 风格（`Plugin/Admin/Ban.php` → 命名空间 `…\Plugin\Admin` 中的类 `Ban`）。
- 插件元数据（被发现的那一组）会被缓存；开发期间设置 `PLUGIN_CACHE_ENABLED=false`，或使用 `PLUGINS_RELOAD_INTERVAL`，即可在不手动清空缓存的情况下纳入新文件。
- 验证框架看到了什么：`php bin/tgbase plugins` —— 显示每个插件的优先级、聊天类型和已注册的处理器。

## 一个完整示例

[examples/HelloBot.php](../../examples/HelloBot.php) 在一个文件中演示了常见模式：

- 路由 `/hello` 和 `/status` 命令
- 用流式的 `Keyboard` 构建器构建内联键盘
- 用 `SessionManager` 维护按聊天状态
- 用 `tg_retryable()` 进行安全 API 调用（在 429/5xx 时抛出 `RetryableException` → 自动进入重试队列）
- 应答并处理回调查询

试用方法：把文件复制到 `Plugin/`，把命名空间改为 `alirezax5\TelegramBase\Plugin`，重启机器人。

## 脚手架

```bash
php bin/tgbase make:plugin users/register --type=private
php bin/tgbase make:plugin admin/ban      --type=group
```

路径段会变成 PSR-4 命名空间（`users/register` → `Plugin\Users\Register`）。
