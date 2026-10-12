<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\TestBot\Middleware;

use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Middleware\MiddlewareInterface;
use telegramBotApiPhp\Telegram;

/**
 * Logs every update with latency. Used to verify middleware executes.
 */
class LoggingMiddleware implements MiddlewareInterface
{
    public static int $callCount = 0;

    public function handle(object $update, Telegram $tg, callable $next): mixed
    {
        self::$callCount++;
        $start = microtime(true);
        $updateId = $update->update_id ?? '?';

        LogHandler::debug("[TestBot] Middleware BEFORE update={$updateId}");

        $result = $next();

        $ms = round((microtime(true) - $start) * 1000, 2);
        LogHandler::debug("[TestBot] Middleware AFTER update={$updateId} ({$ms}ms)");

        return $result;
    }
}
