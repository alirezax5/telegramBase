# TelegramBase

[English](README.md) · [فارسی](README.fa.md) · **中文** · [Русский](README.ru.md) · [Français](README.fr.md) · [Português](README.pt.md) · [العربية](README.ar.md)

一个用于构建 Telegram 机器人的模块化 PHP 8.1+ 迷你框架——带有插件系统、队列后端、Webhook 校验、会话、中间件、调度器、迁移以及 CLI 脚手架。构建于 Illuminate（Laravel）组件之上，而不是重新发明轮子。

## 这是什么？用一段话说明

TelegramBase 负责每个 Telegram 机器人都需要的*管道工作*——接收更新（轮询或 Webhook）、校验 Webhook 请求、把更新分发给你的代码、重试失败的 API 调用，以及与 Redis 和 RabbitMQ 之类的队列后端通信——因此你只需编写*有趣的部分*：称为**插件**的小型 PHP 类，它们对消息作出反应。把一个类放进 `Plugin/`，框架就会发现并运行它。这就是全部理念。

## 它为什么存在？

"直接对着 API 写"机器人在真实流量到来之前都很简单。到那时你需要回答这样的问题：*如果我的工作进程在处理更新途中崩溃会怎样？Webhook 请求在哪里被校验？如何在不丢失更新的情况下重试 `429 Too Many Requests`？我能把繁重的工作从 HTTP 请求中挪走吗？* TelegramBase 预先回答了所有这些问题：

- **六种运行模式**（`update_direct`、`update_queue`、`webhook_direct`、`webhook_queue`、`cronjob_update`、`cronjob_queue`）——通过改变一个 `.env` 值，即可在轮询与 Webhook、内联与入队处理之间切换。
- **坦率的投递语义** —— 框架经过了端到端的崩溃测试（在管道的每个阶段内做 SIGKILL）。每种模式的保证都有文档：Webhook/直接路径是*至少一次*，RabbitMQ 管道是*至少一次*（处理后确认），破坏性驱动（Redis/JSON/Memcached）是*至多一次*。见 [docs/reliability.md](docs/zh/reliability.md)。
- **防御真实的配置错误** —— 在 Webhook 入口点之后运行仅限 CLI 的模式会被 HTTP 500 拒绝，而不是让你的 Web 工作进程挂在无限轮询循环里（这是我们在生产测试中真实踩到过的 bug）。
- **电池已备齐** —— 会话、键盘构建器、i18n、中间件（限流 / 管理员守卫 / 审计日志）、调度器、Eloquent 迁移、有界队列、遵循 Telegram `retry_after` 的自动重试。

## 环境要求

- PHP 8.1+
- Composer
- 扩展：`json`、`mbstring`、`curl`
- 可选：`redis`、`memcached`、`amqp`（队列/缓存后端）
- 可选：MySQL/MariaDB（数据库、Eloquent 迁移）

## 快速开始

```bash
git clone https://github.com/alirezax5/telegramBase.git
cd telegramBase
composer install
cp .env.example .env
# Edit .env — set BOT_TOKEN (get one from @BotFather)
php bot.php
```

向你的机器人发送 `/start` —— `Plugin/start.php` 中的入门插件会应答。然后编写你自己的插件（见 [docs/plugins.md](docs/zh/plugins.md)）。

## 一条更新如何流转

```
Telegram
   │
   ├─ getUpdates long-poll ──► bot.php ──┐
   │                                     │
   └─ webhook POST ──► webhook.php ──────┤
                                         ▼
                                    Core::run()
                                         │
                       validate (webhook)│mode routing
                                         ▼
                                    Processor
                                         │
                             middleware pipeline
                                         ▼
                              your plugins (Plugin/)
```

## 文档

一切都在 `docs/` 里——从概述开始：

| 文档 | 其中包含什么 |
|----------|---------------|
| [docs/overview.md](docs/zh/overview.md) | 框架是什么、做什么、刻意不做什么 |
| [docs/architecture.md](docs/zh/architecture.md) | 启动序列、更新流转、`App/` 的组件地图 |
| [docs/getting-started.md](docs/zh/getting-started.md) | 安装 → 第一个插件 → 部署，逐步进行 |
| [docs/bot-modes.md](docs/zh/bot-modes.md) | 全部六种 `BOT_MODE`、入口点、该运行哪些进程 |
| [docs/plugins.md](docs/zh/plugins.md) | 编写插件：处理器、优先级、`#[ChatType]`、发现机制 |
| [docs/queue-pipeline.md](docs/zh/queue-pipeline.md) | 队列后端、工作进程、重试队列、确认语义 |
| [docs/webhook.md](docs/zh/webhook.md) | Webhook 搭建、5 层校验、代理/CDN 注意事项 |
| [docs/reliability.md](docs/zh/reliability.md) | 崩溃测试结果：每种模式的投递保证、幂等性指南 |
| [docs/configuration.md](docs/zh/configuration.md) | 完整的 `.env` 参考 |
| [docs/features.md](docs/zh/features.md) | 会话、键盘、中间件、缓存、i18n、调度器、数据库 |
| [docs/caching.md](docs/zh/caching.md) | CacheManager 深入：驱动、TTL、flush 覆盖范围、跨进程矩阵（实测验证） |
| [docs/sessions.md](docs/zh/sessions.md) | 按聊天会话状态：API、多步骤流程、安全说明 |
| [docs/i18n.md](docs/zh/i18n.md) | 翻译：文件布局、`__()` 辅助函数、按用户语言、缓存 |
| [docs/buttons.md](docs/zh/buttons.md) | 键盘构建器 + 文件定义的菜单（`btn()`）、回调处理 |
| [docs/multi-bot.md](docs/zh/multi-bot.md) | 单进程多机器人：`BotManager`、`BOT_EXTRA_BOTS`、token 栈（实测验证） |
| [docs/testing.md](docs/zh/testing.md) | 离线套件、实测 Telegram 测试、冒烟测试、CI |
| [docs/troubleshooting.md](docs/zh/troubleshooting.md) | 常见症状与修复、部署陷阱 |

其他有用的文件：

- [CHANGELOG.md](CHANGELOG.md) —— 每个版本的变化
- [llms.txt](llms.txt) / [llms-ctx.txt](llms-ctx.txt) —— 面向 LLM 的文档索引和单文件上下文
- [.env.example](.env.example) —— 每个配置键及其默认值
- [examples/HelloBot.php](examples/HelloBot.php) —— 一个完整的示例插件

## CLI

```bash
php bin/tgbase list              # all commands
php bin/tgbase make:plugin admin/ban   # scaffold a plugin
php bin/tgbase doctor            # environment diagnostics
php bin/tgbase plugins           # list discovered plugins
php bin/tgbase migrate           # run database migrations
```

## 许可证

MIT
