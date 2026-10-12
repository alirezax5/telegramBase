# Changelog

All notable changes to TelegramBase are documented here.
Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning follows [SemVer](https://semver.org/) (`vMAJOR.MINOR.PATCH` git tags).

## [Unreleased]

### Added — Mini App `initData` validation (2026-10-12)

- **`App\MiniApp\InitData`** — server-side validator for Telegram Mini App identity strings, implementing the official HMAC-SHA256 scheme (`secret = HMAC_SHA256("WebAppData", BOT_TOKEN)` → compare `hash` with `hash_equals`). The bot token is auto-detected from the environment, so a single-bot endpoint is one call: `InitData::validate($raw)`; multi-bot deployments pass the owning token explicitly. API: `validate()` (full result: `user`, `auth_date`, `start_param`, `fields`, `hash`, or `error` — `bad_signature` / `auth_date_expired` / …), `valid()`, `user()` and `build()` (development-only signed fixture generator for curl-testing endpoints before the frontend exists).
- **`max_age` freshness window** — `auth_date` older than `max_age` seconds is rejected (default `86400`, `0` disables). `initData` never expires on its own; the window is the replay defense.
- **Docs** — `docs/en/mini-apps.md` + `docs/fa/mini-apps.md` (full guide: frontend→backend flow diagram, endpoint pattern accepting form/JSON, API table, `Keyboard::reply()->webApp()` entry points, local testing with `build()`, security checklist, algorithm reference) plus compact localized pages for ar/zh/ru/fr/pt; hub index and llms manifests updated.
- **Runnable example** — `examples/MiniAppEndpoint.php`: one file serving both the Mini App page and a POST endpoint that validates `initData` (form or JSON body) and answers with trusted identity JSON.
- **Cross-language test pin** — `tests/Unit/MiniAppInitDataTest.php` (9 cases): validates a reference `initData` generated **independently in Python**, so a silent HMAC key/message argument swap can never pass again; plus tampered-hash, tampered-payload, wrong-token, expiry, missing-hash, env auto-detect and helper coverage. The same validator also ships standalone in the underlying library as `telegramBotApiPhp\WebApp\InitData` (explicit token, no env lookup). Suite: 394 pass / 0 fail.

### Added — base upgrade batch: API trace, webhook CLI, update replay, MenuFlow, media, keyboard payloads (2026-10-12)

Distilled from live operation of the payam support-relay bot (TM-25 feedback + the two "message never arrived" outages):

- **`LOG_API_TRACE=1`** — one delivery line per outgoing Bot API call through a new static `Telegram::onApiCall()` hook in the underlying library (compact info: method, chat_id, duration, ok/fail, error summary, retry_after — never payloads; covers every `BOT_EXTRA_BOTS` instance). Gated by `LOG_API_TRACE_POLL` for getUpdates noise. New `App\Bot\ApiTrace` installs the logger during `BotManager` boot. Motivation: the app log previously recorded only boot lines, so "the message never arrived" required a bespoke per-bot trace file (TgTrace) to debug.
- **`tgbase webhook:info|set|del`** — webhook management from the CLI; `set` derives `secret_token` from `BOT_WEBHOOK_SECRET` and defaults `allowed_updates` to the recommended set **including `edited_message`**; `info` prints url/pending/last_error and warns loudly when the live webhook's `allowed_updates` misses `edited_message` (the silent `onEditedMessage()`-never-fires trap).
- **`tgbase simulate <fixture.json|dir> [--dry]`** — replay saved Telegram updates through the real pipeline (or parse-only with `--dry`, which reports the hook that would fire, e.g. `onEditedMessage()`). Sample fixtures ship in `tests/Fixtures/updates/` (start / edited_message / callback_query) and `examples/fixtures/`.
- **`Keyboard` request-shaped payloads + Mini App button** — `Keyboard::remove()` (TM-25 keyboard hygiene: attach to the next message whenever a custom keyboard must disappear), `Keyboard::forceReply($selective, $placeholder)`, `Keyboard::reply()->webApp($text, $url)` (KeyboardButtonWebApp).
- **`App\Shared\Media`** — `download($tg, $fileId, ?dir, ?name)` (getFile → `getFileUrl()` → stream to `storage/downloads` or `MEDIA_DOWNLOAD_DIR`) and `send($tg, $type, $chatId, file_id|local_path, $opts)` (auto-CURLFile; photo/video/audio/voice/video_note/animation/document/sticker; unknown types throw).
- **`App\Conversation\MenuFlow`** — a DB-free screen machine for panel-style plugins: named screens (text/kb as closures), label→screen navigation, built-in «بازگشت»/«منوی اصلی» with `parent` walking, `'parse'` input screens routed to `onInput()`, CacheManager-backed per-chat state (`MENUFLOW_STATE_TTL`), and `exit()` that sends `remove_keyboard`. Extracted from the live support panel after the owner's TM-25 report that hand-rolled panels shipped input screens without a visible cancel and left dead keyboards behind.
- **Lazy `__()` filename.key loader** — a dot-key miss tries `Language/{lang}/{prefix}.{php,json}` exactly once and merges it, so subfiles added after the language cache was built resolve transparently (no reload, no retry storms; unknown prefixes fall back to the default).
- **Library hook** — `telegramBotPhp` gained the static `onApiCall()` observer (fired once per `bot()` call, tracing wrapped so it can never break delivery); framework vendor overlaid in-place until the next library tag.
- **New docs** — `docs/{en,fa}/media.md`, `docs/{en,fa}/menu-flows.md`, `docs/{en,fa}/recipes.md` (six production patterns: relay+edit sync, non-stranding panels, private-only panel/reply-only group, delivery debugging incl. the **`.env` must stay FPM-readable** trap — a 640 root-owned `.env` surfaces as "BOT_TOKEN is not set" HTTP 500s that never reach the app log — keyboard hygiene checklist, Mini App entry points), release summaries for ar/zh/ru/fr/pt (`release-2026-10.md`), plus appended sections in webhook/testing/configuration/buttons/i18n/features (en/fa). Hub index updated.
- **New runnable examples** — `examples/PanelBot.php` (MenuFlow admin panel), `examples/RelayEditSyncBot.php` (user↔admin relay with live edit sync + JSON id-map), `examples/ConversationFlow.php` (Step API with backable/menu), `examples/KeyboardBuilder.php` (inline/reply/remove/forceReply/webApp), `examples/MediaFiles.php` (download + send patterns).
- **New tests** — `tests/Unit/BaseUpgradeTest.php` (11 offline cases: keyboard payloads, webApp button, lazy i18n load, MenuFlow routing, simulate dry-run, webhook CLI offline paths, ApiTrace callback contract, Media discriminator). Suite: 362 pass / 0 fail.


### Added — webhook 403 hardening & log timezone (from a real production outage)

- **Actionable `WebhookDispatcher` warnings.** Both 403 sources now log the *fix*, not just the fact: the IP-reject warning names `WEBHOOK_CHECK_IP=false` for proxy/CDN setups, and the secret-mismatch warning says to re-run `setWebhook()` with the current `BOT_WEBHOOK_SECRET`. Reason: `getWebhookInfo` only ever reports "Wrong response from the webhook: 403 Forbidden" — the app log line is the only place that names the failing layer, and a bare one gives no hint. The incident that motivated this (2026-10-12): **secret drift** — a routine `setWebhook` re-registration went out without the original `secret_token`, so both production bots silently rejected every real delivery while `.env`, code and docs all looked correct; the pair stayed dark until a live test exposed it.
- **`LOG_TIMEZONE`** (optional) pins Monolog log timestamps to one zone. Web (PHP-FPM) and CLI/cron workers of one deployment frequently run under different `php.ini` timezones, which mixed two offsets into the same log file and made incident timelines unreadable. `LoggerConfig::fromEnv()` resolves it into a new readonly `timezone` property (`null` = runtime default; invalid identifier falls back to `null` too — a typo in an observability knob must not kill the boot), `LogHandler` passes it to the Monolog constructor. Covered by the new `tests/Unit/LoggerTimezoneTest.php`; `.env.example` documents the key.
- **Docs — webhook 403 playbook** ([webhook.md](docs/en/webhook.md)): the one-request curl proof that separates secret drift from proxy/IP rejection; the recovery rule ("`.env` is the single source of truth — every `setWebhook` re-registration copies `secret_token` from it verbatim"); and the **`allowed_updates` ↔ plugin-handler gating** warning (`onEditedMessage()` etc. never fire — with no error anywhere — if the webhook was registered without that update type). Troubleshooting table rows added for both plus mixed log offsets.
- **Docs — patterns distilled from the live support-relay deployment** (2026-10-12): `editMessageText()` without `reply_markup` strips the message's inline keyboard — capture the original markup and pass it through on every edit ([buttons.md](docs/en/buttons.md)); every input-taking state must show a **visible** Back/Cancel exit — text-only `/cancel` is undiscoverable and leaves admins stranded on a bare prompt ([buttons.md](docs/en/buttons.md)); cloning a deployment for A/B testing onto the SAME database collides on un-namespaced app tables — clone the database too ([multi-bot.md](docs/en/multi-bot.md)).
- **`tests/migration_check.php`** — standalone sqlite validation of the users migrations (fresh full schema + legacy id-only table extended + idempotency round-trip, via the real `MigrationRunner`) now ships with the test suite instead of living only in fix bundles.

### Added — Conversation engine promoted to the core (multi-step flows)

- **`App/Conversation/`** — the conversation engine that was battle-tested live in the demo apps is now framework core: `Conversation` (abstract flow base), `Step` (declarative step builder), `ConversationManager` (priority-2 engine plugin: routing, validation/retry, skip, branching, timeout) and `ConversationStore` (Eloquent row per active chat).
- **Declarative steps with both keyboard styles.** `Step::text()/photo()/callback()/display()`; fluent modifiers `ask()/withAsk()/pattern()/validate()/retry()/optional()/when()/buttons()/columns()/onDone()/backable()/menu()/compute()`. Text steps can be answered with a temporary **Reply Keyboard** — the tapped label is reverse-mapped to the canonical value through the translator (locale-independent stored values; raw value accepted too). `buttons()` accepts a **closure** for dynamic lists computed from the answers so far.
- **Navigation inside a flow** — `backable()` renders a *Back* button (jumps to the previous input step of the effective path, prunes the target answer and everything after it, skips `display` steps; on the first input step there is no back target so the button is not rendered) and `menu()` renders a *Main menu* exit button. Nav works identically for inline (`conv:{flow}:__nav__:{back|menu}`) and Reply Keyboard (locale-reserved labels via `navBackTexts()/exitTexts()`; manual typing accepted).
- **Display/compute steps** — `Step::display()` + `compute(fn(array $answers))` render a computed message (raw string or `[key, replacements]`) and auto-advance; recomputed after navigation.
- **Core plugins (`CORE_PLUGINS`).** `PluginHandler` now registers plugin classes that live outside the scanned `Plugin/` dir from a comma-separated `CORE_PLUGINS` env (e.g. the Conversation engine). Core list is tracked separately from file hashes in the plugin cache; a changed list invalidates the cache; `getStats()` reports the core count. This also gives apps a general mechanism to register shared/plugin-pack classes without touching `Plugin/`.
- **Idempotent migration** `2026_10_12_000003_create_conversations_table.php` creates the `conversations` state table (`bot_id` for multi-bot isolation, `chatid`, `flow`, `flow_class`, `step`, `answers` JSON, `status active|done|cancelled`, timestamps; `updated_at` is the timeout basis).
- **Core language strings** shipped as `Language/{fa,en}/conv.php` + `conv.json` (`conv.cancelled/timeout/busy/use_buttons/stale/skip_not_allowed/retry_default/nav_back/exit_menu`) for both `php` and `json` drivers.
- **App hooks** — `Conversation::$langResolver` (per-user language without coupling the core to an app model; still falls back to `Database\Users::langOf` automatically when that method exists) and `ConversationManager::$mainMenuMarkup` (markup for the exit-to-menu message).
- New documentation: **`docs/en/conversations.md`** + **`docs/fa/conversations.md`** (full guide: enabling via `CORE_PLUGINS`, writing flows, step reference, nav rules, keyboard-cycle rules, control commands, storage) and README table rows (en/fa). `.env.example` documents `CORE_PLUGINS`.
- New offline test suite `tests/Unit/ConversationTest.php` (Step API, `textMatches`, `applyLang` hook + fallback, `mainMenuMarkup` hook, `CORE_PLUGINS` registration incl. garbage-tolerant parsing, store shape).

### Fixed — post-demo core fixes (fallback phase, role tiers, config footguns, i18n)

- **Native fallback phase.** `App/Update/UpdateState.php` (per-update `markHandled()/isHandled()/handledBy()`, reset in `runAll()`) + `App/Plugin/Contract/FallbackInterface.php` (`onFallback`). `PluginHandler` indexes fallback plugins and runs a fallback phase after the main phase **only when no plugin marked the update handled** — the "command not found" reply is now a first-class plugin contract instead of a per-app convention. `getStats()` reports fallback count; legacy plugin caches are invalidated once by the new cache shape.
- **Role tiers in the users layer.** `Database\Users` gains `ROLE_*` constants + `ROLE_HIERARCHY`, `rankOf()/roleOf()/setRole()/hasRole()/isAtLeast()/isSuperAdmin()` (superadmins from `ADMINS_WHITELIST`; DB errors fail-safe) and `checkAndInsert(chatid, ?lang, ?extra)` for idempotent upsert. New opt-in **`RoleMiddleware`** (fail-closed) for `#[Role('admin')]`-style gating. Schema: `create_users_table` ships the full modern schema for fresh installs and a new idempotent **`extend_users_table`** migration upgrades existing tables (`chatid` nullable + unique, `username`, `first_name`, `last_name`, `lang`, `role`, `active`, `status`, `registered_at`, `command`, `data`, `type`).
- **`PLUGINS_DIR` footgun defused.** The historical default `'/Plugin/'` was interpreted as an absolute filesystem path (scanning `/Plugin` at the filesystem root and silently finding nothing). Default is now the relative `'Plugin'`; absolute values that don't exist are auto-resolved against the project root with a warning. `.env.example` updated.
- **`LOG_ENABLED` now defaults to `true`** — silent failures (log file empty while errors happen) were the #1 deployment trap in real installs. (`run.php` still pins `'false'` for tests.)
- **i18n gaps.** The `json` driver now loads flat `{lang}.json` main files (previously only `{lang}/*.json` sub-files worked) and falls back to the opposite extension; both `#key` and `:key` replacement syntaxes work everywhere (`applyReplacements`).

Practical cache-layer verification release: the whole caching subsystem (CacheManager, sessions, translations, button definitions) was exercised live against real backends on a production server — one real cache bug and one wiring bug were found, fixed and re-verified.

### Added — multilingual documentation

- The complete documentation set (17 documents) now ships in **seven languages** under per-language folders: `docs/en/` (canonical), `docs/fa/`, `docs/zh/`, `docs/ru/`, `docs/fr/`, `docs/pt/`, `docs/ar/` — every folder is a full, self-contained copy of the docs.
- Language hub added at `docs/README.md`; per-language READMEs (`README.md`, `README.fa.md`, `README.zh.md`, `README.ru.md`, `README.fr.md`, `README.pt.md`, `README.ar.md`) with a language switcher at the top of each.
- `llms.txt` / `llms-ctx.txt` updated for the new `docs/en/` paths and now mention the available documentation languages.

### Added — Telegram Managed Bots (Bot API 9.6)

- **`managed_bot` updates are no longer silently dropped.** The `PluginHandler` update-type whitelist now includes `managed_bot` (Bot API 9.6, 2026-04-03): a `ManagedBotUpdated` payload dispatches to `onManagedBot($managedBot, $tg)` with the full `before`/`after` chain, so plugins can react to child-bot creation, token and owner changes.
- **Managed Bots in `BotManager`** — child-bot tokens are fetched live from Telegram and never stored on disk: `registerManagedBot($userId, $name)` (via `getManagedBotToken`), `registerManagedBotFromUpdate($managedBot)` (one call from `onManagedBot`), `refreshManagedBot($userId, $name)` (rotation via `replaceManagedBotToken` — revokes the old token immediately), and `managedBotAccessSettings($userId)`. Failures throw a clean `RuntimeException` carrying the API error text; tokens never reach the logs.
- **`Keyboard::reply()->requestManagedBot($text, $requestId, $suggestedName, $suggestedUsername)`** builds the Bot API 9.6 `KeyboardButtonRequestManagedBot` (private chats; requires enabling *management of other bots* for the manager bot in the @BotFather Mini App).
- **docs/en/multi-bot.md** gained two sections: a token-management strategy matrix for *many bots on one file* (static `.env` registry vs dynamic `add()` vs Managed Bots) and the full Managed Bots flow with prerequisites and token-hygiene notes. Verified by the new TM-1 (23/23 PASS) and TM-2 (8/8 PASS) live harness runs — which also caught the stale deployment below.

### Added — webhook multi-bot routing (`secret_token`), live-proven

- New doc sections in `docs/en/multi-bot.md` (+ all 6 translations) covering: the
  `secret_token` webhook routing pattern (any number of bots from ONE webhook file —
  bot tokens never appear in URLs; verified live: two real bots, 7/7 deliveries,
  correct identity every time), where to keep tokens/secrets (`.env` vs encrypted DB
  registry vs Managed Bots vs HMAC-derived secrets), per-bot data isolation rules
  (cache/session/offset/queue/files/logs/DB namespaced by bot id), and Managed Bots
  live notes (BotFather toggle → `can_manage_bots: true`;
  `KeyboardButtonRequestManagedBot.request_id` must be a Number; child token fetched
  live via `getManagedBotToken`).
- Token transmission facts documented from live evidence: the Bot API token is read
  ONLY from the URL path `/bot<token>/METHOD` — headers, query string and JSON body
  are never consulted (8/8 probe suite); Telegram→bot webhooks carry only
  `X-Telegram-Bot-Api-Secret-Token` (the secret set in `setWebhook`), never the bot
  token.

### Added — guest mode dispatch (Bot API 10.0)

- `guest_message` added to the `PluginHandler` update-type whitelist: a guest query
  now dispatches to `onGuestMessage($message, $tg)` (previously silently dropped).
  Proven with a synthetic update (before → dropped, after → hook fired) and
  regression-checked against `managed_bot` dispatch.

### Fixed — deployment

- The production runtime of the test bot was still running a **stale `App/` tree** missing seven release files: P-REL-B01 (`BOT_EXTRA_BOTS` from `.env`), P-REL-B05 (`safeLoad`), P-REL-B06 (`Config` guards), P3.3.1-F006 (RabbitMQ ack-late + worker ack-after-process) and P3.3.1-F008 (webhook SAPI guard) among them. The live TM-1 run failed exactly the checks those fixes cover — extra bots defined in `.env` were never registered (raw `getenv()` path). After backing up and syncing the seven files, the full suite passes 23/23. If you deploy by hand, diff your runtime `App/` against the release tree before shipping.

### Fixed — pre-release deep review (P-REL)

- **Multi-bot registration ignored `.env` (MEDIUM).** `BotManager` read `BOT_EXTRA_BOTS` with raw `getenv()`, which never sees values defined in `.env` (Dotenv `createImmutable` does not call `putenv`) — yet the docs recommend configuring it in `.env`. Extra bots defined there were silently never registered. `bot()` now reads through `EnvHandler` (`$_ENV` first, real environment second) — both sources work (P-REL-B01). The scheduler's `SCHEDULE_FILE` had the same defect and is fixed the same way (P-REL-B02).
- **Boot crashed without a `.env` file (MEDIUM).** `EnvironmentBootstrap` called Dotenv's `load()`, which *throws* when no `.env` exists — deployments configured exclusively through real environment variables (systemd `Environment=`, `docker -e`, Kubernetes) died at boot with `Unable to read any of the environment file(s)`. Now uses `safeLoad()`: `.env` loads when present, is optional when absent (P-REL-B05). This was also the root cause of the 18 pre-existing test-suite failures (`SessionTest` ×10, `BackwardCompatTest` ×4, `MiddlewareWiringTest` ×4 — the boot aborted before `Config` was initialized); the offline suite now reports **224 pass / 0 fail / 12 skip** in a fresh clone.
- **`Config` accessors hid the real error (LOW).** Calling `Config::bot()` etc. before a failed/incomplete boot produced `Attempt to read property on null` warnings followed by a bare `TypeError`. Accessors now fail fast with a readable `RuntimeException` pointing at the boot problem (P-REL-B06).
- **`DB_DRIVER` was documented but not honored (LOW).** `Database/Connections.php` hardcoded `driver: mysql` while `docs/configuration.md` promised "any PDO driver (sqlite for local dev)". The driver is now read from `DB_DRIVER` (default `mysql` — existing deployments unaffected) (P-REL-B03).
- **Framework pinned a buggy library version (MEDIUM).** `composer.json`/`composer.lock` constrained `alirezax5/telegram-bot-php` to `^3.0` / `3.0.0` — every fresh install got the release with the broken `getChatMember()` (fatal), incomplete `prepareBotData()` JSON encoding, stray `null` parameters and the cURL timeout leak. The constraint is now `^3.3` with the lock pointing at 3.3.0 (the release containing all fixes) (P-REL-B04).
- **Documentation drift (LOW).** `docs/features.md` had a malformed `[multi-bot.md]` link; `docs/configuration.md` still listed the nonexistent `database` cache driver, claimed the wrong `LANG_DRIVER` default (`php` → actually `json`, `.env.example` correctly sets `php`), and omitted `BOT_EXTRA_BOTS`, `BUTTONS_CACHE_ENABLED`, `CACHE_TTL`, `SCHEDULE_FILE` and the env-only deployment mode; four CHANGELOG doc links missed the `docs/` prefix; `.env.example` carried stale keys for removed drivers (`apc`, `database` cache, unused Redis client knobs) and was missing seven keys the code actually reads. All corrected (P-REL-D01…D05).

### Fixed

- **Redis cache `flush()` could silently delete nothing (MEDIUM).** `CacheRedisStore::flush()` iterated `SCAN` with `while ($keys = $redis->scan(...))` — on a shared/large keyspace, `SCAN ... MATCH <prefix>*` routinely returns *empty but non-terminal* pages (the cursor advances past non-matching keys), and an empty array terminated the loop after the first page. Result: matching keys were left behind while `flush()` returned `true`. Discovered live: 11 `tgbase_*` keys survived a "successful" flush on a Redis shared with WordPress object caches. The loop now continues while the SCAN cursor is non-zero (P-CACHE-F001); re-verified — `flush()` removes every prefixed key, other applications' keys untouched.
- **`BUTTONS_CACHE_ENABLED=false` was ignored (LOW).** `ButtonConfig` read the flag into config, but `ButtonManager` never consulted it — the button-definition cache was always on. Now the flag genuinely disables caching (definitions re-read from files per use). Verified live in both states: `flag=false → cached=false`, `flag=true → cached=true` (P-CACHE-F002).

### Added

- **Dedicated docs for the four toolbox features**: [docs/caching.md](docs/en/caching.md) (drivers, config, TTL semantics verified with real waits, per-driver `flush()` reach, key layout, fallback chain), [docs/sessions.md](docs/en/sessions.md), [docs/i18n.md](docs/en/i18n.md) (incl. the `LANG_DRIVER` format-mismatch pitfall), [docs/buttons.md](docs/en/buttons.md). All cross-linked from [docs/features.md](docs/en/features.md) and the README docs index.

### Verified — practical cache test suite (P-CACHE)

Ran against real backends on the production server (Redis with `requirepass`, shared with other apps; memcached; plain disk):

- 17-check in-process suite per driver (`array`, `file`, `memcached`, `redis`): scalar/array/bool/Unicode round-trips, `has`/`get`-default, real-TTL expiry (2 s wait), `remember` executing once, counters, `forget`, tags-fallback API, prefix-scoped `flush`, idempotent re-init — **all PASS**.
- Graceful degradation: requesting `memcached` on a PHP without the extension falls back to `array` with a warning (documented chain) — **PASS**.
- Cross-process persistence matrix (write in process A → verify in fresh process B): scalar, nested array, counter 5→6, `remember` returning the *planted* value, session id + payload continuity, `lang:fa` translation payload, `buttons:all` definitions — **9/9 PASS on `file`, `redis`, `memcached`**; on `array` process B misses exactly as documented.
- Backend-level inspection: redis keys with live TTL countdowns (297 s of 300 s, 3597 s of 3600 s session, 57 s of 60 s lang cache); memcached raw keys under `tgbase_:`; file store with visible expiry stamps.
- Real pipeline end-to-end: `/cache demo hello` dispatched through `Processor` + `CacheTestPlugin` → value persisted; `/cache demo` in a fresh process → `demo = hello`; same for `/session-set`/`/session-get` — **PASS**.

## [v2.4.0] — 2026-10-11

Reliability release: five framework defects fixed (including two HIGH-severity data-loss/availability bugs found and reproduced during end-to-end crash testing), a new offline test suite + CI, and a documentation overhaul.

### Fixed — framework

- **RabbitMQ queue: worker crash no longer loses the job (HIGH).** `RabbitQueue::pop()` acknowledged messages *before* they were processed, so killing a worker mid-processing permanently destroyed the in-flight job (at-most-once). The driver now holds the delivery tag and acknowledges **after** processing — a crash makes the broker redeliver the message (**at-least-once**). Corrupted payloads are still acked-and-discarded immediately so they can't poison the queue. Re-verified end-to-end with real SIGKILLs: post-fix, an in-flight job is redelivered to the next worker.
- **Webhook entry no longer hangs the web worker on CLI-only modes (HIGH).** `Core::run()` only routed `webhook_direct` to the webhook dispatcher; every other `BOT_MODE` fell through to the CLI switch — so pointing Telegram's webhook at a bot configured e.g. `update_direct` silently started an **infinite `getUpdates` loop inside the PHP-FPM worker** (Telegram: `Read timeout expired` → endless retries; FPM: worker killed at `request_terminate_timeout` → 502). A SAPI-aware guard now routes the HTTP path explicitly (see *Added*), and CLI-only modes fail fast with HTTP 500 + an explanatory message instead of hanging.
- **Photo (and all list-shaped) data destroyed in queue/webhook paths (HIGH).** `Processor::toObject()` converted *every* array to an object recursively, destroying list-shaped fields — `photo` sizes, message `entities`, `new_chat_members`, `inline_keyboard` rows — for updates arriving via queue drivers or webhook JSON. List arrays are now preserved as arrays (shape-preserving normalization); verified with a real photo end-to-end through the full pipeline.
- **RabbitMQ driver could never connect with default settings (HIGH).** `ConnectionManager` passed `'default'` as the AMQP login mechanism — not a valid value (RabbitMQ supports `AMQPLAIN`/`PLAIN`), so the handshake was rejected and the socket died (`Broken pipe or closed connection`), making the whole driver unusable. Now uses `AMQPLAIN` (library default).
- **Queue push broken in polling→queue modes (HIGH).** `QueueManager::push()` type-errored when the polling fetcher handed it Telegram SDK update *objects* (JSON/Redis drivers contract plain arrays). Objects are now normalized to arrays once, at the `push()` choke point.
- **Absolute `PLUGINS_DIR` doubled into a nonexistent path (MEDIUM).** `PluginConfig::fromEnv()` always prepended the project base, turning an absolute `PLUGINS_DIR` into `/project//absolute/path`. Absolute paths are now used as-is (also enables multi-bot deployments from one codebase).
- **Middleware crash on callback queries.** `RateLimitMiddleware` and `AdminGuardMiddleware` used non-nullsafe access (`$update->message->chat->id`) and crashed on updates without a message. Now nullsafe across `message`, `edited_message`, `channel_post`, `callback_query` and `chat_member`.
- **Logs written to the wrong directory.** `LogHandler` resolved `LOG_DIR` against the CWD, so `bin/tgbase` from another directory scattered logs around; also crashed when config wasn't booted. Now resolves against the project base and is a no-op without config.
- **`Session::regenerate()` fatal** ("Cannot modify readonly property") — session identifiers are no longer readonly.
- **`Keyboard::row()` on an empty builder** created rows at a wrong index — first-row edge case fixed.
- **Retry queue ignored Telegram's `retry_after`** — it now honors the server-provided delay exactly when present, falling back to exponential backoff.
- **Webhook payload validation refactored** for testability (`dispatchIntoResponse()` returns a DTO; `dispatch()` stays the thin HTTP wrapper) and uses the shared queue bootstrap instead of re-reading config per request.
- **CLI**: `doctor` and `plugins` commands added; `Maker` normalizes plugin paths to PSR-4 (`users/register` → `Plugin\Users\Register`); `migrate` commands boot without `BOT_TOKEN`/`BOT_MODE`; `DatabaseConfig` properly gated behind `DATABASE_ENABLE`.

### Added

- **`QueueManager::ack()` / `RabbitQueue::ack()`** — post-processing acknowledgement API backing the at-least-once RabbitMQ semantics. Drivers without broker-side acks treat it as a no-op.
- **`Core::isHttpSapi()`** (public, static, testable) + HTTP-mode routing in `Core::run()`: `webhook_queue` → validate + enqueue + 200; `update_direct` → process inline + warning; everything else → HTTP 500 fail-fast with guidance.
- **Offline test suite** (`tests/run.php` — 25 files across Unit/Integration, dependency-free runner with `--filter`), standalone smoke test (`tests/smoke.php`), benchmarks, and opt-in **live Telegram tests** (`tests/Live/`, env-gated, self-cleaning, tagged messages).
- **GitHub Actions CI** — `tests.yml` (PHP 8.1–8.4 matrix: composer validate/audit + offline suite) and manual `live-tests.yml` (token via repository secret only). The `branches:` trigger syntax (previously malformed) is fixed.
- **Documentation restructured** — the monolithic README became a short intro + index; full guides now live in `docs/` (overview, architecture, getting-started, bot-modes, plugins, queue-pipeline, webhook, reliability, configuration, features, testing, troubleshooting).
- **`docs/reliability.md`** — the delivery-guarantee matrix and crash-test findings (SIGKILL at every pipeline stage) distilled into operator guidance, including idempotency recipes.
- **`llms.txt` / `llms-ctx.txt`** regenerated to match the new docs layout (LLM-oriented index + single-file context).
- **`.env.example`** — added missing queue keys (`QUEUE_MAX_SIZE`, `QUEUE_MAX_RETRIES`, `QUEUE_RETRY_DELAY`), corrected cache prefix, and documented webhook-secret strength guidance (32+ hex chars; it's the only inbound-auth layer when `WEBHOOK_CHECK_IP=false`).
- **`examples/HelloBot.php`** — complete example plugin (commands, inline keyboard, sessions, `tg_retryable`, callbacks).
- **`integration/TestBot/`** — real-bot integration harness (own plugins/middleware directory, runtime isolation) used to validate the framework against live Telegram traffic; shipped trimmed of session-specific scaffolding.

### Changed

- `webhook.php` / polling entry points are now dependency-free (they no longer wire the TestBot's logging middleware; TestBot remains a standalone opt-in harness).
- README rewritten around the questions a newcomer actually has (what is this, why does it exist, how do updates flow) with the `docs/` index as the entry point.
- Cache prefix default corrected from `balebase_` to `tgbase_` (leftover from the project this was forked from).

### Removed

- Session-specific test scaffolding: root-level debug drivers (`crash_test.php`, `debug_poll.php`, `restart_test.php`, `bot_polling.php`) and the crash/dispatch-debug plugins + scripts inside the TestBot harness. The permanent test coverage lives in `tests/` and CI.

### Verified — cron modes exercised live (P331-CRON)

Both cron modes were tested end-to-end with real crontab fires and real messages:

- `cronjob_queue` — a real message was fetched, pushed to RabbitMQ and processed
  inside the 50 s batch window (`Limited worker finished: 1 updates`), then the
  queue drained back to 0/0; the slot mutex proven (a second fire exits in ~33 ms
  while a worker holds the slot).
- `cronjob_update` — the first tick starts a self-healing daemon that survives
  minute boundaries and processes messages inline; on SIGTERM it shuts down
  gracefully within one long-poll cycle (~12 s), releases the slot, and the next
  tick restarts it. Cron acts as the supervisor; later ticks busy-exit in
  milliseconds — that is normal, not an error.
- Documented from live evidence in `docs/en/bot-modes.md`.

### Verified — multi-bot exercised live (P-MB)

- `/multibot` harness run with two real bots (`@emojiCustom_bot`, `@instaroonxbot`):
  identity ✔, isolation ✔, cross-send ✔ (239 ms), membership via bot #2 ✔ (13 ms),
  token stack ✔ — **5/5 PASS in 393 ms** (evidence: `runtime/p331/multibot.log`).
- Library upgraded 3.0.0 → 3.2.1 with three defect fixes overlaid (`callRaw()`
  availability, JSON parameter encoding, import fix), enabling the multi-bot
  scenarios above.

### Known issues (open, tracked)

- **F004 (MEDIUM, suite):** 18 pre-existing offline-suite failures (`SessionTest` ×10, `BackwardCompatTest` ×4, `MiddlewareWiringTest` ×4) — proven unrelated to the v2.4.0 fixes by A/B reversion runs; subsystem suites for queue/offset/retry/webhook are green. Under investigation.
- **Logger under FPM (observation):** framework logs can be sparse for webhook requests handled by PHP-FPM short-lived workers (CLI behaviour unaffected). Harness/echo-level evidence is reliable; treat framework logs as best-effort on the webhook path.
- **Security tip:** set `BOT_WEBHOOK_SECRET` to 32+ random hex characters — when `WEBHOOK_CHECK_IP=false` (Cloudflare/proxies), the secret is the only inbound authentication of the webhook endpoint.

## [v2.3.0] — 2026-08-05

Previous tagged release. See the git history for changes prior to this changelog's introduction.

[v2.4.0]: https://github.com/alirezax5/telegramBase/compare/v2.3.0...v2.4.0
[v2.3.0]: https://github.com/alirezax5/telegramBase/releases/tag/v2.3.0
