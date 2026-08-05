<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Middleware;

use telegramBotApiPhp\Telegram;

/**
 * Middleware contract.
 *
 * Each middleware inspects `$update` (and the Telegram instance), optionally
 * short-circuits the pipeline, or forwards to `$next` to continue.
 */
interface MiddlewareInterface
{
    /**
     * @param object    $update   Telegram update (already normalized to object)
     * @param Telegram  $Telegram Bot API instance
     * @param callable  $next     Continue to next middleware / final dispatcher
     * @return mixed
     */
    public function handle(object $update, Telegram $Telegram, callable $next): mixed;
}