<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\TestBot\Middleware;

use alirezax5\TelegramBase\App\Middleware\MiddlewareInterface;
use telegramBotApiPhp\Telegram;

/**
 * Demonstrates middleware short-circuit behavior.
 * Not registered by default — used in scenario tests.
 */
class ShortCircuitMiddleware implements MiddlewareInterface
{
    public static bool $active = false;

    public function handle(object $update, Telegram $tg, callable $next): mixed
    {
        if (self::$active) {
            // Short-circuit: do NOT call $next() — plugins never run.
            return null;
        }
        return $next();
    }
}
