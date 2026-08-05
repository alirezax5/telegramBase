<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Middleware\Middlewares;

use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Middleware\MiddlewareInterface;
use telegramBotApiPhp\Telegram;

/**
 * Logs every update that passes through, with latency.
 */
final class AuditLogMiddleware implements MiddlewareInterface
{
    /**
     * @inheritDoc
     */
    public function handle(object $update, Telegram $Telegram, callable $next): mixed
    {
        $start = microtime(true);

        $result = $next();

        $ms = round((microtime(true) - $start) * 1000, 2);
        LogHandler::debug('Audit update processed', [
            'update_id' => $update->update_id ?? null,
            'ms' => $ms,
        ]);

        return $result;
    }
}