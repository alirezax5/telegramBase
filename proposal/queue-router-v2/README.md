# queue-router-v2 (PROPOSAL / نسخهٔ پیشنهادی)

Standalone minimal implementation of the field-proven architecture for a
multi-bot Telegram farm:

```text
Telegram ──POST + X-Telegram-Bot-Api-Secret-Token──▶ mb_router_queue.php
   │  hash_equals(secret, registry)  → unknown: 403
   │  body > 1 MiB                   → 413
   │  idempotency (Redis) + XADD tg:updates   ─┐
   │  (fallback: atomic file queue)           │ enqueue only
   └── 200 {"ok":true}  (<50 ms)  ◀───────────┘
mb_worker.php (N instances, supervised):
   XREADGROUP → handle_update() → XACK AFTER processing
   XAUTOCLAIM (60 s idle) reclaims dead workers' deliveries
   3 failures → tg:updates:dead (DLQ)  |  file mode: failed/
```

| زبان | بخش |
|---|---|
| EN | below |
| FA | پایین |

---

## EN — what & why

**Why this exists** (all proven live on 2026-10-11, see `NOTES-2026-10-11.md`):

1. **Webhook must not process inline.** A webhook that ran bot logic in-request
   was killed by nginx (`499`) after ~60–90 s and Telegram kept retrying.
   The router here only validates + enqueues + answers `200` fast.
2. **Loss-safe queue.** The framework's Redis driver uses destructive `LPOP`
   (a crash between pop and process loses the update). This proposal uses
   Redis Streams (`XREADGROUP` + `XACK` after processing + `XAUTOCLAIM`) or an
   atomic file queue (`new → processing → done|failed` via rename) —
   both are at-least-once; the per-bot monotonic `update_id` guard makes it
   effectively-once.
3. **One ingest per bot.** Webhook XOR polling — a leftover poller 409-ed
   against an armed webhook for hours. The companion patch
   `PollingLoop-self-guard.patch.md` makes the framework poller self-suspend.

**Files**

| File | Role |
|---|---|
| `mb_router_queue.php` | webhook endpoint: 403/413/dedupe/enqueue/200. Redis Streams if ext-redis, else atomic file queue under `data/mb_queue/` |
| `mb_worker.php` | CLI worker (both backends, SIGINT/SIGTERM graceful, DLQ after 3 fails). `handle_update()` is the single integration point for your logic |
| `provision.sh` | arms N bots: generates a 48-hex secret per bot, `setWebhook` with explicit `allowed_updates` (incl. `managed_bot`, `guest_message`), prints `getWebhookInfo` |
| `PollingLoop-self-guard.patch.md` | framework patch sketch (root cure for 409s) |

**Quick start**

```bash
# 1) review + fill BOTS in provision.sh, then:
chmod +x provision.sh && ./provision.sh
#    -> copy printed secrets into mb_router_queue.php $REGISTRY
#    -> copy tokens into mb_worker.php BOTS

# 2) lint + run the worker under supervisord/systemd/BT:
php -l mb_router_queue.php && php -l mb_worker.php
MB_REDIS_HOST=127.0.0.1 php mb_worker.php          # Redis Streams
php mb_worker.php                                   # or file mode (no Redis)

# 3) monitor (both bots):
watch -n5 'curl -s https://api.telegram.org/bot<TOKEN>/getWebhookInfo'
tail -f mb_router_log.jsonl
```

**Rules encoded (do not violate)**

- Unknown secret → 403 (`hash_equals`); authenticated traffic → always `200`
  (5xx makes Telegram retry → duplicates).
- Never log tokens; secret prefix only. Tokens live in the worker's registry
  (production: DB + encryption or Managed Bots live-fetch — zero on disk).
- Never poll a bot whose webhook is armed (409). One ingest per bot.
- Scale workers horizontally; if per-chat ordering matters, run one worker per
  bot or partition by `chat_id` hash.

**Integration path into telegramBase**
- The framework already ships `webhook_queue` mode (`webhook.php` → enqueue,
  worker consumes): the proposal mirrors it for multi-bot farms on one file.
- Apply the `PollingLoop-self-guard` sketch to `App/Update/PollingLoop.php`.
- Longer term: swap the Redis driver's `LPOP` for Streams semantics (F006-style
  ack-late, like the RabbitMQ fix already merged).

---

## FA — چه و چرا

**دلایل وجود این نسخه (همه زنده اثبات شده — `NOTES-2026-10-11.md` در ریشهٔ مخزن):**

1. **وبهوک نباید پردازش inline کند.** وبهوکی که منطق را داخل ریکوئست اجرا کرد
   بعد از ~۶۰–۹۰ ثانیه با 499 توسط nginx کشته شد و تلگرام مدام retry کرد.
   این روتر فقط اعتبارسنجی + enqueue + پاسخ 200 سریع دارد.
2. **صف بدون از دست دادن.** درایور Redis فریم‌ورک `LPOP` تخریب‌ای است (کرش بین
   pop و پردازش = گم‌شدن آپدیت). اینجا Redis Streams (XREADGROUP + XACK بعد از
   پردازش + XAUTOCLAIM) یا صف فایلی اتمی (rename: new→processing→done/failed)
   است — at-least-once؛ گارد یکنوای `update_id` هر ربات آن را effectively-once می‌کند.
3. **یک ingest برای هر ربات.** وبهوک XOR polling — دیمنِ جامانده ساعت‌ها با
   وبهوک مسلح 409 گرفت. پچِ `PollingLoop-self-guard.patch.md` این را ریشه‌ای
   می‌کند (poller خودش را متوقف می‌کند).

**اجرا (خلاصه):** `provision.sh` را پر و اجرا کنید → secretها را در
`$REGISTRY` روتر و توکن‌ها را در `BOTS` ورکر بگذارید → ورکر را زیر
supervisor/systemd/پنل اجرا کنید → با `getWebhookInfo` و لاگ روتر مانیتور کنید.

**قواعد سخت:** ناشناس=403؛ به ترافیک معتبر تلگرام همیشه 200؛ توکن هرگز لاگ
نمی‌شود؛ رباتِ وبهوک‌دار هرگز poll نمی‌شود؛ برای ترتیب پیام در چت، یک ورکر به‌ازای
هر ربات یا پارتیشن با `chat_id`.

**مسیر ادغام با telegramBase:** حالت `webhook_queue` فریم‌ورک همین الگو است — این
پیشنهاد نسخهٔ تک‌فایلیِ چند-رباتی است + پچ گارد PollingLoop + (آینده) تبدیل
درایور Redis به معناشناسی Streams مثل فیکس F006 RabbitMQ.
