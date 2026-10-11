# Webhook — 从 Telegram 接收更新

## 搭建

```dotenv
# .env
BOT_MODE="webhook_direct"          # or webhook_queue
BOT_WEBHOOK_URL="https://yourdomain.com/webhook.php"
BOT_WEBHOOK_SECRET="a-long-random-string"   # openssl rand -hex 32
WEBHOOK_CHECK_IP=true
```

注册一次（Telegram 要求 HTTPS 和有效证书）：

```bash
curl "https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://yourdomain.com/webhook.php&secret_token=<SECRET>"
curl "https://api.telegram.org/bot<TOKEN>/getWebhookInfo"   # verify: pending_update_count=0, no last_error
```

注销（切换回轮询——要在启动 `bot.php` *之前*做，两个更新来源会争抢同一个流）：

```bash
curl "https://api.telegram.org/bot<TOKEN>/deleteWebhook"
```

## 五层校验

每个到达 `webhook.php` 的请求都依次经过 `WebhookDispatcher`：

1. **请求体大小** —— 超过 1 MB → `413`。（Telegram 从不发送更大的载荷；任何更大的东西都不是 Telegram。）
2. **IP 白名单** —— 在 `WEBHOOK_CHECK_IP=true` 时，来自 Telegram 官方子网之外 IP 的请求 → `403`。这些网段是 Telegram 文档公布的那几段（`149.154.160.0/20`、`91.108.4.0/22`、`91.108.8.0/22`、`91.108.56.0/22`、`91.108.16.0/22`、`95.161.64.0/20`）。
3. **Secret token** —— 在设置了 `BOT_WEBHOOK_SECRET` 时，`X-Telegram-Bot-Api-Secret-Token` 头必须匹配，比较使用 `hash_equals()`（防时序攻击）。不匹配 → `403`。
4. **JSON 校验** —— 请求体必须能解析为一个包含 `update_id` 的对象。否则 → `400`。
5. **路由** —— `webhook_direct` 同步运行插件，只有没有任何异常抛出时才应答 `200`；`webhook_queue` 入队并立即应答 `200`。

校验失败永远不会以丢失更新的方式消耗它——Telegram 会重发那些未得到 `2xx` 的更新。

## 在 Cloudflare / nginx 代理之后（重要）

如果 Telegram 的请求经由 Cloudflare（或任何反向代理）到达 PHP，PHP 的 `REMOTE_ADDR` 是*代理的* IP，而不是 Telegram 的——IP 检查会拒绝每一个请求。两种可行的方案：

- **代理在前：** 保持 `WEBHOOK_CHECK_IP=false`，把**secret token**作为你的入站认证手段（设长一点——32 个以上十六进制字符）。生产环境测试中框架正是这样验证的。
- **代理传递真实 IP：** 配置你的代理发送 `X-Forwarded-For` / `X-Real-IP`，*并且*让 PHP 信任它——这样 `WEBHOOK_CHECK_IP=true` 就能工作。只有在你控制该代理时才这样做，否则该头可以被伪造。

经验法则：**IP 检查 = 直接暴露；secret = 经代理暴露。** 两者都用也没问题。

## Webhook 入口上的模式行为

| `BOT_MODE` | `webhook.php` 的行为 |
|------------|--------------------------|
| `webhook_direct` | 校验 → `Processor::handle()` → 完成后 `200` |
| `webhook_queue` | 校验 → 入队 → 立即 `200` |
| `update_direct` | 校验 → `Processor::handle()` → `200`（可用，但会记录警告——请明确使用 `webhook_direct`） |
| `update_queue`、`cronjob_*` | `500` + 说明消息——这些模式仅限 CLI |

最后一行就是 v2.4.0 的 SAPI 守卫（[bot-modes.md](bot-modes.md)）：Web 入口上的 CLI 模式再也不能在你的 PHP-FPM 工作进程内启动无限循环（那曾导致 Telegram 侧出现 `Read timeout expired`，以及你的 Web 服务器返回 502）。

## 响应语义 — 为什么 200 很重要

Telegram 会重试任何未得到 `2xx` 的 Webhook 投递，带退避，最长可达数小时。框架坦率地利用了这一点：

- `200` **只有**在更新被处理完成（direct）或安全入队（queue 模式）**之后**才发送。
- 处理期间抛出异常 → `500` → Telegram 稍后重新投递 → 更新**不会丢失**，但如果第一次尝试实际已在崩溃前完成，它*会被处理两次*。这正是至少一次投递的定义——让处理器幂等见 [reliability.md](reliability.md)。
- 一个花几分钟才响应的工作进程是个 bug，不是特性：Telegram 的客户端超时约为 60 秒。把慢的工作移入 `webhook_queue`。

## 用 allowed updates 注册（可选）

把 Telegram 发给你的内容限制为你的插件实际处理的更新类型：

```bash
curl "https://api.telegram.org/bot<TOKEN>/setWebhook?url=...&secret_token=...&allowed_updates=[\"message\",\"edited_message\",\"callback_query\",\"channel_post\",\"edited_channel_post\"]"
```

## 健康检查

`getWebhookInfo` 就是你的仪表盘——关注：

- `pending_update_count` —— 因为你的端点失败/缓慢而积压在 Telegram 处的更新。持续增长 = 事故。
- `last_error_message` / `last_error_date` —— 确切的投递失败原因（超时、TLS 错误、错误的 secret）。

Webhook 模式的部署检查清单：

1. `webhook.php` 可通过 HTTPS 访问（用浏览器测试：一个空的 GET 应该返回 `400 empty`——那是 dispatcher 在应答）。
2. `BOT_WEBHOOK_SECRET` ≥ 32 个随机字符，`.env` 与 `setWebhook` 中完全一致。
3. `WEBHOOK_CHECK_IP` 与你的网络拓扑匹配（见代理一节）。
4. 发送一条测试消息后 `getWebhookInfo` 干净；你的插件副作用可见。
