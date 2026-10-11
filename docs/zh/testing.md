# 测试 — 离线套件、实测与 CI

## 离线测试套件

框架自带一个零依赖的测试运行器（不需要 PHPUnit）：

```bash
composer install        # vendor/ must exist (the suite boots the framework)
php tests/run.php
```

结构：

| 目录 | 其中包含什么 |
|-----------|------------------|
| `tests/Unit/` | 纯单元测试——env 处理器、配置、队列驱动、偏移量存储、Webhook dispatcher 校验、中间件接线、重试生命周期等（无网络） |
| `tests/Integration/` | 多组件测试（仍然离线） |
| `tests/Live/` | **真实的 Telegram API 测试 —— 仅选择加入**（见下文） |
| `tests/smoke.php` | 独立的引导冒烟测试（坏 token、空 token、坏模式、有效启动） |
| `tests/benchmark.php` | 热路径的微基准测试 |

按名称过滤：

```bash
php tests/run.php --filter=Keyboard
php tests/run.php --filter=Queue
```

## 已知的套件漂移 — 已解决

直到 v2.4.0，离线运行报告的是 **196 通过 / 18 失败 / 12 跳过**。那 18 个失败（`SessionTest` ×10、`BackwardCompatTest` ×4、`MiddlewareWiringTest` ×4）是先前就存在的，并且已通过逐个还原修复再重跑的方式证明与框架修复无关。

根因是在预发布审查（P-REL-B05）中发现的：`tests/run.php` 会播种环境默认值并调用 `Bootstrap::boot()`，但 `EnvironmentBootstrap` 用的是 Dotenv 的 `load()`，它在仓库没有 `.env` 时会**抛出异常**（全新克隆只附带 `.env.example`）。于是启动中止、`Config` 保持未初始化，所有依赖配置的测试全部失败。切换到 `safeLoad()` 之后，套件在全新克隆中无需任何设置即可全绿运行：

```
Pass: 224  Fail: 0  Skip: 12
```

## 实测 Telegram 测试

实测会打到真实的 Bot API，除非显式启用否则一律跳过：

```bash
RUN_TELEGRAM_LIVE_TESTS=1 \
TELEGRAM_BOT_TOKEN="<your-token>" \
TELEGRAM_TEST_CHAT_ID="<your-chat-id>" \
php tests/run.php
```

守则（由测试本身强制执行）：

- 它们发送的每条消息都带 `[telegramBase automated test]` 标签
- 验证之后它们会尽可能删除自己发的消息——并且从不删除不是自己创建的消息
- 包含负面路径：无效聊天、无效 token、模拟 429
- token **只**从环境变量读取

## CI（GitHub Actions）

`.github/workflows/tests.yml` 在每次 push/PR 上运行，横跨 PHP **8.1 – 8.4**：composer 校验、`composer audit`，以及离线套件。

`.github/workflows/live-tests.yml` 是用于实测的**手动**（`workflow_dispatch`）工作流；它从 `TGBASE_LIVE_BOT_TOKEN` 仓库 secret 读取 token，并接受一个 `chat_id` 输入——工作流文件里没有 secret，默认 CI 从不触碰 secret。

## 冒烟测试

```bash
php tests/smoke.php
```

六个用例：坏 token / 空 token / 日志路径穿越尝试 / 日志绝对路径 / 坏模式 / 有效启动。部署后把它当作一个 5 秒的健康检查来用。

## 套件覆盖了什么（以及它为何关系到信任）

[reliability.md](reliability.md) 中的可靠性论断有测试背书，另有崩溃测试过的集成场景（每条管道阶段都做 SIGKILL、真实的代理重新投递检查、带 `getWebhookInfo` 验证的 Webhook 切换测试）。与审计这些论断最相关的套件：

```bash
php tests/run.php --filter=Queue      # driver behaviour (json/redis/rabbitmq contracts)
php tests/run.php --filter=Offset     # offset storage atomicity
php tests/run.php --filter=Retry      # retry queue lifecycle + retry_after
php tests/run.php --filter=Webhook    # the 5 webhook validation layers
php tests/run.php --filter=Security   # env/log hardening rules
```

崩溃测试期间使用的集成工具（真实机器人 + 场景脚本）位于 `integration/TestBot/`——它做什么以及如何改造成你自己的部署，见其 README。
