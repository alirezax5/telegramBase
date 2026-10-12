# Multi-bot — running two bots in one process

TelegramBase supports multi-bot operation at two levels: the underlying
[`telegramBotPhp` library](https://github.com/alirezax5/telegramBotPhp) provides the
primitives (independent instances, token stack, raw calls), and the framework adds a
**`BotManager`** registry so plugins can reach secondary bots by name.

Both owner-requested scenarios were verified live (2026-10-11) with two real bots:

| Scenario | What happens | Verified result |
|---|---|---|
| **A — membership check via bot #2** | user asks bot #1; bot #1 executes `getChatMember()` **with bot #2's token** | `ok=true`, `status=member`, 13 ms |
| **B — delegated send** | user asks bot #1; bot #1 makes **bot #2 send** the message, inside bot #1's process | delivered, 239 ms |

---

## Registering extra bots: `BOT_EXTRA_BOTS`

Add the env var before boot (in `.env` or the process environment):

```dotenv
BOT_EXTRA_BOTS="second:398172019:AAxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
```

Format rules:

- entries are **comma-separated**; each entry is `name:token`;
- split on the **first** colon only (a bot token itself contains a colon);
- the name `main` is reserved for the primary bot and ignored in this list;
- malformed entries are logged (warning) and skipped — boot never fails.

`BotManager` is a singleton created on first use; the default bot (`main`) is built
from `Config::bot()` (i.e. `BOT_TOKEN`), then extras are registered.

## Using bots from a plugin

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

## API of `BotManager`

| Method | Behavior |
|---|---|
| `getInstance(): BotManager` | singleton accessor |
| `get(?string $name): Telegram` | returns the named bot; **unknown names fall back to `main`** (warning logged) |
| `has(string $name): bool` | is the name registered? — **always check before `get()` of a non-default bot** |
| `add(string $name, Telegram $bot): void` | register/overwrite a bot at runtime (overwriting logs a warning) |
| `names(): string[]` | all registered names (`main` first) |
| `setDefaultToken(string $token): Telegram` | overrides the **main** bot's token globally — affects every caller |

## Why `has()` matters

`get('typo-name')` does not throw — it silently returns the **main** bot. A
`getChatMember()` meant for bot #2 would then be executed by bot #1's identity and
return `chat member not found` or worse, a wrong-but-plausible answer. Guard every
non-default access with `has()`.

## Token management: many bots on one file

One deployment (one entry file + one `.env`) can serve any number of bot
identities. Three strategies are supported — ordered by recommendation:

| # | Strategy | Tokens live where | Best for | Verified |
|---|---|---|---|---|
| 1 | **Static registry** — `BOT_TOKEN` + `BOT_EXTRA_BOTS` in `.env` | one `.env`, `name:token` pairs | a fixed set of bots deployed together | 23/23 checks (parse / identity / degrade / scale) |
| 2 | **Dynamic registry** — `BotManager::add($name, new Telegram($token))` at runtime | your DB/config panel; passed into the process | bots provisioned by an admin panel, per-tenant bots | identity + delegated-send checks |
| 3 | **Managed Bots** — child tokens fetched live from Telegram (next section) | **nowhere on disk** | a manager bot creating children on behalf of users | TM-2 suite, 8/8 |

Rules the live test suite proves (2026-10-11, two real bots + synthetic entries):

- malformed `BOT_EXTRA_BOTS` entries are logged and **skipped — boot never fails**;
- a dead/revoked token fails clean (`ok=false`, `Unauthorized`) and never poisons
  other bots: `get('dead')` errors, `get('alive')` and `main` stay healthy;
- `main` is reserved; duplicate names resolve last-wins with a warning;
- the token's own colon survives the `name:token` split (first colon only);
- identity isolation: every registered instance's `getMe()` matches its token's
  id prefix; the token stack is per-instance (switch/restore on `main` leaves
  `second` untouched);
- registry lookups are O(1): 10,000 `get()` calls with 32 registered bots in ~1 ms.

Webhook note: in `webhook_*` modes every update hitting `webhook.php` is
processed under the **main** bot's identity (one shared `BOT_WEBHOOK_SECRET`).
To serve several bots over webhooks you do **not** need one directory per bot
— the `secret_token` pattern in the next section runs **any number of bots
from one single webhook file**, proven live.

## One webhook file, many bots — the `secret_token` pattern

You do NOT need one folder per bot. **One single webhook file can serve any
number of bots.** The trick is the `secret_token` parameter of `setWebhook`:

1. Pick a **different random secret for every bot** (only letters, digits,
   `_` and `-` are allowed).
2. Register the **same webhook URL** for every bot, each with its own secret:
   `setWebhook?url=https://example.com/hook.php&secret_token=<this-bots-secret>`
3. On every update Telegram sends that secret back in the header
   `X-Telegram-Bot-Api-Secret-Token`.
4. Your file reads the header, looks the secret up in a small registry
   (`secret → bot token`) and processes the update under that bot's identity.

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

Why this is the recommended way to run a bot farm:

- **the bot token never appears in a URL** — it cannot leak into access logs,
  browser history or proxies;
- the secret doubles as an **authenticity check** — a request without the
  right secret is not from Telegram; answer `403` and drop it;
- **adding bot #N+1 costs one `setWebhook` call and one registry line** — no
  new files, no new deployment.

Live proof (2026-10-11, two real bots, ONE file, real user traffic):

| Event | Routed identity | Result |
|---|---|---|
| real `/start` on bot B | @instaroonxbot | replied, `ok:true` |
| real tap on bot B's inline button (×2) | @instaroonxbot | `answerCallbackQuery` + reply, `ok:true` |
| real tap on bot A's inline button | @emojiCustom_bot | `answerCallbackQuery` + reply, `ok:true` |
| synthetic POST with secret A / secret B | correct bot each time | replied, `ok:true` |

7/7 deliveries — every update hit the same file and every reply carried the
correct bot identity. The bot token was never in any URL or header.

Hardening rules for production:

- the secret **is the routing key** — it must be unique per bot;
- generate it randomly, 32+ characters from `[A-Za-z0-9_-]` (Telegram rejects
  other characters); a 2-character secret is effectively no secret;
- reject unknown or missing secrets with `403` before touching anything else;
- never log tokens; log at most a short secret prefix;
- rotating a secret = run `setWebhook` again for that bot (takes seconds).

## Managed Bots (Bot API 9.6)

Telegram Bot API 9.6 (2026-04-03) added **Managed Bots**: a bot with
`can_manage_bots` (enabled in the @BotFather Mini App) can have other bots
created and controlled under it. The child token never needs to be stored —
the manager fetches it live with `getManagedBotToken(user_id)`.

Framework support (v2.4.x):

| API element (Bot API 9.6) | Framework surface |
|---|---|
| `User.can_manage_bots` (getMe) | `$tg->callRaw('getMe')->result->can_manage_bots` |
| `managed_bot` update (`ManagedBotUpdated`) | dispatched to `onManagedBot($managedBot, $tg)` — added to the plugin whitelist |
| `KeyboardButtonRequestManagedBot` | `Keyboard::reply()->requestManagedBot($text, $requestId, $suggestedName, $suggestedUsername)` |
| `getManagedBotToken` / `replaceManagedBotToken` / `get·setManagedBotAccessSettings` | `BotManager::registerManagedBot()` / `refreshManagedBot()` / `managedBotAccessSettings()` |
| `ManagedBotCreated` message field | visible in the payload inside `onMessage` |

End-to-end flow (plugin sketch):

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

Prerequisites (owner actions, one-time):

1. Enable **management of other bots** for the manager bot in the @BotFather
   Mini App — until then `getMe` reports `can_manage_bots: false` and the
   creation request fails.
2. The child-bot creation itself always happens on Telegram's side (user
   consent); the framework only receives the `managed_bot` update and fetches
   the token.

Token hygiene: managed tokens live only in memory (the registry) — nothing is
written to `.env` or logs. Rotating a child token via
`BotManager::refreshManagedBot($userId, $name)` revokes the old token
immediately and re-registers the new one.

### Managed Bots — live end-to-end (2026-10-11)

After the owner enabled **management of other bots** in the @BotFather Mini
App, `getMe` flipped to `can_manage_bots: true` on both test bots and the full
flow was exercised live:

- the creation button built by `Keyboard::reply()->requestManagedBot(...)` was
  sent in a private chat — Telegram accepted the framework's keyboard shape;
- field detail worth knowing: for `KeyboardButtonRequestManagedBot` the
  `request_id` must be a **Number** (unlike `request_chat` / `request_users`,
  where it is a String — Telegram rejects a string with `Bad Request: can't
  parse KeyboardButton: Field "request_id" must be a valid Number`);
- once the user completes the creation form, the manager receives a
  `managed_bot` update and fetches the child token live with
  `getManagedBotToken(user_id)` — the demo then made the **child bot speak in
  the owner's chat** with its own freshly fetched token (zero-on-disk).

Enabling the same Mini App toggles also flipped, on both test bots:
`supports_guest_queries`, `has_topics_enabled`,
`supports_join_request_queries`, `can_connect_to_business` → all `true`.

- Live update shape (captured 2026-10-11): `managed_bot.user` = the person who created the child (an ordinary user), `managed_bot.bot` = the child bot itself (`{id, is_bot: true, username, ...}`). Pass `managed_bot.bot.id` to `getManagedBotToken` — the creator's id fails with `Bad Request: user is not a bot`. The demo fetched the child token live and the child delivered a real message to its owner — zero tokens on disk.

### Where to keep tokens and secrets

| Storage | When it fits | Watch out |
|---|---|---|
| **`.env`** (`BOT_TOKEN` + `BOT_EXTRA_BOTS`) | a small, fixed set of bots | root-readable only; changes need a restart |
| **DB registry table** (`bots`: id, name, token, secret, status) | bots added/removed at runtime, admin panels | encrypt the token column at rest (e.g. sodium); cache the registry so one update ≠ one DB query |
| **Managed Bots (zero on disk)** | a manager bot creating children for users | needs `can_manage_bots`; token fetched live via `getManagedBotToken` |
| **Derived secrets (HMAC)** — nothing stored | you control one master key | `secret = base64url(hmac_sha256(master_key, 'bot:' . bot_id))`; rotating the master key means re-registering every webhook |

Recommendation for a growing bot farm: **DB registry (token encrypted at
rest) + cached lookup + `secret_token` routing**, and **Managed Bots** for
user-created children. Keep `.env` for the first manager bot only.

### Keeping every bot's data separate

When many bots share one deployment, each bot's data must stay exclusive to
that bot. Namespace **every storage layer with the bot id** (`b{bot_id}`):

| Layer | Rule |
|---|---|
| Cache | key prefix `b{bot_id}:` (set `CACHE_PREFIX` per bot at boot) |
| Sessions | scope by **bot id + user id** — the same person talking to two bots is two different sessions |
| getUpdates offset | **one offset file/key per bot** — a shared offset would feed one bot's updates to another |
| Queue | one queue name per bot (`tgbase_b{bot_id}_updates`) or tag payloads with `bot_id` |
| File storage | `data/bots/{bot_id}/…` |
| Logs | one log file per bot (`logs/bot_{id}.log`) |
| Database | shared tables get a `bot_id` column; true isolation = separate DB per bot |

The webhook front-controller resolves `secret → bot` first, then boots the
framework with that bot's token and namespace — every later read/write then
stays inside the bot's own sandbox automatically.

### Cloning a deployment? Clone the database too

The namespacing above protects **one process serving many bots**. A different
trap is **two separate deployments of the same app pointed at one database**
— e.g. a copy of the project made for an A/B test, sharing the original's DB
because "it's the same schema". Both instances then fight over the same
un-namespaced tables: unique keys collide (`users.chatid` — the second
instance's upsert of the same person overwrites/loses), and natural-key
lookups pick whichever row came last (a message-mirror table keyed by
`sender_id + message_id` resolves to the *other* instance's chat, because
message ids are only unique per bot). Observed live (2026-10-12): two clones
of one support-bot shared a DB, and an unfinished input state from the test
clone intercepted the other clone's admin flow mid-broadcast.

If your app tables have no `bot_id` column (and no query-level scoping by
one), **every deployment instance must get its own database** — the clone
step is `CREATE DATABASE` + changed `DB_*` env values, not a shared
connection string. Rule of thumb: sharing a DB between instances is only
safe when *every* shared table carries a `bot_id` column and *every* query
scopes by it — the same standard the framework core applies to its own
layers in the table above.

## Delivery semantics

Multi-bot calls ride on the same HTTP client as every other API call:

- retries/backoff and `retry_after` handling apply per instance;
- the token stack is **per-instance state** — in queue workers each update is
  processed by a fresh dispatch, so switch/restore pairs live inside one update's
  lifetime; never rely on stack state across updates;
- in `webhook_*` modes the same rules apply within the single FPM request.

## Live verification harness

`integration/TestBot/Plugin/MultiBotTestPlugin.php` (test harness, private chats):

- `/multibot` — full suite: identity, isolation, cross-send (Scenario B),
  membership via bot #2 (Scenario A), token-stack switch/restore;
- `/mbchannel <chat>` — bot #2 `getChatMember` on an explicit `@channel`/chat id
  (bot #2 must be admin/member of that chat).

Evidence log: `runtime/p331/multibot.log` — final live run
(2026-10-11 12:26:19): identity ✔, isolation ✔, cross-send ✔ (239 ms),
membership ✔ (13 ms), token stack ✔ — **5/5 PASS in 393 ms**.

Additional harness runs (2026-10-11, post-sync live runtime):

- **TM-1 token-management suite** — 23/23 PASS across four cases:
  `parse` (7), `identity` (8, two real bots), `degrade` (5, revoked token),
  `scale` (3, 32 bots / 10k lookups).
- **TM-2 Managed Bots suite** — 8/8 PASS: live `getMe` exposes
  `can_manage_bots`; `getManagedBotToken` / `getManagedBotAccessSettings`
  reachable with clean error contracts; synthetic `managed_bot` update
  dispatches `before → onManagedBot`; invalid `registerManagedBot()` throws
  cleanly without poisoning the registry; `requestManagedBot()` keyboard
  shape matches `KeyboardButtonRequestManagedBot`.
