# 多机器人 — 在一个进程中运行两个机器人

TelegramBase 在两个层面支持多机器人运行：底层的
[`telegramBotPhp` 库](https://github.com/alirezax5/telegramBotPhp)提供了
原语（独立实例、token 栈、原始调用），而框架在其上添加了一个
**`BotManager`** 注册表，让插件可以按名称触达次要机器人。

两个所有者要求的场景均已用两个真实机器人实测验证（2026-10-11）：

| 场景 | 发生的事情 | 已验证结果 |
|---|---|---|
| **A — 通过 2 号机器人做成员检查** | 用户询问 1 号机器人；1 号机器人**用 2 号机器人的 token** 执行 `getChatMember()` | `ok=true`、`status=member`、13 ms |
| **B — 委托发送** | 用户询问 1 号机器人；1 号机器人在自己的进程内**让 2 号机器人发送**消息 | 已送达，239 ms |

---

## 注册额外机器人：`BOT_EXTRA_BOTS`

在启动之前添加该环境变量（在 `.env` 或进程环境中）：

```dotenv
BOT_EXTRA_BOTS="second:398172019:AAxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
```

格式规则：

- 条目以**逗号分隔**；每个条目是 `name:token`；
- 只按**第一个**冒号切分（bot token 本身就含一个冒号）；
- 名称 `main` 为主机器人保留，在该列表中会被忽略；
- 格式错误的条目会被记录（警告）并跳过——启动永不失败。

`BotManager` 是首次使用时创建的单例；默认机器人（`main`）由
`Config::bot()` 构建（即 `BOT_TOKEN`），然后注册其余的。

## 在插件中使用机器人

```php
use alirezax5\TelegramBase\App\Bot\BotManager;
use alirezax5\TelegramBase\App\Plugin\Contract\PluginInterface;
use telegramBotApiPhp\Telegram;

class MultiBotPlugin implements PluginInterface
{
    public function getPriority(): int { return 1; }

    public function onMessage($message, Telegram $tg): void
    {
        if ($message->text !== '/inchannel') {
            return;
        }

        $bm  = BotManager::getInstance();
        $userId = $tg->fromId();
        $chatId = $tg->chatId();

        // Scenario A — membership check executed by bot #2's identity
        if ($bm->has('second')) {
            $member = $bm->get('second')->getChatMember('@mychannel', $userId);
            $status = $member->result->status ?? 'left';   // member|administrator|creator|left|kicked
            $tg->sendMessage($chatId, "وضعیت عضویت شما (چک توسط ربات ۲): {$status}");
        } else {
            $tg->sendMessage($chatId, 'ربات دوم ثبت نشده — BOT_EXTRA_BOTS را تنظیم کنید');
        }

        // Scenario B — bot #1 sends AS bot #2 (token stack on the main instance)
        if ($bm->has('second')) {
            $tg->switchToken($bm->get('second')->getToken());
            try {
                $tg->sendMessage($chatId, 'این پیام با هویت ربات ۲ ارسال شد (داخل پروسه ربات ۱)');
            } finally {
                $tg->restoreToken();
            }
        }
    }
}
```

## `BotManager` 的 API

| 方法 | 行为 |
|---|---|
| `getInstance(): BotManager` | 单例访问器 |
| `get(?string $name): Telegram` | 返回指定名称的机器人；**未知名称回退到 `main`**（记录警告） |
| `has(string $name): bool` | 该名称是否已注册？—— **非默认机器人在 `get()` 之前务必先检查** |
| `add(string $name, Telegram $bot): void` | 在运行时注册/覆盖一个机器人（覆盖会记录警告） |
| `names(): string[]` | 所有已注册名称（`main` 在前） |
| `setDefaultToken(string $token): Telegram` | 全局覆盖**主**机器人的 token —— 影响每一个调用方 |

## 为什么 `has()` 很重要

`get('typo-name')` 不抛异常——它会静默返回**主**机器人。本想给 2 号机器人
执行的 `getChatMember()` 于是会以 1 号机器人的身份执行，并返回
`chat member not found`，或者更糟：一个错误但貌似合理的答案。每次非默认访问都要用 `has()` 加以防护。

## 令牌管理：一个文件承载多个机器人

一套部署（一个入口文件 + 一个 `.env`）可以承载任意数量的机器人身份。支持三种策略——按推荐程度排序：

| # | 策略 | token 存放位置 | 最适合 | 已验证 |
|---|---|---|---|---|
| 1 | **静态注册表** —— `.env` 中的 `BOT_TOKEN` + `BOT_EXTRA_BOTS` | 一个 `.env`，`name:token` 键值对 | 一起部署的一组固定机器人 | 23/23 项检查（parse / identity / degrade / scale） |
| 2 | **动态注册表** —— 运行时调用 `BotManager::add($name, new Telegram($token))` | 你的数据库/配置面板；传入进程 | 由管理面板开通的机器人、按租户的机器人 | identity + 委托发送检查 |
| 3 | **托管机器人（Managed Bots）** —— 子机器人 token 实时从 Telegram 获取（见下一节） | **磁盘上不留任何内容** | 由管理者机器人为用户代建子机器人 | TM-2 套件，8/8 |

实测测试套件证明的规则（2026-10-11，两个真实机器人 + 合成条目）：

- 格式错误的 `BOT_EXTRA_BOTS` 条目会被记录并**跳过——启动永不失败**；
- 失效/被吊销的 token 会干净地失败（`ok=false`、`Unauthorized`），并且绝不污染其他机器人：`get('dead')` 报错，`get('alive')` 和 `main` 保持健康；
- `main` 为保留名称；重复名称按后注册者胜出（last-wins）解析并记录警告；
- token 自身的冒号能在 `name:token` 切分中保留下来（只按第一个冒号切分）；
- 身份隔离：每个已注册实例的 `getMe()` 都与其 token 的 id 前缀匹配；token 栈按实例隔离（在 `main` 上 switch/restore 不会影响 `second`）；
- 注册表查找为 O(1)：注册 32 个机器人时，10,000 次 `get()` 调用约 1 ms。

Webhook 说明：在 `webhook_*` 模式下，抵达 `webhook.php` 的每一条更新都以**主**机器人的身份处理（共用同一个 `BOT_WEBHOOK_SECRET`）。要通过 webhook 服务多个机器人，你**并不**需要为每个机器人准备一个目录——下一节的 `secret_token` 模式可以在**一个 webhook 文件中运行任意数量的机器人**，已实测验证。

## 一个 webhook 文件，多个机器人 —— `secret_token` 模式

你并不需要为每个机器人准备一个文件夹。**一个 webhook 文件就能服务任意数量的机器人。** 诀窍在于 `setWebhook` 的 `secret_token` 参数：

1. 为每个机器人挑选一个**不同的随机 secret**（只允许字母、数字、`_` 和 `-`）。
2. 为每个机器人注册**同一个 webhook URL**，各配一个自己的 secret：
   `setWebhook?url=https://example.com/hook.php&secret_token=<this-bots-secret>`
3. 每一条更新中，Telegram 都会在请求头 `X-Telegram-Bot-Api-Secret-Token` 中把该 secret 回传。
4. 你的文件读取该请求头，在小型注册表（`secret → bot token`）中查找该 secret，然后以该机器人的身份处理这条更新。

```text
             ┌─ bot A: setWebhook(url=hook.php, secret=SECRET_A)
 Telegram ───┤
             └─ bot B: setWebhook(url=hook.php, secret=SECRET_B)
                         │   POST + header X-Telegram-Bot-Api-Secret-Token
                         ▼
               ┌───────────────────┐   SECRET_A → token A → act as bot A
               │     hook.php      │
               │  registry:        │
               │  secret → token   │   SECRET_B → token B → act as bot B
               └───────────────────┘
```

为什么这是运行机器人集群（bot farm）的推荐方式：

- **bot token 永远不会出现在 URL 中**——它不可能泄漏到访问日志、浏览器历史或代理中；
- secret 同时兼任**真实性校验**——没有正确 secret 的请求并非来自 Telegram；返回 `403` 并丢弃即可；
- **新增第 N+1 个机器人只需一次 `setWebhook` 调用和一行注册表记录**——无需新文件，无需新部署。

实测证明（2026-10-11，两个真实机器人，一个文件，真实用户流量）：

| 事件 | 路由到的身份 | 结果 |
|---|---|---|
| 在机器人 B 上真实的 `/start` | @instaroonxbot | 已回复，`ok:true` |
| 真实点击机器人 B 的内联按钮（×2） | @instaroonxbot | `answerCallbackQuery` + 回复，`ok:true` |
| 真实点击机器人 A 的内联按钮 | @emojiCustom_bot | `answerCallbackQuery` + 回复，`ok:true` |
| 携带 secret A / secret B 的合成 POST | 每次都是正确的机器人 | 已回复，`ok:true` |

7/7 次投递——每一条更新都命中同一个文件，每一条回复都带有正确的机器人身份。bot token 从未出现在任何 URL 或请求头中。

生产环境加固规则：

- secret **就是路由键**——它对每个机器人必须唯一；
- 随机生成，32+ 个字符，取自 `[A-Za-z0-9_-]`（Telegram 会拒绝其他字符）；2 个字符的 secret 等于没有 secret；
- 在触碰任何其他东西之前，先用 `403` 拒绝未知或缺失的 secret；
- 绝不把 token 写入日志；最多记录 secret 的一个短前缀；
- 轮换 secret = 为该机器人再运行一次 `setWebhook`（只需几秒）。

## 托管机器人（Managed Bots，Bot API 9.6）

Telegram Bot API 9.6（2026-04-03）引入了**托管机器人（Managed Bots）**：拥有 `can_manage_bots` 的机器人（在 @BotFather Mini App 中启用）可以让其他机器人在其之下创建并受其控制。子机器人的 token 永远无需存储——管理者用 `getManagedBotToken(user_id)` 实时获取。

框架支持（v2.4.x）：

| API 元素（Bot API 9.6） | 框架侧接口 |
|---|---|
| `User.can_manage_bots`（getMe） | `$tg->callRaw('getMe')->result->can_manage_bots` |
| `managed_bot` 更新（`ManagedBotUpdated`） | 分发到 `onManagedBot($managedBot, $tg)` —— 已加入插件白名单 |
| `KeyboardButtonRequestManagedBot` | `Keyboard::reply()->requestManagedBot($text, $requestId, $suggestedName, $suggestedUsername)` |
| `getManagedBotToken` / `replaceManagedBotToken` / `get·setManagedBotAccessSettings` | `BotManager::registerManagedBot()` / `refreshManagedBot()` / `managedBotAccessSettings()` |
| `ManagedBotCreated` 消息字段 | 在 `onMessage` 内的负载中可见 |

端到端流程（插件草图）：

```php
use alirezax5\TelegramBase\App\Bot\BotManager;
use alirezax5\TelegramBase\App\Button\Keyboard;

// 1) offer creation (private chats only)
$tg->sendMessage($chatId, 'Create your own bot:', [
    'reply_markup' => Keyboard::reply()
        ->requestManagedBot('➕ Create my bot', 1, 'MyHelperBot', 'my_helper_bot')
        ->toArray(),
]);

// 2) the user completes the flow in Telegram → managed_bot update arrives
public function onManagedBot($managedBot, $tg): void
{
    // fetches the child token live (getManagedBotToken) and registers it
    BotManager::getInstance()->registerManagedBotFromUpdate($managedBot);
}

// 3) use the child bot — registered under its bot id
BotManager::getInstance()->get((string) $managedBot->bot->id)
    ->sendMessage($chatId, 'hello from the child bot');
```

前置条件（所有者操作，一次性）：

1. 在 @BotFather Mini App 中为管理者机器人启用**对其他机器人的管理**——在此之前，`getMe` 会报告 `can_manage_bots: false`，创建请求会失败。
2. 子机器人的创建本身始终发生在 Telegram 一侧（需用户同意）；框架只负责接收 `managed_bot` 更新并获取 token。

token 卫生：托管 token 只存在于内存（注册表）中——不会写入 `.env` 或日志。通过 `BotManager::refreshManagedBot($userId, $name)` 轮换子机器人 token 会立即吊销旧 token 并重新注册新 token。

### 托管机器人——实测端到端（2026-10-11）

在所有者于 @BotFather Mini App 中启用**对其他机器人的管理**之后，两个测试机器人的 `getMe` 都翻转为 `can_manage_bots: true`，完整流程得到了实测：

- 由 `Keyboard::reply()->requestManagedBot(...)` 构建的创建按钮在私聊中发送——Telegram 接受了框架的键盘结构；
- 一个值得了解的字段细节：对 `KeyboardButtonRequestManagedBot` 而言，`request_id` 必须是 **Number**（不像 `request_chat` / `request_users` 那样是 String——Telegram 会以 `Bad Request: can't parse KeyboardButton: Field "request_id" must be a valid Number` 拒绝字符串）；
- 用户完成创建表单后，管理者会收到一条 `managed_bot` 更新，并用 `getManagedBotToken(user_id)` 实时获取子机器人 token——随后，演示让**子机器人在所有者的聊天里说话**，用的是它自己刚获取的 token（磁盘零存储）。

启用同一批 Mini App 开关也让两个测试机器人上的以下字段一并翻转：
`supports_guest_queries`、`has_topics_enabled`、
`supports_join_request_queries`、`can_connect_to_business` → 全部为 `true`。

- 实测到的更新结构（2026-10-11 捕获）：`managed_bot.user` = 创建子机器人的用户（普通用户），`managed_bot.bot` = 子机器人本体（`{id, is_bot: true, username, ...}`）。请把 `managed_bot.bot.id` 传给 `getManagedBotToken` —— 传创建者的 id 会报 `Bad Request: user is not a bot`。演示中实时取到了子机器人令牌，并由子机器人向其创建者发送了真实消息 —— 磁盘上零令牌。

### token 和 secret 存放在哪里

| 存储方式 | 适用场景 | 注意事项 |
|---|---|---|
| **`.env`**（`BOT_TOKEN` + `BOT_EXTRA_BOTS`） | 小型、固定的机器人集合 | 仅 root 可读；修改需要重启 |
| **数据库注册表**（`bots` 表：id、name、token、secret、status） | 运行时增删的机器人、管理面板 | token 列静态加密（例如 sodium）；缓存注册表，让一次更新 ≠ 一次数据库查询 |
| **托管机器人（磁盘零存储）** | 由管理者机器人为用户代建子机器人 | 需要 `can_manage_bots`；token 通过 `getManagedBotToken` 实时获取 |
| **派生 secret（HMAC）**——不存储任何东西 | 你掌握一把主密钥 | `secret = base64url(hmac_sha256(master_key, 'bot:' . bot_id))`；轮换主密钥意味着要重新注册每一个 webhook |

针对不断壮大的机器人集群的建议：**数据库注册表（token 静态加密）+ 缓存查找 + `secret_token` 路由**，用户创建的子机器人则交给**托管机器人**。`.env` 只保留给第一个管理者机器人。

### 让每个机器人的数据彼此隔离

当许多机器人共享同一套部署时，每个机器人的数据必须专属于该机器人。用 bot id 为**每一个存储层划分命名空间**（`b{bot_id}`）：

| 层 | 规则 |
|---|---|
| 缓存 | 键前缀 `b{bot_id}:`（启动时为每个机器人设置 `CACHE_PREFIX`） |
| 会话 | 按 **bot id + 用户 id** 划分作用域——同一个人与两个机器人对话就是两个不同的会话 |
| getUpdates offset | **每个机器人一个 offset 文件/键**——共享 offset 会把一个机器人的更新喂给另一个机器人 |
| 队列 | 每个机器人一个队列名（`tgbase_b{bot_id}_updates`），或给负载打上 `bot_id` 标签 |
| 文件存储 | `data/bots/{bot_id}/…` |
| 日志 | 每个机器人一个日志文件（`logs/bot_{id}.log`） |
| 数据库 | 共享表增加一列 `bot_id`；真正的隔离 = 每个机器人一个独立数据库 |

webhook 前端控制器先解析 `secret → bot`，然后用该机器人的 token 和命名空间启动框架——此后的每一次读写都会自动留在该机器人自己的沙箱内。

## 投递语义

多机器人调用与所有其他 API 调用使用同一个 HTTP 客户端：

- 重试/退避和 `retry_after` 处理按实例生效；
- token 栈是**按实例的状态**——在队列工作进程中，每次更新都由一次全新的分发处理，
  因此 switch/restore 配对存活于单次更新的生命周期内；永远不要跨更新依赖栈状态；
- 在 `webhook_*` 模式下，同样的规则在单个 FPM 请求内适用。

## 实测验证工具

`integration/TestBot/Plugin/MultiBotTestPlugin.php`（测试工具，私聊）：

- `/multibot` —— 完整套件：身份、隔离、交叉发送（场景 B）、
  经由 2 号机器人的成员检查（场景 A）、token 栈 switch/restore；
- `/mbchannel <chat>` —— 2 号机器人对显式 `@channel`/聊天 id 执行 `getChatMember`
  （2 号机器人必须是该聊天的管理员/成员）。

证据日志：`runtime/p331/multibot.log` —— 最终实测运行
（2026-10-11 12:26:19）：identity ✔、isolation ✔、cross-send ✔（239 ms）、
membership ✔（13 ms）、token stack ✔ —— **393 ms 内 5/5 PASS**。

补充工具实测运行（2026-10-11，同步后的实时运行环境）：

- **TM-1 令牌管理套件** —— 23/23 PASS，覆盖四个用例：`parse`（7）、`identity`（8，两个真实机器人）、`degrade`（5，被吊销的 token）、`scale`（3，32 个机器人 / 10k 次查找）。
- **TM-2 托管机器人（Managed Bots）套件** —— 8/8 PASS：实测 `getMe` 暴露 `can_manage_bots`；`getManagedBotToken` / `getManagedBotAccessSettings` 可达且错误契约干净；合成 `managed_bot` 更新按 `before → onManagedBot` 分发；无效的 `registerManagedBot()` 会干净地抛出异常而不污染注册表；`requestManagedBot()` 的键盘结构与 `KeyboardButtonRequestManagedBot` 一致。
