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

## Health checks

`getWebhookInfo` is your dashboard — watch:

- `pending_update_count` — updates queued at Telegram because your endpoint is failing/slow. Sustained growth = incident.
- `last_error_message` / `last_error_date` — the exact delivery failure (timeouts, TLS errors, wrong secret).

A deployment checklist for webhook mode:

1. `webhook.php` reachable over HTTPS (test with a browser: an empty GET should give `400 empty` — that's the dispatcher answering).
2. `BOT_WEBHOOK_SECRET` ≥ 32 random characters, identical in `.env` and `setWebhook`.
3. `WEBHOOK_CHECK_IP` matches your network topology (see the proxy section).
4. `getWebhookInfo` clean after a test message; your plugin's side effect visible.
