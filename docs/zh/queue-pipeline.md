# 队列管道 — 当单个进程不够用时

## 为什么要用队列？

内联处理有一个简单的弱点：*接收*更新的进程还必须*完成*对它的处理。如果某个处理器要上传一个 50 MB 的视频，或要等待一个缓慢的第三方 API，接收方就会被阻塞（在 Webhook 模式下，Telegram 还在等它的 200）。队列管道把工作拆开：

```
producer                          broker                    consumers (N processes)
──────                            ──────                    ────────
queue.php / webhook.php  ──push──► Redis / RabbitMQ ──pop──► bot.php workers
(pick up updates fast,            (holds updates while       (do the slow work,
 advance offset / 200)             nobody is around)          ack after processing)
```

好处：快速签收（Telegram 永远不必等你的处理器）、水平扩展（运行更多工作进程）、崩溃隔离（一个垂死的工作进程不影响接收方）。

## 选择驱动

| 驱动 | `QUEUE_SAVE_TYPE` | 需要 | 弹出行为 | 工作进程崩溃时的保证 |
|--------|-------------------|-------|---------------|---------------------------|
| JSON | `json` *(默认)* | 无 | 破坏性文件读取 | **至多一次**——处理中途崩溃则任务丢失 |
| Redis | `redis` | `redis` 扩展 + 服务器 | `LPOP`（破坏性） | **至多一次**——处理中途崩溃则任务丢失 |
| Memcached | `memcached` | `memcached` 扩展 + 服务器 | 破坏性 get/delete | **至多一次** |
| RabbitMQ | `rabbitmq` | `amqp` 扩展 + 服务器 | `basic_get` + **处理后确认**（v2.4.0） | **至少一次**——未确认的任务会被重新投递 |

当丢失单个更新不可接受时选 RabbitMQ；当偶发丢失可以容忍（或更新很容易重建）时，为吞吐量和简单性选 Redis/JSON。

## RabbitMQ 确认契约（v2.4.0）

自 v2.4.0 起，RabbitMQ 驱动采用 **ack-late** 语义：

1. `pop()` 取出一条消息并持有其投递标签——消息*尚未*被确认。
2. 你的处理器运行。
3. 工作进程在处理**之后**调用 `ack()`。只有那时中间件代理才删除这条消息。
4. 如果工作进程在第 1 步与第 3 步之间的任何位置死去，中间件代理会把消息重新投递给下一个工作进程。

在代码层面，工作进程做的正是这件事：

```php
$update = $queue->pop();
try {
    $processor->handle($update);
} finally {
    $queue->ack();   // success → done; handled exception → discarded (no poison loops)
}
```

值得了解的后果：

- **可能出现重复处理**（处理完成后、确认之前崩溃——中间件代理无法分辨）。请让处理器幂等：先存储 `update_id` 再行动，若已见过则跳过。见 [reliability.md](reliability.md)。
- 无法解码的消息会被立即确认（丢弃），因此它不可能毒化队列。
- 自定义队列驱动不需要实现 `ack()`——对于没有代理端确认机制的驱动，`QueueManager::ack()` 是空操作。

配置位于 `.env` 的 `rabbitmq` 段（host、port、user、password、vhost、队列名）——见 [.env.example](../../.env.example)。

## 运行管道

`update_queue`（轮询生产者）：

```bash
# terminal 1 — fetcher: long-polls Telegram, pushes to the broker
php queue.php

# terminal 2..N — consumers: pop + process + ack
php bot.php
```

`webhook_queue`（Webhook 生产者）：

```bash
# web server receives Telegram POSTs → validates → enqueues → 200
# (webhook.php handles this automatically when BOT_MODE=webhook_queue)

# terminal 1..N — consumers
php bot.php
```

所有进程都要在监督器下运行。工作进程会以指数退避自动重连中间件代理（`BACKOFF_INITIAL=1s` … `BACKOFF_MAX=60s`），因此你可以在不触碰工作进程的情况下重启 Redis/RabbitMQ。

## 有界队列

`QUEUE_MAX_SIZE`（默认值见 [.env.example](../../.env.example)）限制了中间件代理中最多可以堆积多少条更新。达到上限后，新的推入会被**拒绝并记录日志**（WARNING），绝不会悄悄堆成无界的一堆。当工作进程长时间宕机时，这可以保护磁盘/内存。大小为 0 表示禁用上限。

## 重试队列（独立于主队列）

重试队列处理*暂时性的处理器失败*（HTTP `429`、`5xx`、超时、curl 错误），覆盖所有模式，而不仅仅是入队模式：

- `Processor::handle()` 捕获一个可重试的错误 → 把更新推入 `RetryQueue`。
- 长驻工作进程在其维护 tick（队列工作进程的每次循环迭代）中清空到期的条目。
- 每次尝试退避翻倍；当 Telegram 提供 `retry_after` 时，则严格遵循该确切值（`RetryableException::retryAfter`）。
- 超过 `RETRY_MAX_ATTEMPTS` 后，该条目被丢弃并记录日志。

你的处理器通过 `tg_retryable()` 辅助函数选择加入：

```php
use function alirezax5\TelegramBase\App\Queue\tg_retryable;

$resp = tg_retryable($tg->sendMessage($chatId, 'Hello!'), 'sendMessage');
// ok=true            → returned unchanged
// 429 / 5xx          → RetryableException thrown → retry queue
// other 4xx          → returned unchanged (permanent errors are not retried)
```

配置：`RETRY_ENABLED`、`RETRY_QUEUE_DRIVER`（`json`|`redis`）、`RETRY_MAX_ATTEMPTS`、`RETRY_BASE_DELAY`、`RETRY_MAX_SIZE` —— 默认值见 [.env.example](../../.env.example)。
