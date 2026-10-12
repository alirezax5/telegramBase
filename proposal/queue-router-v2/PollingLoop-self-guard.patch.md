# PollingLoop self-guard — patch sketch (PROPOSAL)

Problem (observed live, 2026-10-11): a polling daemon kept calling `getUpdates`
while a webhook was armed for the same bot → Telegram answered **409 Conflict**
continuously for hours. Logs filled, nothing processed. The root cure: the
poller must detect an armed webhook and suspend itself.

Rule (proven): **webhook XOR getUpdates per bot, never both.**

---

## Where

`App/Update/PollingLoop.php` — add one method + one call at the top of the
batch loop (right where `$shouldStop` is checked each iteration).

## Patch sketch

```php
// App/Update/PollingLoop.php

/**
 * Self-guard (PROPOSAL): every WEBHOOK_GUARD_INTERVAL seconds ask Telegram
 * whether a webhook is armed for THIS bot. If it is, polling is forbidden
 * (Telegram answers 409) — stop quietly so a supervisor or an operator can
 * decide which ingest wins. One ingest per bot: webhook XOR getUpdates.
 */
private function webhookGuard(): void
{
    static int $lastCheck = 0;
    $interval = (int)($_ENV['WEBHOOK_GUARD_INTERVAL'] ?? 60);
    if ($interval <= 0 || (time() - $lastCheck) < $interval) {
        return;
    }
    $lastCheck = time();

    try {
        // Method name in alirezax5/telegram-bot-php (BotSettings trait);
        // adjust if the framework wraps it differently.
        $res  = $this->telegram->getWebhookInfo();
        $info = $res['result'] ?? [];
        $url  = (string)($info['url'] ?? '');

        if ($url !== '') {
            // Webhook owns the ingress for this bot. Options:
            //   A) stop:              $this->shouldStop = true;            (chosen)
            //   B) deleteWebhook + continue  (poll wins) — explicit only.
            $this->logger?->warning('PollingLoop: webhook armed for this bot, '
                . 'suspending getUpdates (409 guard)', ['url' => $url,
                'pending' => $info['pending_update_count'] ?? null]);
            $this->shouldStop = true;
        }
    } catch (\Throwable $e) {
        // Never let the guard kill the poller: connectivity hiccups happen.
        $this->logger?->debug('PollingLoop: webhook guard skipped', [
            'err' => $e->getMessage(),
        ]);
    }
}
```

Call site (top of the loop body in `run()`):

```php
while (!$this->shouldStop) {
    $this->webhookGuard();      // <-- add this line
    // ... existing getUpdates batch ...
}
```

Env knob (optional, `.env`):

```text
# seconds between webhook guards; 0 disables the guard
WEBHOOK_GUARD_INTERVAL=60
```

---

## Behavior matrix after the patch

| State                                       | Poller behavior                  |
|---------------------------------------------|----------------------------------|
| Webhook armed (`getWebhookInfo.url` != '')  | Poller self-suspends (no 409s)   |
| No webhook (url == '')                      | Polling continues normally       |
| `getWebhookInfo` network error              | Guard skipped, polling continues |

## Test plan (after applying)

1. Arm a webhook for the bot → start `bot.php` (polling) → expect one warning
   line then a clean exit 0, **zero 409 entries**.
2. `deleteWebhook` → start `bot.php` → polling runs normally, guard checks every
   60 s and stays silent.
3. While polling, run `setWebhook` → within ≤60 s the poller stops itself.

---

## فارسی (خلاصه)

مشکل مشاهده‌شده: دیمنِ polling با وبهوکِ مسلح ساعت‌ها 409 گرفت. راه‌حل ریشه‌ای:
هر ۶۰ ثانیه یک `getWebhookInfo`؛ اگر `url` غیرخالی بود، poller خودش را متوقف
کند (گزینهٔ پیش‌فرض: توقف؛ حذف وبهوک فقط با تصمیم صریح). خطای شبکهٔ گارد هرگز
poller را نمی‌کشد. سه مرحلهٔ تست بعد از اعمال پچ در بالا آمده است.
