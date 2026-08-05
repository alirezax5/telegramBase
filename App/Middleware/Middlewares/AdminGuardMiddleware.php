<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Middleware\Middlewares;

use alirezax5\TelegramBase\App\Environment\EnvHandler;
use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Middleware\MiddlewareInterface;
use telegramBotApiPhp\Telegram;

/**
 * Only allows chat ids in the ADMINS_WHITELIST env (comma-separated) to pass.
 * Leave ADMINS_WHITELIST empty to allow everyone.
 */
final class AdminGuardMiddleware implements MiddlewareInterface
{
    /**
     * @inheritDoc
     */
    public function handle(object $update, Telegram $Telegram, callable $next): mixed
    {
        $allowed = EnvHandler::get('ADMINS_WHITELIST', '');

        if ($allowed === '') {
            return $next();
        }

        $admins = array_map('trim', explode(',', $allowed));
        $chatId = $update->message->chat->id
            ?? $update->callback_query?->from->id
            ?? null;

        if (!in_array((string)$chatId, $admins, true)) {
            LogHandler::warning("⛔ Access denied for chat {$chatId}");
            return null;
        }

        return $next();
    }
}