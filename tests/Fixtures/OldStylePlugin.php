<?php

declare(strict_types=1);

/**
 * Backward-compatibility fixture plugin.
 *
 * Written in the "old style" (pre-Phase-2 API):
 *   - implements PluginInterface (the original contract)
 *   - uses #[ChatType] attribute (Phase 1)
 *   - has onMessage handler (original pattern)
 *   - uses $Telegram->fromId() and $Telegram->sendMessage() (original SDK calls)
 *   - does NOT use any Phase 2 features (no middleware, no tg_retryable)
 *
 * Phase 2 must keep this plugin working unchanged.
 *
 * This file lives in tests/Fixtures/ (not Plugin/) so it's NOT
 * auto-discovered. The BackwardCompatTest loads it manually via
 * require_once and dispatches a synthetic update through the
 * Processor → PluginHandler pipeline.
 */

namespace alirezax5\TelegramBase\Plugin\Fixtures;

use alirezax5\TelegramBase\App\Attributes\ChatType;
use alirezax5\TelegramBase\App\Plugin\Contract\PluginInterface;
use telegramBotApiPhp\Telegram;

#[ChatType(['private'])]
class OldStylePlugin implements PluginInterface
{
    public static bool $onMessageCalled = false;
    public static ?object $receivedUpdate = null;
    public static ?Telegram $receivedTelegram = null;

    public function getPriority(): int
    {
        return 5;
    }

    public function onMessage($update, Telegram $Telegram): void
    {
        self::$onMessageCalled = true;
        self::$receivedUpdate = $update;
        self::$receivedTelegram = $Telegram;

        // Old-style direct API call — no tg_retryable wrapper.
        $chatid = $Telegram->fromId();
        $Telegram->sendMessage($chatid, 'old-style plugin says hello');
    }
}
