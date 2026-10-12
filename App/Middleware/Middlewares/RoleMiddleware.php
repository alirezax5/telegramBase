<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Middleware\Middlewares;

use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Middleware\MiddlewareInterface;
use Database\Users;
use telegramBotApiPhp\Telegram;

/**
 * Database-role gate — the DB half of the two-tier access model.
 *
 * telegramBase ships two layers of privilege:
 *
 *   1. ADMINS_WHITELIST env (AdminGuardMiddleware) — hard-coded bot owner
 *      / superadmin chat ids. Always evaluated first, works even without
 *      a database.
 *   2. users.role column (this middleware) — per-bot roles assigned at
 *      runtime via /promote style commands:
 *      user < moderator < admin < superadmin.
 *
 * This middleware denies the update unless the sender's DB role ranks at
 * least at the configured minimum. Superadmins from ADMINS_WHITELIST
 * always pass (see Users::isAtLeast()).
 *
 * Opt-in wiring (middlewares are NOT auto-attached):
 *
 *   $core->middleware()->add(new RoleMiddleware('admin'));
 *   $core->middleware()->add(new RoleMiddleware('moderator'));
 *
 * Fail-closed: if the DB is unavailable or the users table is missing the
 * role column, the update is DENIED and the reason logged — never silently
 * allowed.
 */
final class RoleMiddleware implements MiddlewareInterface
{
    /**
     * @param string $minRole Minimum role required to pass
     *                        ('user' | 'moderator' | 'admin' | 'superadmin')
     */
    public function __construct(
        private readonly string $minRole = 'admin',
    ) {}

    /**
     * @inheritDoc
     */
    public function handle(object $update, Telegram $Telegram, callable $next): mixed
    {
        $chatId = $update->message?->chat?->id
            ?? $update->edited_message?->chat?->id
            ?? $update->channel_post?->chat?->id
            ?? $update->edited_channel_post?->chat?->id
            ?? $update->callback_query?->from?->id
            ?? null;

        if ($chatId === null) {
            LogHandler::warning(
                "⛔ RoleMiddleware ['{$this->minRole}'] denied update without a chat id"
            );
            return null;
        }

        try {
            $allowed = Users::isAtLeast((int) $chatId, $this->minRole);
        } catch (\Throwable $e) {
            LogHandler::error(
                "RoleMiddleware ['{$this->minRole}'] DB check failed — denying " .
                "chat {$chatId}: {$e->getMessage()}"
            );
            $allowed = false;
        }

        if (!$allowed) {
            LogHandler::warning(
                "⛔ RoleMiddleware ['{$this->minRole}'] denied chat {$chatId}"
            );
            return null;
        }

        return $next();
    }
}
