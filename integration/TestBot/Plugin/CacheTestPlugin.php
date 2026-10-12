<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\Plugin;

use alirezax5\TelegramBase\App\Attributes\ChatType;
use alirezax5\TelegramBase\App\Cache\CacheManager;
use alirezax5\TelegramBase\App\Plugin\Contract\PluginInterface;
use function alirezax5\TelegramBase\App\Queue\tg_retryable;
use telegramBotApiPhp\Telegram;

#[ChatType(['private'])]
class CacheTestPlugin implements PluginInterface
{
    public function getPriority(): int { return 5; }

    public function onMessage($message, Telegram $tg): void
    {
        $text = trim($message->text ?? '');
        $chatId = $tg->chatId();
        if ($chatId === null) return;

        // /cache <key> <value> — set
        // /cache <key>         — get
        // /cache               — info
        if (str_starts_with($text, '/cache')) {
            $parts = explode(' ', $text, 3);

            if (count($parts) === 1) {
                // No args — show driver info
                $driver = CacheManager::getDriver();
                $resp = $tg->sendMessage($chatId, "[TGBASE TEST] Cache driver: {$driver}");
                tg_retryable($resp, 'sendMessage');
                return;
            }

            $key = 'testbot:' . $parts[1];

            if (count($parts) >= 3) {
                // Set
                $val = $parts[2];
                CacheManager::put($key, $val, 300);
                $resp = $tg->sendMessage($chatId, "[TGBASE TEST] Cached: {$parts[1]} = {$val}");
                tg_retryable($resp, 'sendMessage');
            } else {
                // Get
                $val = CacheManager::get($key, '<not set>');
                $resp = $tg->sendMessage($chatId, "[TGBASE TEST] Cache lookup: {$parts[1]} = {$val}");
                tg_retryable($resp, 'sendMessage');
            }
        }
    }
}
