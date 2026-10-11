# 架构 — 各个部件如何组装在一起

## 启动序列

每个入口点（CLI 或 Web）都构造一个对象：`Core`。

```php
$core = new Core(CoreMode::FULL);   // FULL = boot plugins too
$core->run();
```

构造函数按顺序运行启动链：

| 步骤 | 类 | 发生的事情 |
|------|-------|--------------|
| 1 | `Bootstrap::boot()` | 加载 `.env`（通过 `EnvHandler`），校验环境（`EnvironmentValidator`），准备路径（`Paths`、`AppData/`），连接缓存 |
| 2 | `BotManager::getInstance()->get()` | 用 `BOT_TOKEN` 创建 `Telegram` SDK 实例（单例——所有组件共享同一个实例） |
| 3 | `PluginsBootstrap::boot()` *(仅 FULL)* | 扫描 `PLUGINS_DIR`，实例化插件类，按优先级排序，构建 `PluginHandler` 注册表 |

`CoreMode::UPDATES_ONLY`（由 `queue.php` 使用）会跳过第 3 步——fetcher 只把更新推入队列，它自己从不分发插件。

## 更新流转

```
                 ┌──────────────────────────────────────────────┐
                 │                  Core::run()                 │
                 └──────────────────────────────────────────────┘
                                    │
              route by BOT_MODE + SAPI (CLI vs HTTP) — see bot-modes.md
                                    │
        ┌───────────────┬───────────┴──────────┬─────────────────┐
        ▼               ▼                      ▼                 ▼
   PollingLoop    WebhookDispatcher        QueueWorker       CronManager
   (getUpdates    (validate + dispatch     (pop from queue   (cron slots →
    in a loop)     one webhook payload)     + process)        poll/worker)
        │               │                      │
        └───────────────┴──────────┬───────────┘
                                   ▼
                              Processor::handle($update)
                                   │
                     normalize to object (shape-preserving —
                     list arrays like photo sizes stay arrays)
                                   │
                                   ▼
                       MiddlewarePipeline (optional)
                                   │
                                   ▼
                     PluginHandler::runAll($update, $telegram)
```

要点：

- **每个管道阶段一个 `Processor`。** 轮询、Webhook 和队列路径各自构建一个；它们全部挂载你在 `Core` 上注册的同一个中间件管道。
- **保持形状的规范化。** 以数组形式到达的更新（来自队列驱动）会被递归转换为对象——*列表*数组（照片尺寸、entities、内联键盘行）保持为数组，关联映射变为对象。这很重要，因为 Telegram 将 `photo` 等字段建模为数组，而插件会对它们做 `foreach`。
- **`handle()` 内部的失败**会被捕获、带更新上下文记录日志，并且——当错误看起来是暂时性的（`429`、`5xx`、超时、curl 错误）时——以指数退避推入 `RetryQueue`。

## `App/` 的组件地图

| 目录 | 职责 | 关键类 |
|-----------|---------|-------------|
| `Attributes/` | PHP 8 注解 | `ChatType`（按 private/group/channel 过滤插件） |
| `Bootstrap/` | 启动链 | `Bootstrap`、`PluginsBootstrap`、`QueueBootstrap` |
| `Bot/` | SDK 实例 | `BotManager`（单例）、`BotConfig` |
| `Button/` | 键盘 | `Keyboard`（流式构建器）、`ButtonManager`（i18n 标签） |
| `Cache/` | 缓存 | `CacheManager` — array / file / redis / memcached / database 驱动 |
| `Cli/` | CLI 工具 | `Console`、`Maker`（脚手架）、`Migrate`、`Doctor`、`PluginsList`、`RetryDrain` |
| `Config/` | 类型化配置 | `Config`（注册表）、`AppConfig`、`SessionConfig`、... |
| `Connection/` | 中间件代理 | `ConnectionManager`（Redis / Memcached / RabbitMQ，带存活检查的单例） |
| `Core.php` | 入口 | 模式路由、中间件注册、`run()`、`runFetchQueueUpdate()` |
| `Cron/` | Cron 模式 | `CronManager`、`CronWorker`、`WorkerLocker`（文件锁槽位） |
| `Database/` | 持久化 | `DatabaseManager`（Eloquent）、`MigrationRunner` |
| `Enum/` | 枚举 | `CoreMode`（FULL / UPDATES_ONLY） |
| `Environment/` | 环境变量 | `EnvHandler`（类型化 getter）、`EnvironmentValidator`（启动时校验） |
| `Language/` | i18n | `Language` 加载器 + `__()` 辅助函数 |
| `Logger/` | 日志 | `LogHandler`（Monolog 封装）、`LoggerConfig`（防路径穿越） |
| `Middleware/` | 管道 | `MiddlewareInterface`、`MiddlewarePipeline`，内置：`RateLimitMiddleware`、`AdminGuardMiddleware`、`AuditLogMiddleware` |
| `Plugin/` | 分发 | `PluginHandler`（发现 + 运行）、`PluginInterface`（你的插件实现它） |
| `Queue/` | 队列 | `QueueManager`（驱动门面）、`Drivers/` 中的驱动（json/redis/rabbitmq/memcached）、`RetryQueue`、`RetryableException`、`QueueWorker` |
| `Scheduler/` | 计划任务 | `Scheduler`（cron 表达式）、`TaskLock` |
| `Session/` | 状态 | `SessionManager::start($chatId)` → `Session`（基于缓存，带 TTL） |
| `Shared/` | 按更新暂存 | `SharedManagement`（在迭代之间清空） |
| `Storage/` | 状态文件 | `OffsetStorage`（getUpdates 偏移量，`LOCK_EX` 写入） |
| `Update/` | 更新流转 | `Fetcher`、`PollingLoop`、`Processor`、`WebhookDispatcher`、`WebhookResponse` |

## 三种长驻进程形态

1. **轮询工作进程**（`bot.php`，模式 `update_direct`）— 循环执行 `getUpdates` → `Processor::handle()` → 推进偏移量。每处理 `POLLING_RESTART_INTERVAL` 批就自我重启一次以保持内存有界；请在监督进程（supervisor）下运行它。
2. **Webhook 工作进程**（`webhook.php`，FPM/Apache）— 每个请求无状态；Telegram 只需要一个及时的状态码。繁重的工作应放入队列（模式 `webhook_queue`）。
3. **队列工作进程**（`queue.php` 生产者 + `bot.php` 消费者，模式 `*_queue`）— fetcher 推入，工作进程弹出。可以有任意数量的消费者进程；由中间件代理在它们之间分摊更新。

## 状态存放的位置

| 状态 | 位置 | 说明 |
|-------|----------|-------|
| getUpdates 偏移量 | `AppData/lastupdate.txt` | `OffsetStorage`，原子写入；只有轮询模式会触碰它 |
| 队列载荷 | 中间件代理（json 驱动为 `AppData/queue/`） | |
| 重试条目 | `AppData/retry/` 或 Redis（取决于 `RETRY_QUEUE_DRIVER`） | |
| 会话 | 缓存驱动（取决于 `CACHE_DRIVER`） | TTL 由 `SESSION_TTL` 控制 |
| 日志 | `logs/`（取决于 `LOG_DIR`） | 路径穿越会被配置校验拒绝 |

`AppData/` 仅用于运行时且已被 gitignore——只需运行机器人即可重新创建它。
