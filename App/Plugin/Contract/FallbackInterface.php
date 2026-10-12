<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Plugin\Contract;

use telegramBotApiPhp\Telegram;

/**
 * Fallback contract — the framework-level "command not found" hook.
 *
 * PluginHandler runs every plugin in broadcast style. AFTER the normal
 * on{UpdateType} phase, if no plugin called
 * {@see \alirezax5\TelegramBase\App\Update\UpdateState::markHandled()},
 * every registered FallbackInterface plugin receives the SAME payload
 * through onFallback(), in getPriority() order (lowest first), filtered
 * by the ChatType attribute like any other hook.
 *
 * Typical use: a single Fallback plugin that answers unknown /commands
 * with a localized message:
 *
 *   #[ChatType(['private', 'group'])]
 *   final class Fallback implements PluginInterface, FallbackInterface
 *   {
 *       public function getPriority(): int { return 1000; }
 *
 *       public function onMessage($message, Telegram $tg): void
 *       {
 *           if (is_string($message?->text ?? null) && str_starts_with($message->text, '/')) {
 *               UpdateState::markHandled(); // known command — suppress fallback
 *           }
 *       }
 *
 *       public function onFallback($data, Telegram $tg): void
 *       {
 *           $text = $data->text ?? '';
 *           if (is_string($text) && str_starts_with($text, '/')) {
 *               $tg->sendMessage([...__('fallback.unknown', [], ['cmd' => $text]) ...]);
 *           }
 *       }
 *   }
 *
 * Keep fallback handlers defensive: they can fire for ANY update type
 * (message, callback_query, ...) that no plugin marked handled.
 */
interface FallbackInterface
{
    /**
     * Called when no plugin marked the update as handled.
     *
     * @param mixed    $data Type payload (message / callback_query / ...) —
     *                       identical to what on{UpdateType} receives
     * @param Telegram $tg   Bot API instance
     */
    public function onFallback($data, Telegram $tg): void;
}
