<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Update;

/**
 * Per-update handled flag (process-local, reset for every update).
 *
 * telegramBase dispatches updates in broadcast style — every plugin whose
 * hook matches the update type runs, in priority order. There is no
 * "first match wins" semantics, so the framework cannot know on its own
 * whether an update was actually answered. This class gives plugins an
 * explicit, tiny contract:
 *
 *   - A plugin that recognized the update and replied (or otherwise took
 *     responsibility for it) calls UpdateState::markHandled().
 *   - Plugins implementing {@see \alirezax5\TelegramBase\App\Plugin\Contract\FallbackInterface}
 *     are invoked by PluginHandler only when the flag was NOT set —
 *     the canonical "command not found" hook.
 *
 * State lives for the lifetime of one update: PluginHandler::runAll()
 * resets it before dispatching, so webhook, polling and queue workers
 * (one update per handle() call) all get a clean slate.
 *
 * Usage inside a plugin:
 *
 *   public function onMessage($message, Telegram $tg): void
 *   {
 *       if (($message->text ?? '') !== '/ping') {
 *           return; // not ours — do NOT mark handled
 *       }
 *       $tg->sendMessage([...]);
 *       UpdateState::markHandled();
 *   }
 */
final class UpdateState
{
    private static bool $handled = false;

    private static ?string $handledBy = null;

    /**
     * Mark the current update as handled.
     *
     * @param string|null $by Optional identifier (FQCN or command) recorded
     *                        for debugging — exposed via handledBy().
     */
    public static function markHandled(?string $by = null): void
    {
        self::$handled = true;
        self::$handledBy = $by;
    }

    /**
     * Whether any plugin marked the current update as handled.
     */
    public static function isHandled(): bool
    {
        return self::$handled;
    }

    /**
     * Identifier passed to markHandled() by the plugin that took the
     * update (null when nothing marked it, or when no id was given).
     */
    public static function handledBy(): ?string
    {
        return self::$handledBy;
    }

    /**
     * Clear the flag. Called by PluginHandler before each update and
     * safe to call in tests between cases.
     */
    public static function reset(): void
    {
        self::$handled = false;
        self::$handledBy = null;
    }
}
