# 概述 — TelegramBase 是什么

## 一句话版本

TelegramBase 是一个 PHP 框架，它把"与 Telegram Bot API 交互"变成"编写小型插件类"，同时处理好可靠机器人所需的一切：更新获取、Webhook 校验、队列、重试以及进程生命周期管理。

## 它做什么

每个 Telegram 机器人——无论多么简单——都需要同一套骨架：

1. **接收更新。** 要么你向 Telegram 询问"有什么新消息吗？"（通过 `getUpdates` 进行长轮询），要么 Telegram 主动调用你的 HTTPS URL（Webhook）。TelegramBase 两者都支持，只需一个 `.env` 值即可选择。
2. **校验 Webhook 请求。** 任何人都可以向你的 URL POST 伪造的更新。TelegramBase 会在你的代码运行之前检查请求体大小、来源 IP（Telegram 官方子网）、可选的 secret token 以及 JSON 格式的合法性。
3. **将更新路由到你的代码。** 一个 `Processor` 会将原始更新对象规范化，并把它交给每一个过滤器匹配的插件（按聊天类型、优先级顺序），可选地经过一个中间件管道。
4. **在故障中存活。** API 调用可能返回 `429 Too Many Requests`。处理器可能抛出异常。工作进程可能在处理更新途中被杀死。队列中间件可能宕机。TelegramBase 对每种情况都有应对方案：带指数退避的重试队列（遵循 Telegram 的 `retry_after`）、有上限的队列大小、带退避的中间件重连，以及针对每种模式均有明确文档的投递保证。
5. **按需扩展。** 从单个 `php bot.php` 进程开始。当负载变重时，切换到队列管道：一个轻量的 fetcher 将更新推入 Redis/RabbitMQ，由独立的工作进程消费。

TelegramBase 完成所有这些工作，并暴露出一个小巧、朴素、可测试的 API 面。

## 它刻意不做什么

- **它不是机器人 SDK。** 实际的 Telegram API 调用来自 [`alirezax5/telegram-bot-php`](https://github.com/alirezax5/telegram-bot-php)（即注入到你插件中的 `Telegram` 对象）。TelegramBase 是包裹在该 SDK 之外的层。
- **它不替你编写机器人逻辑。** 没有"对话构建器"，也没有管理面板。你编写普通的 PHP 类；框架负责调度并喂给它们更新。
- **它不隐藏 PHP。** 没有自定义 DSL，没有 YAML 机器人定义。只要你懂 PHP 8 和 Telegram Bot API，你就懂 TelegramBase 是如何工作的。

## 设计哲学

- **配置优于代码。** 运行模式、队列后端、缓存驱动、语言——全部通过 `.env` 切换，无需修改类。
- **朴素的组件。** 会话、缓存和锁构建在 Illuminate 之上（`illuminate/cache`、`illuminate/database`）。这些正是 Laravel 在生产环境中使用的组件；TelegramBase 不引入任何奇特的依赖。
- **对故障坦诚。** 每条处理路径都有明确的文档化投递保证（至少一次或至多一次）——这是通过在集成测试中用 SIGKILL 杀死真实工作进程测量得出的，而非凭空猜测。参见 [reliability.md](reliability.md)。
- **大声失败，而非静默失败。** 仅限 CLI 的模式在 Web 入口上会返回带说明的 HTTP 500，而不是静默挂起。队列满时会拒绝并记录新的推入，而不是悄悄吃掉你的磁盘。

## 代码库的结构

```
App/          the framework (one concern per subdirectory)
Plugin/       YOUR plugins (auto-discovered)
Button/       keyboard label definitions
Language/     translation files
Database/     Eloquent models + migrations
bin/          CLI tools (tgbase, scheduler)
docs/         documentation (you are here)
tests/        offline + live test suite
```

`App/` 中的所有内容都通过少数几个入口类提供给你：`Core`（运行模式）、`Processor`（更新分发）、`QueueManager`（队列后端）、`SessionManager`（按聊天状态）、`Keyboard`（流式键盘）、`LogHandler`（日志）。其余部分都是这五个类所需的管道设施。

## 下一步去哪里

- [getting-started.md](getting-started.md) — 安装并运行你的第一个机器人
- [architecture.md](architecture.md) — 各个部件如何组装在一起
- [bot-modes.md](bot-modes.md) — 在六种运行模式之间做选择
