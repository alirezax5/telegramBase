<?php

declare(strict_types=1);

/**
 * Webhook entry point.
 *
 * Point Telegram's setWebhook to this file (HTTPS required by Telegram).
 * The Core routes based on BOT_MODE:
 *   - webhook_direct: validate + process synchronously
 *   - webhook_queue : validate + enqueue, process via queue.php worker
 */

use alirezax5\TelegramBase\App\Core;
use alirezax5\TelegramBase\App\Enum\CoreMode;

require __DIR__ . '/vendor/autoload.php';

$core = new Core(CoreMode::FULL);
$core->run();
