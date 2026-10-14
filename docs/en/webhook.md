# Webhook — receiving updates from Telegram

## Setup

```dotenv
# .env
BOT_MODE="webhook_direct"          # or webhook_queue
BOT_WEBHOOK_URL="https://yourdomain.com/webhook.php"
BOT_WEBHOOK_SECRET="a-long-random-string"   # openssl rand -hex 32
WEBHOOK_CHECK_IP=true
```

Register once (Telegram requires HTTPS with a valid certificate):

```bash
curl "https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://yourdomain.com/webhook.php&secret_token=<SECRET>"
curl "https://api.telegram.org/bot<TOKEN>/getWebhookInfo"   # verify: pending_update_count=0, no last_error
```

Unregister (switching back to polling — do this *before* starting `bot.php`, two update sources fight over the same stream):

```bash
curl "https://api.telegram.org/bot<TOKEN>/deleteWebhook"
```

## The five validation layers

Every request hitting `webhook.php` passes through `WebhookDispatcher`, in order:

1. **Body size** — over 1 MB → `413`. (Telegram never sends larger payloads; anything bigger is not Telegram.)
2. **IP whitelist** — with `WEBHOOK_CHECK_IP=true`, requests from IPs outside Telegram's official subnets → `403`. The ranges are the ones published in the Telegram docs (`149.154.160.0/20`, `91.108.4.0/22`, `91.108.8.0/22`, `91.108.56.0/22`, `91.108.16.0/22`, `95.161.64.0/20`).
3. **Secret token** — with `BOT_WEBHOOK_SECRET` set, the `X-Telegram-Bot-Api-Secret-Token` header must match, compared with `hash_equals()` (timing-attack safe). Mismatch → `403`.
4. **JSON validation** — the body must parse to an object containing `update_id`. Otherwise → `400`.
5. **Routing** — `webhook_direct` runs plugins synchronously, then answers `200` only if nothing threw; `webhook_queue` enqueues and answers `200` immediately.

Failing validation never consumes the update in a way that loses it — Telegram re-sends updates it didn't get a `2xx` for.

## Behind Cloudflare / nginx proxies (important)

If Telegram's requests reach PHP through Cloudflare (or any reverse proxy), PHP's `REMOTE_ADDR` is the *proxy's* IP, not Telegram's — the IP check would reject every request. Two working setups:

- **Proxy in front:** keep `WEBHOOK_CHECK_IP=false` and rely on the **secret token** as your inbound authentication (make it long — 32+ hex chars). This is exactly how the framework was validated in production testing.
- **Proxy passes the real IP:** configure your proxy to send `X-Forwarded-For` / `X-Real-IP` *and* have PHP trust it — then `WEBHOOK_CHECK_IP=true` works. Only do this if you control the proxy, otherwise the header can be spoofed.

Rule of thumb: **IP check = direct exposure; secret = proxied exposure.** Using both is fine too.

## Playbook: real traffic gets `403 Forbidden`

`getWebhookInfo` only ever says `Wrong response from the webhook: 403 Forbidden` — it never names the validation layer that rejected the request. The app log does (`⛔ Webhook from unknown IP: …` vs `⛔ Webhook secret token mismatch`), and since the hardening release those two warnings carry their own fix in the message text.

The sneakiest variant is **secret drift**: `.env` is correct and untouched, but a *later* `setWebhook` call — typically a re-registration to add an `allowed_updates` type — went out without the original `secret_token` (or with a different value). Every real delivery then fails with 403 while code, `.env` and docs all look perfect. Observed live on a production pair (2026-10-12): both bots of the deployment went dark at the same moment, `pending_update_count` stuck above zero, zero app-side errors besides the mismatch line.

Prove which layer fails with one request from the server itself:

```bash
curl -s -o /dev/null -w '%{http_code}\n' -X POST \
  -H "X-Telegram-Bot-Api-Secret-Token: <SECRET_FROM_DOT_ENV>" \
  -H 'Content-Type: application/json' \
  -d '{"update_id":1,"message":{"message_id":1,"date":1,"chat":{"id":1,"type":"private"},"from":{"id":1,"is_bot":false,"first_name":"diag"},"text":"ping"}}' \
  "https://yourdomain.com/webhook.php"
```

- **`200`** while `getWebhookInfo` still reports 403 → *secret drift*: the value registered at Telegram's side differs from the current `.env`. Fix = re-run `setWebhook` with the exact `BOT_WEBHOOK_SECRET` from `.env` (add `drop_pending_updates=true` to flush the stale backlog), then confirm `last_error_message` stays empty.
- **`403`** from this curl too → read the app log: *unknown IP* means you are behind a proxy (section above); *secret mismatch* means header and `.env` genuinely differ (`.env` quoting is handled by the env loader; stray whitespace inside the curl header is not).

Recovery rule of thumb: **`.env` is the single source of truth — every `setWebhook` re-registration must copy `secret_token` from it verbatim.**

## Mode behaviour on the webhook entry

| `BOT_MODE` | What `webhook.php` does |
|------------|--------------------------|
| `webhook_direct` | validate → `Processor::handle()` → `200` when done |
| `webhook_queue` | validate → enqueue → `200` immediately |
| `update_direct` | validate → `Processor::handle()` → `200` (works, but logs a warning — prefer `webhook_direct` explicitly) |
| `update_queue`, `cronjob_*` | `500` + explanatory message — these modes are CLI-only |

The last row is the v2.4.0 SAPI guard ([bot-modes.md](bot-modes.md)): a CLI mode on the web entry can no longer start an infinite loop inside your PHP-FPM worker (which used to end as `Read timeout expired` on Telegram's side and a 502 from your web server).

## Response semantics — why 200 matters

Telegram retries any webhook delivery that doesn't get a `2xx`, with backoff, for up to several hours. The framework exploits this honestly:

- `200` is sent **only after** the update was processed (direct) or safely enqueued (queue mode).
- An exception during handling → `500` → Telegram redelivers later → the update is **not lost**, but *will be processed twice* if the first attempt actually completed before the crash. That is the definition of at-least-once delivery — see [reliability.md](reliability.md) for making handlers idempotent.
- A worker that takes minutes to respond is a bug, not a feature: Telegram's client timeout is ~60s. Move slow work into `webhook_queue`.

## Registering with allowed updates (optional)

Restrict what Telegram sends you to the update types your plugins actually handle:

```bash
curl "https://api.telegram.org/bot<TOKEN>/setWebhook?url=...&secret_token=...&allowed_updates=[\"message\",\"edited_message\",\"callback_query\",\"channel_post\",\"edited_channel_post\"]"
```

> **`allowed_updates` silently gates plugin handlers.** A plugin method like
> `onEditedMessage()` (or `onChannelPost()`, `onMyChatMember()`, …) only ever
> runs if the webhook was registered with that update type — Telegram doesn't
> send what you didn't ask for, and *nothing anywhere raises an error*. If you
> add a handler for a new update type, re-run `setWebhook` with the complete
> list. And carry the `secret_token` parameter in **every** `setWebhook` call:
> it is not remembered from previous registrations (see the playbook below).

## Health checks

`getWebhookInfo` is your dashboard — watch:

- `pending_update_count` — updates queued at Telegram because your endpoint is failing/slow. Sustained growth = incident.
- `last_error_message` / `last_error_date` — the exact delivery failure (timeouts, TLS errors, wrong secret).

A deployment checklist for webhook mode:

1. `webhook.php` reachable over HTTPS (test with a browser: an empty GET should give `400 empty` — that's the dispatcher answering).
2. `BOT_WEBHOOK_SECRET` ≥ 32 random characters, identical in `.env` and `setWebhook`.
3. `WEBHOOK_CHECK_IP` matches your network topology (see the proxy section).
4. `getWebhookInfo` clean after a test message; your plugin's side effect visible.


## Webhook management CLI (2026-10)

```bash
php bin/tgbase webhook:info                       # url, allowed_updates, pending, last_error
php bin/tgbase webhook:set --url=https://host/webhook.php
php bin/tgbase webhook:del [--drop-pending]
```

Tokens resolve from `BOT_TOKEN` (`.env` is loaded automatically for every
`bin/tgbase` command); an explicit `--token=…` flag overrides it for one
invocation. Each subcommand prints which source it used — `token: set
(46 chars, from BOT_TOKEN env)` — without echoing the value itself.

`webhook:set` derives `secret_token` from `BOT_WEBHOOK_SECRET` and uses the
recommended `allowed_updates` default — `message,edited_message,
callback_query,my_chat_member,chat_member` — so `onEditedMessage()` hooks
receive edits out of the box. `webhook:info` warns explicitly when the
active webhook's `allowed_updates` misses `edited_message`.
