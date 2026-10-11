# Caching — CacheManager

One static API over four interchangeable drivers, configured entirely through `.env`. Sessions, translations, button definitions and (optionally) plugin discovery all ride on this layer — switching the driver changes the persistence story of the whole framework without touching code.

```php
use alirezax5\TelegramBase\App\Cache\CacheManager;
```

> Every claim in this document was verified live (2026-10-11) against real backends on a production server: the 17-check suite ran green on `array`, `file`, `memcached` (PHP 8.5) and `redis`, plus a 9-point cross-process persistence matrix for each persistent driver. See the *Live verification* section at the bottom and [CHANGELOG](../../CHANGELOG.md).

## Drivers

| `CACHE_DRIVER` | Backend | Cross-process | Tags | Notes |
|---|---|---|---|---|
| `array` (default) | per-process memory | ✗ | ✗ | zero-config; state lives exactly as long as the process |
| `file` | disk, `CACHE_PATH` | ✓ | ✗ | no extra services; hashed 2-level dirs; PHP-serialized payloads |
| `redis` | phpredis ext (`redis.so`) | ✓ | ✗ | JSON payloads; recommended for multi-process deployments |
| `memcached` | `memcached` ext + server | ✓ | ✗ | JSON payloads; volatile by nature |

**Fallback chain (verified):** if the requested driver is unavailable — extension missing, server unreachable, auth failed, path not writable — the framework logs a warning and **falls back to `array`** instead of failing boot. Requesting `memcached` on a PHP without the ext produced exactly this: `driver=array`, everything else unchanged. Check what you actually got with `CacheManager::getDriver()`.

> ⚠️ The `array` driver is process-local. A polling daemon is *one* long-lived process, so `array` "works" there; but webhook (per-request FPM workers) and queue workers (one process per worker) never share state. If sessions, counters or `remember()` need to survive across messages/processes, use `file`, `redis` or `memcached`. This was demonstrated live: values written in process A and read in process B — persist on all three, `<MISS>` on `array` (by design).

## Configuration (.env)

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

| Method | Behavior |
|---|---|
| `get($key, $default = null)` | value or default on miss/expiry |
| `put($key, $value, ?int $ttl = null)` | store; `null` ttl → `CACHE_TTL` |
| `remember($key, callable $cb, ?int $ttl = null)` | return cached value or execute `$cb`, cache the result |
| `has($key)` | exists and not expired |
| `forget($key)` | delete one key |
| `flush()` | delete **all keys of this framework's namespace** — see per-driver notes |
| `increment($key, $by = 1)` / `decrement($key, $by = 1)` | counters |
| `getDriver()` / `isInitialized()` / `tagsSupported()` | introspection |
| `putWithTags()` / `getWithTags()` / `rememberWithTags()` / `flushTags()` | tag-style API that works on every driver (prefix-based fallback) |
| `tags([...])` | native tagged cache — **throws** unless the driver supports tags (none of the four do) |

### TTL semantics (verified with real waits)

`put('k', 'x', 2)` → `has` true → after 3 s → `has` false, `get` returns default. Backend TTLs match config exactly: a 300 s entry showed `ttl=297s` under `redis-cli`, a 3600 s session `ttl=3597s`, a 60 s translation cache `ttl=57s`. Expired entries in `file`/`redis` are removed lazily (on access) or by the backend (`redis` setex).

### Storage formats

- **redis** — `setex` with `json_encode(JSON_UNESCAPED_UNICODE)`. Payloads must be JSON-round-trippable: scalars and arrays yes; PHP objects come back as arrays; no resources.
- **memcached** — JSON via the same store family; values transparently (un)serialized by the ext.
- **file** — PHP `serialize()` payloads, expiry stamp first, under `CACHE_PATH/ab/cd/<sha1>`.
- **array** — plain PHP variables; nothing touches disk or network.

### Key layout (raw backend view)

| Driver | Raw key for `put('lang:fa', …)` with `CACHE_PREFIX=tgbase_` |
|---|---|
| redis | `tgbase_lang:fa` |
| memcached | `tgbase_:lang:fa` (Illuminate appends `:` to the prefix) |
| file | `CACHE_PATH/<h1>/<h2>/<sha1>` |

### Counters — one nuance (verified)

`increment()` on a **missing** key: `array`/`file`/`redis` create it (`incBy`), `memcached` returns `false` (Illuminate sends no initial value). Portable pattern: seed first — `CacheManager::put($k, 0, $ttl); CacheManager::increment($k);`

### flush() — per-driver reach

- `array` — this process only.
- `file` — everything inside `CACHE_PATH` (dedicated dir; safe).
- `redis` — **prefix-scoped**: `SCAN`-iterates `{CACHE_PREFIX}*` and `UNLINK`s only those keys. Safe on shared instances (verified on a Redis shared with WordPress object caches — other keys untouched). Refuses to run with an empty prefix.
- `memcached` — ⚠️ `MemcachedStore::flush()` issues a server-wide `flush_all`. On a **shared** memcached this nukes other applications' cache — skip it there (the test suite deliberately does).

## What rides on the cache

| Consumer | Key | TTL knob |
|---|---|---|
| [Sessions](sessions.md) | `tgbase:session:{chatId}` | `SESSION_TTL` (default 3600, min 60) |
| [Translations](i18n.md) | `lang:{lang}`, `lang:missing_keys` | `LANG_CACHE_TTL` (default 60) |
| [Button definitions](buttons.md) | `buttons:all` | `BUTTONS_CACHE_TTL` (default 300), off switch `BUTTONS_CACHE_ENABLED` |
| Plugin discovery | (internal) | `PLUGIN_CACHE_ENABLED` + `PLUGIN_CACHE_TTL` |

## Live verification (P-CACHE, 2026-10-11)

| Suite | Result |
|---|---|
| 17-check in-process suite × `array` / `file` / `memcached` (PHP 8.5) / `redis` | **PASS** on all |
| memcached requested on PHP 8.4 (no ext) → graceful `array` fallback | **PASS** (documented chain) |
| Cross-process matrix (`set` proc → `get` proc): scalar, nested array, counter 5→6, `remember` returns the *planted* random, session id + payload continuity, `lang:fa` payload, `buttons:all` payload | **9/9 PASS** on `file`, `redis`, `memcached`; `array` misses exactly as documented |
| Backend inspection | redis keys with live TTL countdown; memcached raw keys under `tgbase_:`; file store with visible expiry stamps |
| Real pipeline: `/cache demo hello` (dispatch 1) → `/cache demo` (dispatch 2, fresh process) → `demo = hello`; `/session-set`/`/session-get` same | **PASS** |

One real defect was found and fixed by this exercise: **`CacheRedisStore::flush()` terminated its SCAN loop on the first empty MATCH page** (common on shared/large keyspaces) — deleting nothing while returning `true` (P-CACHE-F001 in the CHANGELOG).
