# 缓存 — CacheManager

一个静态 API，四套可互换的驱动，全部通过 `.env` 配置。会话、翻译、按钮定义以及（可选的）插件发现都建立在这一层之上——切换驱动即改变整个框架的持久化方式，而无需触碰代码。

```php
use alirezax5\TelegramBase\App\Cache\CacheManager;
```

> 本文档中的每一条断言都在生产服务器上对真实后端进行了实测验证（2026-10-11）：17 项检查的套件在 `array`、`file`、`memcached`（PHP 8.5）和 `redis` 上全部通过，另外对每个持久化驱动还有一份 9 项的跨进程持久化矩阵。见底部的*实测验证*一节和 [CHANGELOG](../../CHANGELOG.md)。

## 驱动

| `CACHE_DRIVER` | 后端 | 跨进程 | 标签 | 说明 |
|---|---|---|---|---|
| `array`（默认） | 按进程内存 | ✗ | ✗ | 零配置；状态的生命周期恰好与进程一致 |
| `file` | 磁盘，`CACHE_PATH` | ✓ | ✗ | 无额外服务；两级哈希目录；PHP 序列化载荷 |
| `redis` | phpredis 扩展（`redis.so`） | ✓ | ✗ | JSON 载荷；多进程部署推荐 |
| `memcached` | `memcached` 扩展 + 服务器 | ✓ | ✗ | JSON 载荷；天然易失 |

**回退链（已验证）：**如果请求的驱动不可用——扩展缺失、服务器不可达、认证失败、路径不可写——框架会记录警告并**回退到 `array`**，而不是启动失败。在没有该扩展的 PHP 上请求 `memcached` 产生的正是这一结果：`driver=array`，其余一切不变。用 `CacheManager::getDriver()` 检查你实际得到的驱动。

> ⚠️ `array` 驱动是进程本地的。轮询守护进程是*一个*长驻进程，所以 `array` 在那里"能用"；但 Webhook（每请求的 FPM 工作进程）和队列工作进程（每个工作进程一个进程）从不共享状态。如果会话、计数器或 `remember()` 需要跨消息/进程存续，请使用 `file`、`redis` 或 `memcached`。这已实测演示：进程 A 写入、进程 B 读取的值——在其余三个驱动上持久存在，在 `array` 上 `<MISS>`（设计如此）。

## 配置（.env）

```dotenv
CACHE_DRIVER=file            # array | file | redis | memcached
CACHE_PREFIX=tgbase_         # key namespace (also the flush() safety boundary)
CACHE_TTL=3600               # default TTL seconds when put() has no explicit ttl
CACHE_PATH=/tmp/tgbase-cache # file driver only
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=              # required if the server has requirepass (NOAUTH otherwise)
REDIS_DATABASE=0
MEMCACHED_HOST=127.0.0.1
MEMCACHED_PORT=11211
```

## API

| 方法 | 行为 |
|---|---|
| `get($key, $default = null)` | 未命中/过期时返回值或默认值 |
| `put($key, $value, ?int $ttl = null)` | 存储；`null` ttl → `CACHE_TTL` |
| `remember($key, callable $cb, ?int $ttl = null)` | 返回缓存值或执行 `$cb`，并缓存结果 |
| `has($key)` | 存在且未过期 |
| `forget($key)` | 删除一个键 |
| `flush()` | 删除**本框架命名空间的所有键** —— 见各驱动说明 |
| `increment($key, $by = 1)` / `decrement($key, $by = 1)` | 计数器 |
| `getDriver()` / `isInitialized()` / `tagsSupported()` | 内省 |
| `putWithTags()` / `getWithTags()` / `rememberWithTags()` / `flushTags()` | 在所有驱动上可用的标签式 API（基于前缀的回退实现） |
| `tags([...])` | 原生标签缓存——除非驱动支持标签否则**抛出异常**（四个驱动都不支持） |

### TTL 语义（以真实等待验证）

`put('k', 'x', 2)` → `has` 为 true → 3 秒后 → `has` 为 false，`get` 返回默认值。后端 TTL 与配置完全吻合：一个 300 秒的条目在 `redis-cli` 下显示 `ttl=297s`，一个 3600 秒的会话 `ttl=3597s`，一个 60 秒的翻译缓存 `ttl=57s`。`file`/`redis` 中的过期条目被惰性删除（访问时）或由后端删除（`redis` 的 setex）。

### 存储格式

- **redis** — 以 `json_encode(JSON_UNESCAPED_UNICODE)` 执行 `setex`。载荷必须可 JSON 往返：标量和数组可以；PHP 对象返回时变成数组；不支持资源。
- **memcached** — 通过同一存储族的 JSON；值由扩展透明地（反）序列化。
- **file** — PHP `serialize()` 载荷，过期时间戳在前，位于 `CACHE_PATH/ab/cd/<sha1>`。
- **array** — 纯 PHP 变量；不触碰磁盘或网络。

### 键布局（后端原始视图）

| 驱动 | 在 `CACHE_PREFIX=tgbase_` 下 `put('lang:fa', …)` 的原始键 |
|---|---|
| redis | `tgbase_lang:fa` |
| memcached | `tgbase_:lang:fa`（Illuminate 会在前缀后附加 `:`） |
| file | `CACHE_PATH/<h1>/<h2>/<sha1>` |

### 计数器 — 一个细节（已验证）

对**缺失**的键执行 `increment()`：`array`/`file`/`redis` 会创建它（`incBy`），`memcached` 返回 `false`（Illuminate 不发送初始值）。可移植的写法：先播种 —— `CacheManager::put($k, 0, $ttl); CacheManager::increment($k);`

### flush() — 各驱动的覆盖范围

- `array` — 仅本进程。
- `file` — `CACHE_PATH` 内的一切（专用目录；安全）。
- `redis` — **按前缀划定范围**：`SCAN` 迭代 `{CACHE_PREFIX}*` 并只 `UNLINK` 这些键。在共享实例上安全（已在与 WordPress 对象缓存共享的 Redis 上验证——其他键未受影响）。前缀为空时拒绝执行。
- `memcached` — ⚠️ `MemcachedStore::flush()` 会发出整个服务器范围的 `flush_all`。在**共享的** memcached 上这会清掉其他应用的缓存——在那里请跳过它（测试套件刻意如此做）。

## 建立在缓存之上的东西

| 使用方 | 键 | TTL 旋钮 |
|---|---|---|
| [会话](sessions.md) | `tgbase:session:{chatId}` | `SESSION_TTL`（默认 3600，最小 60） |
| [翻译](i18n.md) | `lang:{lang}`、`lang:missing_keys` | `LANG_CACHE_TTL`（默认 60） |
| [按钮定义](buttons.md) | `buttons:all` | `BUTTONS_CACHE_TTL`（默认 300），关闭开关 `BUTTONS_CACHE_ENABLED` |
| 插件发现 | （内部） | `PLUGIN_CACHE_ENABLED` + `PLUGIN_CACHE_TTL` |

## 实测验证（P-CACHE，2026-10-11）

| 套件 | 结果 |
|---|---|
| 17 项检查的进程内套件 × `array` / `file` / `memcached`（PHP 8.5） / `redis` | 全部 **PASS** |
| 在 PHP 8.4 上请求 memcached（无扩展） → 优雅回退 `array` | **PASS**（已文档化的回退链） |
| 跨进程矩阵（`set` 进程 → `get` 进程）：标量、嵌套数组、计数器 5→6、`remember` 返回*植入的*随机数、会话 id + 载荷连续性、`lang:fa` 载荷、`buttons:all` 载荷 | `file`、`redis`、`memcached` 上 **9/9 PASS**；`array` 上恰如文档所述未命中 |
| 后端检查 | redis 键带实时 TTL 倒计时；memcached 在 `tgbase_:` 下的原始键；file 存储带可见的过期时间戳 |
| 真实管道：`/cache demo hello`（分发 1）→ `/cache demo`（分发 2，全新进程）→ `demo = hello`；`/session-set`/`/session-get` 同样 | **PASS** |

这次实测发现并修复了一个真实缺陷：**`CacheRedisStore::flush()` 在第一个空的 MATCH 页上就终止了 SCAN 循环**（在共享/大型键空间中很常见）——什么也没删却返回 `true`（CHANGELOG 中的 P-CACHE-F001）。
