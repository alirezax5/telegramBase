# Mini Apps — `initData` validation

Telegram **Mini Apps** (formerly Web Apps) are normal web pages opened inside Telegram. The frontend receives a **signed identity string** — `Telegram.WebApp.initData` — that proves *which Telegram user* is looking at the page. Your backend must verify that signature before serving any user-specific data. This guide covers the backend half, which ships as a small validator class:

- Framework: `alirezax5\TelegramBase\App\MiniApp\InitData`
- Library-only projects (SDK without the framework): `telegramBotApiPhp\WebApp\InitData`

> **Never trust `initDataUnsafe`.** It carries the same fields but *without* the signature. Anyone can post arbitrary JSON to your endpoint — the signed `initData` is the only thing that can be verified against your bot token.

## The flow

```
┌─────────────┐  1. open Mini App   ┌──────────────────┐
│ Telegram     │ ──────────────────► │ Mini App page     │  your HTTPS host
│ chat         │                     │ (static HTML/JS)  │
└─────────────┘                     └────────┬─────────┘
       ▲                                    │  2. POST { initData } (fetch/form)
       │  4. render validated JSON          ▼
┌──────┴───────┐                     ┌──────────────────┐
│ Mini App UI  │ ◄────────────────── │ backend endpoint  │  InitData::validate()
└─────────────┘                     └──────────────────┘
```

Mini App traffic does **not** pass through the bot webhook — it is the user's browser calling your domain directly. `BOT_MODE`, queues and `allowed_updates` are not involved. The only proof of identity is the signed `initData`, so validate it on **every** privileged request.

## Backend usage

```php
use alirezax5\TelegramBase\App\MiniApp\InitData;

// Accept classic form POST or a JSON body.
$raw = $_POST['initData'] ?? null;
if (!is_string($raw) || $raw === '') {
    $json = json_decode((string) file_get_contents('php://input'), true);
    $raw  = is_array($json) ? (string) ($json['initData'] ?? '') : '';
}

// Token auto-detected from BOT_TOKEN; pass it explicitly for multi-bot:
//   InitData::validate($raw, $extraBotToken, ['max_age' => 3600])
$res = InitData::validate((string) $raw, null, ['max_age' => 3600]);

if (!$res['ok']) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $res['error']]);
    exit;
}

$user       = $res['user'];        // ['id' => 555000111, 'first_name' => 'Ali', 'username' => ...]
$startParam = $res['start_param']; // deep-link payload from ?startapp=ref_7
$authDate   = $res['auth_date'];   // unix ts the signature was issued
```

A complete runnable endpoint + page is in [examples/MiniAppEndpoint.php](../examples/MiniAppEndpoint.php).

## API

| Method | Signature | Returns |
|---|---|---|
| `validate()` | `validate(string $initData, ?string $botToken = null, array $options = [])` | Full result array (below) |
| `valid()` | same args | `bool` |
| `user()` | same args | `?array` — the Mini App user, or `null` when invalid |
| `build()` | `build(array $fields, ?string $botToken = null)` | A correctly **signed** initData string — for tests, fixtures and local curl-ing |

**Result (success):** `ok`, `user` (decoded array or `null`), `auth_date`, `start_param`, `fields` (all decoded fields without the hash), `hash`.

**Result (failure):** `ok: false` + `error`, one of: `empty_init_data`, `bot_token_missing`, `malformed_init_data`, `hash_missing`, `bad_signature`, `auth_date_missing`, `auth_date_expired`.

**Options:** `max_age` — maximum accepted age of `auth_date` in seconds. Default `86400` (24 h); `0` disables the check. Use short windows (e.g. `3600`) for sensitive operations.

## Frontend snippet

```html
<script src="https://telegram.org/js/telegram-web-app.js"></script>
<script>
  fetch('/miniapp/shop.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ initData: Telegram.WebApp.initData })
  })
    .then(r => r.json())
    .then(d => { /* d.ok ? render(d) : show(d.error) */ });
</script>
```

Opening the Mini App from a bot: `Keyboard::reply()->webApp('Open Shop', 'https://your.domain/miniapp/shop.php')` (see [buttons.md](buttons.md)) or set the bot's Menu Button via BotFather. Deep-link parameters arrive as `start_param` from `https://t.me/yourbot?startapp=ref_7`.

## Local testing without Telegram

`build()` signs a fixture string with your token, so endpoints can be exercised with curl before the frontend exists:

```bash
php -r '
require "vendor/autoload.php";
use alirezax5\TelegramBase\App\MiniApp\InitData;
echo InitData::build(["user" => ["id" => 555000111, "first_name" => "Ali"], "start_param" => "ref_7"], getenv("BOT_TOKEN"));
' > /tmp/init.txt

curl -s https://your.domain/miniapp/shop.php --data-urlencode "initData@/tmp/init.txt"
```

> `build()` is for **development only** — it exists so you can test the validator; it is not needed in production and must obviously never be exposed as an endpoint.

## Security checklist

- Validate server-side on **every** privileged request; the signature proves the *user*, not the *request's business logic*.
- Keep `max_age` tight (`3600` or less) — `initData` does not expire on its own.
- The comparison uses `hash_equals` (constant-time). Never compare hashes with `===`.
- Do not log `initData` or bot tokens; they are bearer credentials.
- The `signature` field (Ed25519) that Telegram also sends is for validating **third-party** Mini Apps; for your own bot, the HMAC `hash` check above is the official and sufficient scheme.
- Serve the frontend over HTTPS only — Telegram requires it and `initData` is only as trustworthy as the channel it travels over.

## How the validation works (reference)

1. Parse `initData` as a query string; remove `hash`.
2. Data-check-string = every remaining field as `key=value`, sorted alphabetically by key, joined with `\n` (0x0A).
3. `secret_key = HMAC_SHA256(key="WebAppData", message=BOT_TOKEN)`
4. `computed   = HMAC_SHA256(key=secret_key, message=data_check_string)` — hex.
5. Valid iff `computed == hash` (constant-time), and `auth_date` within `max_age`.

The test suite pins this with a **cross-language vector**: `tests/Unit/MiniAppInitDataTest.php` validates an initData string generated independently in Python — catching the classic silent bug of HMAC key/message arguments swapped.
