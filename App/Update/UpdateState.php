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
 *   - A plugin that must STOP every later plugin from seeing the same
 *     update (e.g. a force-join gate) additionally calls haltPropagation().
 *     PluginHandler then skips all remaining hooks for this update.
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

    /** TM-48: when true, PluginHandler stops dispatching further plugins. */
    private static bool $halted = false;

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
     * TM-48: stop the dispatch chain — no further plugin hooks (and no
     * fallback phase) run for the current update. Pair with
     * markHandled(). Intended for gates such as «join the channel
     * first» that must answer INSTEAD of every other feature.
     */
    public static function haltPropagation(?string $by = null): void
    {
        self::$halted = true;
        if (self::$handledBy === null && $by !== null) {
            self::$handledBy = $by;
        }
    }

    /** Whether haltPropagation() was called for the current update. */
    public static function isHalted(): bool
    {
        return self::$halted;
    }

    /**
     * Clear the flag. Called by PluginHandler before each update and
     * safe to call in tests between cases.
     */
    public static function reset(): void
    {
        self::$handled = false;
        self::$handledBy = null;
        self::$halted = false;
    }
}
