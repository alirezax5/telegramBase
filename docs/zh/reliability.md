# 可靠性 — 崩溃时究竟会发生什么

这份文档对框架文档而言并不寻常：其中的一切都是**实测的，而非假设的**。在集成测试期间（阶段 3.2–3.3），工作进程在每条管道的每个阶段都被 `SIGKILL` 杀死过——获取期间、入队期间、处理器执行中途、处理器之后确认之前、偏移量写入之前/之后——并记录了每个场景的结果。本页是提炼后的结论，它既驱动框架的设计，也驱动下面的建议。

## 每条路径的投递保证

| 路径 | 保证 | 原因 | 重复窗口 |
|------|-----------|-----|------------------|
| 轮询（`update_direct`） | **至少一次** | `getUpdates` 偏移量只有在 `Processor::handle()` 返回*之后*才持久化 | 产生副作用之后、写入偏移量之前崩溃 |
| Webhook（`webhook_direct`） | **至少一次** | `200` 只有在 `handle()` 返回之后才发送；其余任何情况 Telegram 都会重试 | 产生副作用之后、返回 200 之前崩溃 |
| Webhook → 队列（`webhook_queue`） | **至少一次进入中间件代理** | 只有成功入队后才返回 `200` | 入队与 200 之间代理宕机 |
| 队列消费者——**RabbitMQ**（v2.4.0） | **至少一次** | 处理后确认：未确认的消息由代理重新投递 | 处理之后、确认之前崩溃 |
| 队列消费者——Redis / JSON / Memcached | **至多一次** | 破坏性弹出——消息在弹出时即被移除 | 无（处理中途崩溃会丢失任务） |
| 重试队列 | **至少一次，有界** | 条目带退避地重新分发，直到 `RETRY_MAX_ATTEMPTS` | 与其父路径相同 |

从崩溃测试得出的两点坦率观察：

- **恰好一次并不存在。** TelegramBase 中不存在，任何构建在网络之上的系统中都不存在。你只能在"永不丢失"（至少一次，崩溃时可能重复）与"永不重复"（至多一次，崩溃时可能丢失）之间选择。框架同时提供这两类，并明确标注哪条路径属于哪类。
- **重复窗口窄但真实。** 它们只在恰好最糟的时机崩溃时才打开。用幂等处理器即可关闭它们。

## 让处理器幂等（实用的那 90%）

只有同一条更新被*作用*两次时，至少一次才令人痛苦。一道护栏就能让每个窗口变得无害：

```php
public function onMessage($message, Telegram $tg): void
{
    $update  = $tg->getInputData();
    $uId     = $update->update_id ?? null;

    // Cheap, atomic, per-update marker — works with any cache driver.
    if (!empty(cache()->get("upd:{$uId}"))) {
        return;                       // already processed (redelivery) — skip
    }

    // ... do the actual work ...

    cache()->set("upd:{$uId}", 1, 3600);   // remember for an hour
}
```

尽可能在不可逆副作用*之前*存储标记，并接受"标记与副作用之间崩溃会把重复转化为丢失"这一事实——按动作选择权衡（消息发两次通常比永远没发出去便宜）。

## 按组件划分的崩溃行为

| 组件 | 崩溃场景 | 观察到的行为 |
|-----------|---------------|--------------------|
| 轮询工作进程（`update_direct`） | 批处理中途 `SIGKILL` | 偏移量未变 → 重启后同样的更新被重新投递 → 重新处理（幂等性很重要） |
| 轮询工作进程 | 批次之间 `SIGKILL` | 干净；至多当前这条更新被重新投递 |
| 队列 fetcher（`queue.php`） | 弹出之后、推入之前 `SIGKILL` | 更新仍在 Telegram 侧（偏移量未推进）→ 被重新投递 |
| 队列 fetcher | 推入之后、写入偏移量之前 `SIGKILL` | 更新在中间件代理里**且**仍在 Telegram 侧 → 生产者重启会把它重复放入代理（受 `QUEUE_MAX_SIZE` 约束；消费者的幂等性可吸收它） |
| RabbitMQ 工作进程（v2.4.0 之前） | 处理中途 `SIGKILL` | 任务**永久丢失**（确认早已发出）——已在 v2.4.0 修复 |
| RabbitMQ 工作进程（v2.4.0 及以后） | 处理中途 `SIGKILL` | 任务被**重新投递**给下一个工作进程（未确认） |
| Redis/JSON 工作进程 | 处理中途 `SIGKILL` | 任务永久丢失（破坏性弹出）——设计如此，已有文档 |
| Webhook（FPM） | 处理器超过约 60 秒 | Telegram 超时并重试；如果处理器最终完成，重试会造成重复——让 Webhook 处理保持快速，或改用 `webhook_queue` |
| 代理宕机（任何队列模式） | 连接被拒绝 | 工作进程指数退避（1 秒 → 60 秒），重连并恢复；fetcher 在推入成功前保持偏移量不变 |

## 有界资源

- **队列是有界的**（`QUEUE_MAX_SIZE`、`RETRY_MAX_SIZE`）：满了之后，推入被拒绝并记录日志——一次故障无法悄悄填满你的磁盘。
- **内存是有界的**：轮询工作进程每 `POLLING_RESTART_INTERVAL` 批自我重启一次（可配置，`0` 表示禁用）。
- **日志防路径穿越**：`LOG_DIR`/`LOG_FILE` 不能指向项目之外。

## 重试队列使用的错误分类

`RetryableException::isRetryable()` 对错误分类，只有*暂时性*失败才会被重试：

- HTTP `429 Too Many Requests`（存在时遵循 Telegram 的 `parameters.retry_after`）
- HTTP `5xx` / "server error"
- 超时（`timeout`、`timed out`、`connect`）
- `cURL error N`
- 你自己抛出的任何 `RetryableException` 子类

其余一切（400 "chat not found"、403 "bot blocked" 等）都是永久性错误——记录日志，不重试。

## 测试你自己的可靠性姿态

随附的测试套件包含与崩溃相关的原语（偏移量存储原子性、队列驱动、重试生命周期、Webhook dispatcher 校验路径）：

```bash
php tests/run.php --filter=Queue     # queue driver behaviour
php tests/run.php --filter=Offset    # offset storage
php tests/run.php --filter=Retry     # retry lifecycle
php tests/run.php --filter=Webhook   # webhook validation layers
```

若要端到端的信心，请在你自己的部署上重复集成测试的方法：注册 Webhook，发送一条标记消息，在运行途中 `kill -9` 工作进程，然后在 `getWebhookInfo` / 日志中验证重新投递。
