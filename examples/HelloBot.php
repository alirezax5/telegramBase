<?php

declare(strict_types=1);

/**
 * Example plugin demonstrating common TelegramBase patterns.
 *
 * ⚠️  This file lives in examples/ (NOT Plugin/) so it is NOT auto-
 * discovered by the framework. It will not run unless you manually
 * copy it into Plugin/ and adjust the namespace.
 *
 * To activate:
 *   1. Copy this file to Plugin/HelloBot.php
 *   2. Change the namespace to: alirezax5\TelegramBase\Plugin
 *   3. Restart your bot
 *
 * Commands:
 *   /hello    — replies with a greeting + inline keyboard
 *   /status   — shows session state and update count
 *
 * Demonstrates:
 *   - command parsing from message text
 *   - inline keyboard via Keyboard builder
 *   - session read/write via SessionManager
 *   - safe API calls via tg_retryable() helper
 *   - callback query handling
 */

namespace alirezax5\TelegramBase\Examples;

use alirezax5\TelegramBase\App\Attributes\ChatType;
use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Plugin\Contract\PluginInterface;
use alirezax5\TelegramBase\App\Button\Keyboard;
use alirezax5\TelegramBase\App\Queue\RetryableException;
use alirezax5\TelegramBase\App\Session\SessionManager;
use function alirezax5\TelegramBase\App\Queue\tg_retryable;
use telegramBotApiPhp\Telegram;
#[ChatType(['private'])]
class HelloBot implements PluginInterface
{
    public function getPriority(): int
    {
        return 10;
    }

    public function onMessage($message, Telegram $tg): void
    {
        $text = trim($message->text ?? '');
        $chatId = $tg->chatId();

        if ($chatId === null) {
            return;
        }

        // Route by command.
        if ($text === '/hello') {
            $this->handleHello($chatId, $tg);
            return;
        }

        if ($text === '/status') {
            $this->handleStatus($chatId, $tg);
            return;
        }

        // Unknown text — ignore (don't spam on every message).
    }

    public function onCallback_query($callbackQuery, Telegram $tg): void
    {
        $data = $callbackQuery?->data ?? '';
        $chatId = $callbackQuery?->message?->chat?->id;

        if ($chatId === null) {
            return;
        }

        // Bump the session counter on any callback.
        $session = SessionManager::start($chatId);
        $count = (int)$session->get('callback_count', 0) + 1;
        $session->set('callback_count', $count);

        switch ($data) {
            case 'hello_refresh':
                // Edit the original message.
                $resp = $tg->editMessageText(
                    $chatId,
                    $callbackQuery->message->message_id,
                    "🔄 Refreshed! Callback #{$count}"
                );
                tg_retryable($resp, 'editMessageText');
                break;

            case 'hello_close':
                $tg->deleteMessage($chatId, $callbackQuery->message->message_id);
                break;
        }

        // Always answer the callback to clear the loading spinner.
        $tg->answerCallbackQuery($callbackQuery->id, "✓ #{$count}");
    }

    private function handleHello(int $chatId, Telegram $tg): void
    {
        $kb = Keyboard::inline()
            ->button('🔄 Refresh', 'hello_refresh')
            ->button('✖ Close', 'hello_close')
            ->toArray();

        $resp = $tg->sendMessage(
            $chatId,
            '👋 *Hello!* This is the HelloBot example plugin.',
            [
                'parse_mode' => 'Markdown',
                'reply_markup' => $kb,
            ]
        );

        // tg_retryable throws on 429/5xx → Processor catches → retry queue.
        // On 4xx it returns the response unchanged so we can log here.
        tg_retryable($resp, 'sendMessage');

        if (empty($resp->ok)) {
            LogHandler::warning('HelloBot sendMessage failed: ' . ($resp->description ?? '?'));
        }
    }

    private function handleStatus(int $chatId, Telegram $tg): void
    {
        $session = SessionManager::start($chatId);
        $callbackCount = (int)$session->get('callback_count', 0);
        $lastActivity = date('Y-m-d H:i:s', $session->lastActivity());

        $text = "📊 *Status*\n"
              . "Callbacks received: {$callbackCount}\n"
              . "Last activity: {$lastActivity}\n"
              . "Session ID: `" . substr($session->id(), 0, 8) . "…`";

        $resp = $tg->sendMessage($chatId, $text, ['parse_mode' => 'Markdown']);
        tg_retryable($resp, 'sendMessage');
    }
}
