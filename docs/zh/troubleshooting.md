# 故障排查 — 症状、原因、修复

## 启动与配置

| 症状 | 可能原因 | 修复 |
|---------|--------------|-----|
| 在 `migrate:status` 上出现 `BOT_TOKEN is not set or empty` | 旧行为 | 已在当前版本修复——migrate 命令不再要求 token。如果你仍然看到它，请带 `TGBASE_SKIP_BOT_VALIDATION=1` 运行 |
| 在 `migrate` 上出现 `could not find driver` | `DATABASE_ENABLE=true` 但未配置 PDO 驱动/数据库 | 不需要数据库就设 `DATABASE_ENABLE=false`，或安装 PDO 驱动 |
| 启动失败并报模式相关的校验错误 | `BOT_MODE` 拼写错误 | 只能使用以下之一：`update_direct`、`update_queue`、`webhook_direct`、`webhook_queue`、`cronjob_update`、`cronjob_queue` |
| 日志出现在 CWD 而不是 `logs/` | 旧行为——相对日志路径以 CWD 解析 | 已修复：`LogHandler` 使用 `LoggerConfig::absolutePath()`（相对于项目根目录）。从其他目录运行 `bin/tgbase` 时也曾受影响 |
| `Session::regenerate()` 致命错误："Cannot modify readonly property" | 旧行为 | 已修复：`Session` 不再把 `$sessionId` 声明为 readonly |
| `tests/smoke.php` 的所有负面用例都失败 | 旧的损坏的 match 逻辑 | 已在当前版本修复 |
| 插件未加载 | `PLUGINS_DIR` 错误，或插件缓存过期 | 默认是 `/Plugin/`（相对于项目根目录；支持绝对路径）。开发时设置 `PLUGIN_CACHE_ENABLED=false`，然后用 `php bin/tgbase plugins` 验证发现结果 |
| 回调查询时限流器/管理员守卫崩溃 | 旧的非 nullsafe `->message->chat->id` 访问 | 已修复：nullsafe 链 + 支持 `edited_message` / `channel_post` / `chat_member` |

## Webhook

| 症状 | 可能原因 | 修复 |
|---------|--------------|-----|
| Webhook 对每次投递都返回 `403` | IP 白名单启用，但代理/CDN 改写了来源 IP | 在 Cloudflare/nginx 之后设置 `WEBHOOK_CHECK_IP=false`，并依赖强 `BOT_WEBHOOK_SECRET` —— 见 [webhook.md](webhook.md) |
| Webhook 返回 `403`，`getWebhookInfo` 提示 secret 不匹配 | `.env` 中的 `BOT_WEBHOOK_SECRET` ≠ 传给 `setWebhook` 的 `secret_token` | 让两者一致；用 `openssl rand -hex 32` 轮换 |
| Webhook 返回 `500` 并提示 "BOT_MODE ... is CLI-only" | Web 入口上是仅限 CLI 的模式（v2.4.0 SAPI 守卫） | 为 Web 入口设置 `BOT_MODE=webhook_direct` / `webhook_queue`，或从 CLI 运行该模式（`bot.php` / `queue.php`） |
| `getWebhookInfo`：`last_error_message="Read timeout expired"` | 端点未及时应答（v2.4.0 之前这可能是无限循环 bug；v2.4.0 之后是慢处理器） | 升级到 ≥ v2.4.0；检查慢处理器；把繁重工作移到 `webhook_queue` |
| `pending_update_count` 持续增长 | 你的端点持续宕机/失败 | 检查 `last_error_message`、TLS 证书有效性，以及 `webhook.php` 是否可达；一个返回 `400 empty` 的裸 GET 证明 dispatcher 还活着 |
| Webhook 应答延迟数秒 | 处理器在 HTTP 请求期间内联运行 | 切换到 `webhook_queue` —— 端点随后立即应答，工作进程异步处理 |

## 队列与工作进程

| 症状 | 可能原因 | 修复 |
|---------|--------------|-----|
| 连接时出现 `RabbitMQ ... "Broken pipe or closed connection"` | 旧的登录方法 bug（`default` 不是有效的 AMQP 机制） | 已在 v2.4.0 修复（驱动现在使用 `AMQPLAIN`）；无论如何请核实你的 RabbitMQ 用户/vhost |
| 更新已推入但从未被处理 | 没有消费者在运行 | 模式 `*_queue` 需要两端：生产者（`queue.php` 或 `webhook.php`）**和**消费者（`php bot.php`） |
| 工作进程宕机时队列无限增长 | | 设置 `QUEUE_MAX_SIZE`（有界队列在满时拒绝 + 记录日志） |
| 工作进程崩溃后出现重复处理 | 至少一次语义下的预期行为（RabbitMQ ack-late、Webhook/轮询重新投递） | 让处理器幂等 —— 见 [reliability.md](reliability.md) |
| 负载下工作进程静默退出 | 缺少监督器 | 把所有长驻进程放到 systemd/supervisord 下并以 `Restart=always` 运行；框架每 `POLLING_RESTART_INTERVAL` 批自我重启轮询，其余一切由监督器兜底 |
| `getUpdates` 返回 `409 Conflict` | 两个进程在轮询同一个 bot token（或 webhook 与轮询同时进行） | 只留一个更新来源：只运行单个 fetcher，并在开始轮询前 `deleteWebhook` |

## 值得铭记的部署要点

- **日志文件权限（FPM）：** 如果 Webhook 入口无法写日志，通常是文件属主问题——PHP-FPM 以 `www-data`/`www` 运行，所以日志文件/目录必须对该用户可写（`chown www-data:www-data logs -R`）。否则静默失败的日志写入很难察觉。
- **PHP-FPM `request_terminate_timeout`：** 你的 Webhook 处理器任何超过此限制的动作都会以 502 死掉。让 Webhook 处理保持快速；其余交给队列。
- **`php bin/tgbase doctor`** 是最快的分诊工具——它检查 PHP 版本/扩展、`.env` 可读性、`AppData/` 可写性、日志可写性以及 token 格式，且不触碰 Telegram。
- **两个更新来源打架：** 在轮询与 Webhook 之间切换后，先确认切换完成（`deleteWebhook` 或干净地停止轮询）再启动另一侧。两者同时运行 = 偏移量冲突和 409。

## 诊断速查

```bash
php bin/tgbase doctor              # environment triage
php bin/tgbase plugins             # what the framework actually discovered
php tests/smoke.php                # 6-case bootstrap check
php tests/run.php --filter=Queue   # subsystem deep-dive
curl "https://api.telegram.org/bot<TOKEN>/getWebhookInfo"   # Telegram-side view
```
