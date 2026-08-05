<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Middleware\Middlewares;

use alirezax5\TelegramBase\App\Cache\CacheManager;
use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Middleware\MiddlewareInterface;
use telegramBotApiPhp\Telegram;

/**
 * Sliding-window rate limiter, one counter per chat id.
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly int $max = 10,
        private readonly int $window = 60,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function handle(object $update, Telegram $Telegram, callable $next): mixed
    {
        $chatId = $update->message->chat->id
            ?? $update->callback_query?->from->id
            ?? $update->chat_member?->from->id
            ?? null;

        if ($chatId === null) {
            return $next();
        }

        $key = 'tgbase:ratelimit:' . $chatId;
        $count = CacheManager::get($key, 0);

        if ($count === 0) {
            CacheManager::put($key, 1, $this->window);
        } else {
            $count = (int)CacheManager::increment($key);

            if ($count > $this->max) {
                LogHandler::warning("⛔ Rate limited: chat {$chatId} ({$count}/{$this->window}s)");
                return null;
            }
        }

        return $next();
    }
}