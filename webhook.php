<?php

declare(strict_types=1);

/**
 * Webhook entry point.
 *
 * Point Telegram's setWebhook at this file (HTTPS required). The incoming
 * request is validated (body size / Telegram IP / secret token) and then
 * dispatched according to BOT_MODE:
 *
 *   - webhook_direct : process the update synchronously, respond 200
 *   - webhook_queue  : enqueue the update for a queue worker, respond 200
 *
 * Any CLI-only BOT_MODE is rejected immediately with HTTP 500 (F008 guard)
 * instead of hanging the web worker inside an infinite polling loop.
 */

use alirezax5\TelegramBase\App\Core;
use alirezax5\TelegramBase\App\Enum\CoreMode;

require __DIR__ . '/vendor/autoload.php';

$core = new Core(CoreMode::FULL);
$core->run();
