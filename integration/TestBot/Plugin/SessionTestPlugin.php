<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\Plugin;

use alirezax5\TelegramBase\App\Attributes\ChatType;
use alirezax5\TelegramBase\App\Session\SessionManager;
use alirezax5\TelegramBase\App\Plugin\Contract\PluginInterface;
use function alirezax5\TelegramBase\App\Queue\tg_retryable;
use telegramBotApiPhp\Telegram;

#[ChatType(['private'])]
class SessionTestPlugin implements PluginInterface
{
    public function getPriority(): int { return 5; }

    public function onMessage($message, Telegram $tg): void
    {
        $text = trim($message->text ?? '');
        $chatId = $tg->chatId();
        if ($chatId === null) return;

        if ($text === '/session') {
            $session = SessionManager::start($chatId);
            $data = $session->all();
            $count = count($data);

            $resp = $tg->sendMessage(
                $chatId,
                "[TGBASE TEST] 📋 Session\nID: " . substr($session->id(), 0, 12) . "…\nKeys: {$count}\nData: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            );
            tg_retryable($resp, 'sendMessage');
        }

        // /session-set <key> <value>
        if (str_starts_with($text, '/session-set')) {
            $parts = explode(' ', $text, 3);
            if (count($parts) >= 3) {
                $session = SessionManager::start($chatId);
                $session->set($parts[1], $parts[2]);
                $resp = $tg->sendMessage($chatId, "[TGBASE TEST] Session set: {$parts[1]} = {$parts[2]}");
                tg_retryable($resp, 'sendMessage');
            }
        }

        // /session-get <key>
        if (str_starts_with($text, '/session-get')) {
            $parts = explode(' ', $text, 2);
            if (count($parts) >= 2) {
                $session = SessionManager::start($chatId);
                $val = $session->get($parts[1], '<not set>');
                $resp = $tg->sendMessage($chatId, "[TGBASE TEST] Session get: {$parts[1]} = {$val}");
                tg_retryable($resp, 'sendMessage');
            }
        }

        // /session-clear
        if ($text === '/session-clear') {
            SessionManager::flush($chatId);
            $resp = $tg->sendMessage($chatId, '[TGBASE TEST] Session cleared');
            tg_retryable($resp, 'sendMessage');
        }
    }
}
