# 机器人模式 — 运行 TelegramBase 机器人的六种方式

`.env` 中的 `BOT_MODE` 是最重要的单一配置值。它决定*更新如何到达*（轮询 vs Webhook）以及*如何被处理*（内联 vs 入队）。

## 各种模式

| 模式 | 更新来源 | 处理方式 | 你要运行的进程 |
|------|--------------|------------|-------------------|
| `update_direct` *(默认)* | `getUpdates` 长轮询 | 内联，同一进程 | 在监督器下运行 `php bot.php` |
| `update_queue` | `getUpdates` 长轮询 | 通过队列异步 | `php queue.php`（fetcher）+ 一个或多个 `php bot.php`（工作进程） |
| `webhook_direct` | Telegram Webhook POST | 内联，在 HTTP 请求内 | 仅 Web 服务器（`webhook.php`） |
| `webhook_queue` | Telegram Webhook POST | 通过队列异步 | Web 服务器（`webhook.php`）+ `php bot.php` 工作进程 |
| `cronjob_update` | `getUpdates`（轮询） | 内联，**自愈式守护进程**——第一个 tick 启动无尽循环，守护进程存活期间后续 tick 均为空操作 | crontab `* * * * * php bin/scheduler.php` |
| `cronjob_queue` | 仅队列（不轮询 Telegram） | 异步批处理——每个 tick 最多消费 `CRON_MAX_TIME`（默认 50 秒），然后退出 | crontab + `CRON_WORKER` 槽位 |

心智模型：

- **`update_*` / `cronjob_*`** 模式自己拉取更新（你的服务器去和 Telegram 通信）。
- **`webhook_*`** 模式接收更新（Telegram 来和你的服务器通信）。
- **`*_direct`** 在更新到达之处就地处理——最简单，对大多数机器人足够了。
- **`*_queue`** 把接收与处理解耦——当处理器很慢（上传、外部 API）或你想水平扩展时使用它。
- **`cronjob_*`** 模式从普通 crontab 运行——不需要监督器、systemd 或 Docker。

## Cron 模式详解

两种 cron 模式共享同一个安全机制：每个 tick 时框架都会尝试获取一个**工作槽位**——对 `data/cron-slots/` 下锁文件的独占 `flock()`（`WorkerLocker`）。如果所有 `CRON_WORKER` 个槽位都忙，该 tick 会记录 `All N worker slots busy` 并在数毫秒内退出——并发 tick 永远不会重复处理任何东西。

### `cronjob_update` — 自愈式守护进程，而非批处理

尽管名字如此，一个 tick 并**不会**运行"一批"。第一个 tick 获取槽位并启动与 `update_direct` 相同的无尽轮询循环（`set_time_limit(0)` + 长轮询），该 tick 永远不结束——这是有意为之：

1. 第 1 个 tick → 守护进程启动，槽位保持锁定。
2. 第 2..N 个 tick → 发现槽位忙，数毫秒内退出（实测约 30–45 毫秒）。这是*正常现象*，不是错误。
3. 守护进程死亡（崩溃、OOM、重启）→ 下一个 cron tick 自动启动一个全新守护进程。**Cron 充当监督器。**

已实测验证：一个守护进程于 09:24:01 启动，内联处理了一条真实消息，跨越多个分钟边界存活，在 SIGTERM 后于一个长轮询周期内优雅关闭（约 12 秒——当前长轮询一返回，停止信号即被尊重），释放锁，紧接着的下一个 tick 重新启动了它。

### `cronjob_queue` — 有界的批处理工作进程

每个 tick 获取一个槽位，最多从队列中消费 `CRON_MAX_TIME` 秒（默认 50），然后以 `Limited worker finished: N updates` 干净退出。此模式下没有任何东西去轮询 Telegram——队列必须由生产者喂入（`webhook_queue`，或你自己运行一次 `queue.php`）。

已实测验证：一条真实消息于 09:22:49 被推入 RabbitMQ，随后被一个已在其 50 秒窗口内的 tick 弹出并分发（插件 `onMessage` 在 09:22:49.6，`Limited worker finished: 1 updates` 在 09:22:51），队列随后清空回 0/0。投递语义继承自队列驱动——RabbitMQ 自 v2.4.0 起为至少一次。

### Cron 配置

| 环境变量 | 默认值 | 含义 |
|-----|---------|---------|
| `CRON_WORKER` | `1` | 并发工作槽位数（`data/cron-slots/` 中的 flock 文件） |
| `CRON_MAX_TIME` | `50` | `cronjob_queue` 的批处理窗口（秒）——保持它小于你的 cron 间隔，以免 tick 重叠 |

## 入口点与 SAPI 守卫

| 文件 | 运行方式 | 用途 |
|------|---------|---------|
| `bot.php` | CLI | 轮询（`update_direct`）、队列消费者（`*_queue`）、cron 回调 |
| `queue.php` | CLI | 队列*生产者*——长轮询 Telegram 并把更新推入队列（`update_queue`） |
| `webhook.php` | HTTP | 接收 Telegram Webhook POST（`webhook_*`） |
| `bin/tgbase` | CLI | 诊断、脚手架、迁移 |
| `bin/scheduler.php` | CLI | cron tick 运行器（`cronjob_*` + 计划任务） |

**重要——SAPI 守卫（v2.4.0）：** HTTP 请求只能服务 Webhook 类工作。如果 `BOT_MODE` 是仅 CLI 的模式时执行了 `webhook.php`，框架会拒绝在你的 Web 工作进程内运行无限循环：

| 在 `webhook.php` 上的 `BOT_MODE` | 行为 |
|------------------------------|-----------|
| `webhook_direct` | 校验 → 内联处理 → `200` |
| `webhook_queue` | 校验 → 入队 → `200` |
| `update_direct` | 校验 → 内联处理 → `200`（会记录一条警告——你想要的可能是 `webhook_direct`） |
| `update_queue`、`cronjob_*` | 返回 **HTTP 500** 并附说明消息——不循环、不挂起 |

背景：在这个守卫存在之前，把 Webhook 指向一个配置为 `update_direct` 的机器人，会在 PHP-FPM 工作进程内静默启动一个无限的 `getUpdates` 循环。Telegram 永远得不到响应（`Read timeout expired` → 无尽重试），FPM 在 `request_terminate_timeout`（502）时杀掉工作进程。这个守卫把那种静默挂起变成响亮、快速的失败。你可以用 `Core::isHttpSapi($sapi)` 直接测试该检测逻辑。

> 经验法则：**CLI 模式从终端运行，Webhook 模式从 Web 运行。** 框架会替你强制执行这条边界。

## 投递语义一览

| 路径 | 保证 | 重复窗口 |
|------|-----------|------------------|
| `webhook_direct` | 至少一次（Telegram 会重试直到收到 2xx） | 在 `handle()` 之后、返回 200 之前崩溃 |
| `update_direct` | 至少一次（只有处理完成后才推进偏移量） | 在产生副作用之后、写入偏移量之前崩溃 |
| `webhook_queue`（生产者侧） | 一旦你收到 200，更新就在中间件代理里了 | 中间件代理故障时的入队重试 |
| 队列消费者——**RabbitMQ** | 至少一次（处理之后确认，v2.4.0） | 处理与确认之间崩溃 |
| 队列消费者——Redis / JSON / Memcached | 至多一次（破坏性弹出——弹出时任务即消失） | 处理中途崩溃会丢失任务 |

完整的崩溃测试证据与幂等性指南：[reliability.md](reliability.md)。

`cronjob_update` 的行为与 `update_direct` 完全相同（在推进偏移量之前内联处理一条消息）；`cronjob_queue` 的行为与其队列驱动的消费者完全相同（RabbitMQ 为至少一次，Redis/JSON/Memcached 为至多一次）。

## 选择模式 — 实用建议

- **小型机器人、面板、原型** → `update_direct`。一个进程，零基础设施。
- **慢速处理器（文件处理、第三方 API）、多实例** → `webhook_queue` 或 `update_queue`，配合 Redis/RabbitMQ。
- **没有 CLI 守护进程的共享主机** → `cronjob_update`（crontab 充当监督器）或 `webhook_direct`。
- **想要恰好一次？** 没人做得到。TelegramBase 提供的是永不丢失更新的*至少一次*路径和永不重复的*至多一次*路径——按用例选择，并让处理器幂等（见 [reliability.md](reliability.md)）。
