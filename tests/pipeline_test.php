<?php
/**
 * Full pipeline test: simulates exactly what PollingLoop does with a
 * fetched update (object form), so we can verify the plugin responds.
 */
require __DIR__ . '/../vendor/autoload.php';

use alirezax5\TelegramBase\App\Core;
use alirezax5\TelegramBase\App\Enum\CoreMode;
use alirezax5\TelegramBase\App\Update\Processor;

$core = new Core(CoreMode::FULL);

// Replicate Fetcher's output: stdClass update objects
$update = (object)[
    'update_id' => 900000001,
    'message' => (object)[
        'message_id' => 9001,
        'from' => (object)[
            'id' => 82267967,
            'is_bot' => false,
            'first_name' => 'Alireza x5',
            'username' => 'Alirezax5_org',
        ],
        'chat' => (object)[
            'id' => 82267967,
            'type' => 'private',
        ],
        'date' => time(),
        'text' => '/start',
    ],
];

$processor = new Processor(
    alirezax5\TelegramBase\App\Bootstrap\PluginsBootstrap::getPlugins(),
    $core->Telegram
);

echo "=== Dispatching update through Processor (same as PollingLoop) ===\n";
$processor->handle($update);
echo "=== Done. Check Telegram for the bot's reply ===\n";
