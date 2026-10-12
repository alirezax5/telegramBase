<?php

/**
 * Mini App backend endpoint — validate `initData` and serve trusted data.
 *
 * A Telegram Mini App has two halves:
 *
 *   1. FRONTEND (this file's `renderPage()`): an HTML page opened inside
 *      Telegram via a Web App button — create one from a plugin with
 *      Keyboard::reply()->webApp('Open Shop', 'https://your.domain/miniapp/shop.php')
 *      or set it as the bot's menu button in BotFather.
 *
 *   2. BACKEND (this file's API half): plain HTTPS endpoints on YOUR server.
 *      Mini App traffic does NOT pass through the Telegram webhook — it is
 *      the user's browser calling your domain directly, so nothing in
 *      BOT_MODE / allowed_updates is involved. The ONLY proof that the
 *      request really comes from your Mini App user is the signed
 *      `Telegram.WebApp.initData` string, validated with App\MiniApp\InitData.
 *
 * Run: put this file on an HTTPS host (or `php -S 127.0.0.1:8088 MiniAppEndpoint.php`
 * for local testing — it serves both the page and the POST endpoint),
 * open the page in Telegram, and the profile card is rendered from
 * *validated* identity data — never from unsigned `initDataUnsafe`.
 */

declare(strict_types=1);

use alirezax5\TelegramBase\App\MiniApp\InitData;

require __DIR__ . '/../vendor/autoload.php';

// Load the project .env when present, so BOT_TOKEN auto-detection works for
// standalone endpoints that do not boot the whole framework. If you keep the
// .env elsewhere (or use another secret store), drop this block and pass the
// token explicitly: InitData::validate($raw, $botToken).
if (class_exists(\Dotenv\Dotenv::class) && is_file(__DIR__ . '/../.env')) {
    \Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}

/* -------------------------------------------------------------------------
 * API half: POST {initData: "..."} → validated JSON
 * ---------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    // Accept classic form POST or a JSON body (fetch() style).
    $raw = $_POST['initData'] ?? null;
    if (!is_string($raw) || $raw === '') {
        $body = (string) file_get_contents('php://input');
        if ($body === '' && PHP_SAPI === 'cli') {
            $body = (string) stream_get_contents(STDIN);   // CLI testing convenience only
        }
        $json = json_decode($body, true);
        $raw  = is_array($json) ? (string) ($json['initData'] ?? '') : '';
    }

    // BOT_TOKEN is read from the environment; for a multi-bot setup pass the
    // owning bot's token explicitly: InitData::validate($raw, $extraToken).
    // max_age: reject replays older than one hour (default 86400).
    $res = InitData::validate($raw, null, ['max_age' => 3600]);

    if (!$res['ok']) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => $res['error']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $me = $res['user'] ?? [];

    echo json_encode([
        'ok'          => true,
        'user_id'     => $me['id'] ?? null,
        'name'        => trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')),
        'username'    => $me['username'] ?? null,
        'start_param' => $res['start_param'],   // deep-link: /yourbot?startapp=ref_7
        'auth_date'   => $res['auth_date'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* -------------------------------------------------------------------------
 * Frontend half: the Mini App page itself
 * ---------------------------------------------------------------------- */
header('Content-Type: text/html; charset=utf-8');
echo renderPage();

function renderPage(): string
{
    return <<<'HTML'
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>پروفایل</title>
<script src="https://telegram.org/js/telegram-web-app.js"></script>
<style>
  body { font-family: Tahoma, sans-serif; background: var(--tg-theme-bg-color, #fff);
         color: var(--tg-theme-text-color, #111); padding: 16px; }
  .card { border-radius: 12px; padding: 16px; background: var(--tg-theme-secondary-bg-color, #f2f2f2); }
  .err { color: #c0392b; }
</style>
</head>
<body>
  <h2>پروفایل من</h2>
  <div class="card" id="out">در حال اعتبارسنجی…</div>
  <script>
    // initData is SIGNED by Telegram — send it to the backend and trust only
    // the validated response. Never read initDataUnsafe on the server side.
    fetch(location.pathname, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ initData: Telegram.WebApp.initData })
    })
      .then(r => r.json())
      .then(d => {
        document.getElementById('out').innerHTML = d.ok
          ? 'سلام <b>' + d.name + '</b>!<br>شناسه: ' + d.user_id +
            (d.start_param ? '<br>پارامتر ورود: ' + d.start_param : '')
          : '<span class="err">اعتبارسنجی ناموفق: ' + d.error + '</span>';
      })
      .catch(e => { document.getElementById('out').textContent = 'خطا: ' + e; });
  </script>
</body>
</html>
HTML;
}
