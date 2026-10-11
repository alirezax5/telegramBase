# 配置 — 完整的 `.env` 参考

把 [.env.example](../../.env.example) 复制为 `.env` 并编辑。所列出的值均为默认值。启动时校验（`EnvironmentValidator`）会检查关键项，失败时给出可读的消息，而不是一个谜之崩溃。

> 仅通过真实环境变量配置（systemd `Environment=`、`docker -e`、k8s）同样受支持——`.env` 是可选的。两者同时存在时，进程环境优先（Dotenv 是不可变的）。

## Bot

| 键 | 默认值 | 说明 |
|-----|---------|-------|
| `BOT_TOKEN` | — | **必需**；来自 @BotFather。会做格式校验；从不出现在错误消息中。`TGBASE_SKIP_BOT_VALIDATION=1` 允许仅数据库的 CLI 命令在没有 token 的情况下运行 |
| `BOT_MODE` | `update_direct` | 六种模式之一——见 [bot-modes.md](bot-modes.md) |
| `BOT_API_URL` | `https://api.telegram.org` | 用于本地 Bot API 服务器的覆盖值 |
| `POLLING_TIMEOUT` | `30` | 每次 `getUpdates` 调用的长轮询秒数 |
| `POLLING_RESTART_INTERVAL` | `100` | 每多少批自我重启一次（内存卫生；`0` 表示禁用） |
| `BOT_WEBHOOK_URL` | — | Webhook 端点 URL（HTTPS） |
| `BOT_WEBHOOK_SECRET` | — | `X-Telegram-Bot-Api-Secret-Token` 的 secret；使用 32 个以上随机十六进制字符（`openssl rand -hex 32`） |
| `WEBHOOK_CHECK_IP` | `true` | 对照 Telegram 子网校验 `REMOTE_ADDR`——在 Cloudflare/代理之后请设为 `false`（此时 secret 就是你的认证层） |
| `DATA_DIR` | `AppData` | 运行时状态（偏移量、队列文件、锁） |

## 插件

| 键 | 默认值 | 说明 |
|-----|---------|-------|
| `PLUGINS_DIR` | `/Plugin/` | 相对于项目根目录；支持绝对路径 |
| `PLUGIN_CACHE_ENABLED` | `true` | 在多次启动之间缓存已发现的插件 |
| `PLUGINS_RELOAD_INTERVAL` | `0` | 两次发现重扫之间的秒数（开发便利） |

## 语言与按钮

| 键 | 默认值 | 说明 |
|-----|---------|-------|
| `LANG_DRIVER` | `json` | 翻译文件驱动 —— ⚠️ 随附文件是 PHP 数组，因此 `.env.example` 设置为 `php`；不匹配时会静默回退为键名（见 [i18n.md](i18n.md)） |
| `DEFAULT_LANG` | `fa` | 回退语言代码 |
| `LANG_DIR` | `/Language` | 翻译文件位置 |
| `LANG_CACHE_TTL` | `60` | 翻译缓存秒数 |
| `BUTTONS_DIR` | `/Button` | 键盘标签定义文件 |
| `BUTTONS_FILE` | `btn.php` | 基础文件名 |
| `BUTTONS_CACHE_TTL` | `60` | 标签缓存秒数 |
| `BUTTONS_CACHE_ENABLED` | `true` | 设为 `false` 可总是从文件重新读取按钮定义 |

## 队列

| 键 | 默认值 | 说明 |
|-----|---------|-------|
| `QUEUE_SAVE_TYPE` | `json` | `json` \| `redis` \| `rabbitmq` \| `memcached` |
| `QUEUE_PATH` | `AppData/queue` | json 驱动的存储目录 |
| `QUEUE_MAX_SIZE` | `0` | 有界队列上限（0 = 无限制）；队列满时拒绝 + 记录日志 |
| `QUEUE_MAX_RETRIES` | `3` | 每次推入/弹出的代理操作重试次数 |
| `QUEUE_RETRY_DELAY` | `2` | 两次代理重试之间的秒数 |
| `QUEUE_REDIS_KEY` / `QUEUE_MEMCACHED_KEY` | `bot_updates` | 各驱动的列表键 |
| `RABBITMQ_QUEUE` | `bot_updates` | RabbitMQ 队列名 |

### Redis 连接

| 键 | 默认值 |
|-----|---------|
| `REDIS_HOST` | `127.0.0.1` |
| `REDIS_PORT` | `6379` |
| `REDIS_PASSWORD` | *（空）* |
| `REDIS_DATABASE` | `0` |
| `REDIS_TIMEOUT` | `60` |

### RabbitMQ 连接

| 键 | 默认值 |
|-----|---------|
| `RABBITMQ_HOST` | `127.0.0.1` |
| `RABBITMQ_PORT` | `5672` |
| `RABBITMQ_USER` | `guest` |
| `RABBITMQ_PASSWORD` | `guest` |
| `RABBITMQ_VHOST` | `/` |

### Memcached 连接

| 键 | 默认值 |
|-----|---------|
| `MEMCACHED_HOST` | `127.0.0.1` |
| `MEMCACHED_PORT` | `11211` |
| `MEMCACHED_USERNAME` / `MEMCACHED_PASSWORD` | *（空 — SASL）* |

## 重试队列

| 键 | 默认值 | 说明 |
|-----|---------|-------|
| `RETRY_ENABLED` | `true` | 总开关 |
| `RETRY_QUEUE_DRIVER` | `json` | `json` \| `redis` |
| `RETRY_MAX_ATTEMPTS` | `3` | 之后丢弃 + 记录日志 |
| `RETRY_BASE_DELAY` | `2` | 秒；每次尝试翻倍；被 Telegram 的 `retry_after` 覆盖 |
| `RETRY_MAX_SIZE` | `1000` | 有界队列上限 |
| `RETRY_REDIS_KEY` | `bot_retry_queue` | 驱动为 redis 时 |

## 缓存与会话

| 键 | 默认值 | 说明 |
|-----|---------|-------|
| `CACHE_DRIVER` | `array` | `array` \| `file` \| `redis` \| `memcached` —— 不支持/不可用的驱动会回退为 `array` 并记录警告 |
| `CACHE_PREFIX` | `tgbase_` | 所有缓存存储的键前缀 |
| `CACHE_PATH` | 系统临时目录 | file 驱动的目录 |
| `CACHE_TTL` | `300` | 当 `put()`/`remember()` 未给显式 TTL 时的默认 TTL 秒数 |
| `SESSION_TTL` | `3600` | 会话生命周期秒数 |
| `SESSION_PREFIX` | `tgbase:session:` | 会话键前缀 |

## 数据库

| 键 | 默认值 | 说明 |
|-----|---------|-------|
| `DATABASE_ENABLE` | `false` | 控制所有数据库引导（CLI 可以在没有数据库的情况下工作） |
| `DB_DRIVER` | `mysql` | 任意 PDO 驱动（本地开发用 `sqlite`） |
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `3306` | |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | — | |

## 多机器人

| 键 | 默认值 | 说明 |
|-----|---------|-------|
| `BOT_EXTRA_BOTS` | — | 单进程内的额外机器人 —— `name1:token1,name2:token2`（按第一个冒号切分）；见 [multi-bot.md](multi-bot.md)。在 `.env` 和进程环境中均有效 |

## 调度器与 cron

| 键 | 默认值 | 说明 |
|-----|---------|-------|
| `SCHEDULER_LOCK_TTL` | `300` | 任务锁秒数 |
| `SCHEDULER_LOCK_TABLE` | `task_locks` | 锁后端的表 |
| `SCHEDULE_FILE` | `bin/tasks.php` | `bin/scheduler.php` 使用的任务文件（路径覆盖） |
| `CRON_WORKER` | `10` | `cronjob_queue` 的并发槽位数（文件锁） |
| `CRON_MAX_TIME` | `50` | 工作进程每个 cron tick 的最大秒数 |

## 日志

| 键 | 默认值 | 说明 |
|-----|---------|-------|
| `LOG_ENABLED` | `true` | 总开关 |
| `LOG_LEVEL` | `debug` | Monolog 级别 |
| `LOG_DIR` | `./logs` | **仅限相对名称** —— 绝对路径和 `..` 会被拒绝（防路径穿越保护） |
| `LOG_FILE` | `log.txt` | |

## 管理员

| 键 | 默认值 | 说明 |
|-----|---------|-------|
| `ADMINS_WHITELIST` | — | 逗号分隔的 Telegram 用户 id，由 `AdminGuardMiddleware` 使用 |

## 安全说明

- 永远不要提交 `.env`；提供的 `.gitignore` 已排除它（`*.secret` 和 `secrets.json` 同样如此）。
- `EnvironmentValidator` 的错误消息只指出缺失的键名，从不给出它的值。
- Webhook secret 用 `hash_equals()` 比较（恒定时间）。
- 刻意**不提供 `VERIFY_TLS=false` 开关** —— 到 api.telegram.org 的 TLS 校验始终开启。
