<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\Plugin;

use alirezax5\TelegramBase\App\Attributes\ChatType;
use alirezax5\TelegramBase\App\Plugin\Contract\PluginInterface;
use alirezax5\TelegramBase\App\Queue\RetryableException;
use function alirezax5\TelegramBase\App\Queue\tg_retryable;
use telegramBotApiPhp\Telegram;

/**
 * Tests retry-queue behavior by intentionally throwing RetryableException
 * on /retry commands. The framework's Processor catches it and pushes
 * the update to the retry queue.
 */
#[ChatType(['private'])]
class FailureTestPlugin implements PluginInterface
{
    public function getPriority(): int { return 5; }

    public function onMessage($message, Telegram $tg): void
    {
        $text = trim($message->text ?? '');
        $chatId = $tg->chatId();
        if ($chatId === null) return;

        if ($text === '/error') {
            // Throw a plain exception — should be logged but not retried.
            throw new \RuntimeException('[TGBASE TEST] Intentional plugin error for testing');
        }

        if ($text === '/retry') {
            // Throw a RetryableException — should go to retry queue.
            throw new RetryableException('[TGBASE TEST] Intentional retryable error', 500);
        }

        if ($text === '/retry-after') {
            // Throw a RetryableException with retryAfter — should honor Telegram hint.
            throw new RetryableException('[TGBASE TEST] Simulated 429 with retry_after', 429, null, 3);
        }
    }
}
