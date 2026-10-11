# Troubleshooting — symptoms, causes, fixes

## Boot & configuration

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| `BOT_TOKEN is not set or empty` on `migrate:status` | old behaviour | fixed in current version — migrate commands no longer require a token. If you still see it, run with `TGBASE_SKIP_BOT_VALIDATION=1` |
| `could not find driver` on `migrate` | `DATABASE_ENABLE=true` but no PDO driver/DB configured | set `DATABASE_ENABLE=false` if you don't need a DB, or install the PDO driver |
| Boot fails with mode-related validation error | `BOT_MODE` misspelled | use exactly one of: `update_direct`, `update_queue`, `webhook_direct`, `webhook_queue`, `cronjob_update`, `cronjob_queue` |
| Logs appear in CWD instead of `logs/` | old behaviour — relative log path resolved against CWD | fixed: `LogHandler` uses `LoggerConfig::absolutePath()` (project-base-relative). Also affected running `bin/tgbase` from another directory |
| `Session::regenerate()` fatal: "Cannot modify readonly property" | old behaviour | fixed: `Session` no longer declares `$sessionId` readonly |
| `tests/smoke.php` fails all negative cases | old broken match logic | fixed in current version |
| Plugins not loaded | wrong `PLUGINS_DIR`, or plugin cache stale | default is `/Plugin/` (project-base-relative; absolute paths supported). For dev set `PLUGIN_CACHE_ENABLED=false`, then `php bin/tgbase plugins` to verify discovery |
| Rate limiter / admin guard crash on callback queries | old non-nullsafe `->message->chat->id` access | fixed: nullsafe chains + `edited_message` / `channel_post` / `chat_member` supported |

## Webhook

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Webhook returns `403` for every delivery | IP whitelist active but a proxy/CDN rewrites the source IP | behind Cloudflare/nginx set `WEBHOOK_CHECK_IP=false` and rely on a strong `BOT_WEBHOOK_SECRET` — see [webhook.md](webhook.md#behind-cloudflare--nginx-proxies-important) |
| Webhook returns `403`, `getWebhookInfo` says secret mismatch | `BOT_WEBHOOK_SECRET` in `.env` ≠ `secret_token` passed to `setWebhook` | make them identical; rotate with `openssl rand -hex 32` |
| Webhook returns `500` with "BOT_MODE ... is CLI-only" | CLI-only mode on the web entry (the v2.4.0 SAPI guard) | set `BOT_MODE=webhook_direct` / `webhook_queue` for the web entry, or run the mode from the CLI (`bot.php` / `queue.php`) |
| `getWebhookInfo`: `last_error_message="Read timeout expired"` | endpoint didn't answer in time (pre-v2.4.0 this could be the infinite-loop bug; post-v2.4.0 it's slow handlers) | upgrade to ≥ v2.4.0; check for slow handlers; move heavy work to `webhook_queue` |
| `pending_update_count` keeps growing | your endpoint is down/failing continuously | check `last_error_message`, TLS certificate validity, and that `webhook.php` is reachable; a bare GET returning `400 empty` proves the dispatcher is alive |
| Webhook replies are delayed by seconds | handlers run inline during the HTTP request | switch to `webhook_queue` — the endpoint then answers immediately and workers process asynchronously |

## Queues & workers

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| `RabbitMQ ... "Broken pipe or closed connection"` at connect | old login-method bug (`default` is not a valid AMQP mechanism) | fixed in v2.4.0 (driver now uses `AMQPLAIN`); verify your RabbitMQ user/vhost anyway |
| Updates pushed but never processed | no consumer running | modes `*_queue` need both sides: producer (`queue.php` or `webhook.php`) **and** consumer (`php bot.php`) |
| Queue grows unboundedly while workers are down | | set `QUEUE_MAX_SIZE` (bounded queues reject + log when full) |
| Duplicate processing after a worker crash | expected with at-least-once (RabbitMQ ack-late, webhook/polling redelivery) | make handlers idempotent — see [reliability.md](reliability.md#making-handlers-idempotent-the-practical-90) |
| Worker exits silently under load | supervisor missing | run all long processes under systemd/supervisord with `Restart=always`; the framework self-restarts polling every `POLLING_RESTART_INTERVAL` batches, the supervisor covers everything else |
| `409 Conflict` from `getUpdates` | two processes polling the same bot token (or webhook + polling simultaneously) | one update source only: run a single fetcher, and `deleteWebhook` before starting polling |

## Deployment notes worth their weight

- **Log file permissions (FPM):** if the webhook entry can't write logs, it's usually the file owner — PHP-FPM runs as `www-data`/`www`, so log files/dirs must be writable by that user (`chown www-data:www-data logs -R`). A silent no-op log write is hard to notice otherwise.
- **PHP-FPM `request_terminate_timeout`:** anything your webhook handler does longer than this limit dies with a 502. Keep webhook handling fast; use the queue for the rest.
- **`php bin/tgbase doctor`** is the fastest triage tool — it checks PHP version/extensions, `.env` readability, `AppData/` writability, log writability, and token format without touching Telegram.
- **Two update sources fighting:** after switching between polling and webhook, verify the switch completed (`deleteWebhook` or a clean polling stop) before starting the other side. Both running at once = offset conflicts and 409s.

## Diagnostics quick reference

```bash
php bin/tgbase doctor              # environment triage
php bin/tgbase plugins             # what the framework actually discovered
php tests/smoke.php                # 6-case bootstrap check
php tests/run.php --filter=Queue   # subsystem deep-dive
curl "https://api.telegram.org/bot<TOKEN>/getWebhookInfo"   # Telegram-side view
```
