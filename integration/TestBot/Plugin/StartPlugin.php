<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\Plugin;

use alirezax5\TelegramBase\App\Attributes\ChatType;
use alirezax5\TelegramBase\App\Button\Keyboard;
use alirezax5\TelegramBase\App\Plugin\Contract\PluginInterface;
use alirezax5\TelegramBase\App\Session\SessionManager;
use function alirezax5\TelegramBase\App\Queue\tg_retryable;
use telegramBotApiPhp\Telegram;

#[ChatType(['private'])]
class StartPlugin implements PluginInterface
{
    public function getPriority(): int { return 1; }

    public function onMessage($message, Telegram $tg): void
    {
        $text = trim($message->text ?? '');
        $chatId = $tg->chatId();
        if ($chatId === null) return;

        if ($text === '/start' || $text === '/help') {
            $kb = Keyboard::inline()
                ->button('Ping', 'cmd_ping')
                ->button('Status', 'cmd_status')
                ->row()
                ->button('Session', 'cmd_session')
                ->button('Keyboard', 'cmd_keyboard')
                ->row()
                ->button('Error Test', 'cmd_error')
                ->toArray();

            $resp = $tg->sendMessage(
                $chatId,
                "[TGBASE TEST] 🤖 Test Bot running\n\nCommands:\n/start /help /ping /status\n/session /cache /db /queue\n/keyboard /error /admin",
                ['reply_markup' => $kb]
            );
            tg_retryable($resp, 'sendMessage');
        }

        if ($text === '/ping') {
            $resp = $tg->sendMessage($chatId, '[TGBASE TEST] 🏓 pong');
            tg_retryable($resp, 'sendMessage');
        }

        if ($text === '/status') {
            $session = SessionManager::start($chatId);
            $visits = (int)$session->get('visits', 0) + 1;
            $session->set('visits', $visits);

            $resp = $tg->sendMessage(
                $chatId,
                "[TGBASE TEST] 📊 Status\nVisits: {$visits}\nSession: " . substr($session->id(), 0, 8) . "…\nChat: {$chatId}\nUser: " . ($tg->fromId() ?? '?')
            );
            tg_retryable($resp, 'sendMessage');
        }
    }

    public function onCallback_query($cq, Telegram $tg): void
    {
        $data = $cq?->data ?? '';
        $chatId = $cq?->message?->chat?->id;
        if ($chatId === null) return;

        $tg->answerCallbackQuery($cq->id, '✓');

        // Re-dispatch as a text command by sending the corresponding message.
        $map = [
            'cmd_ping' => '/ping',
            'cmd_status' => '/status',
            'cmd_session' => '/session',
            'cmd_keyboard' => '/keyboard',
            'cmd_error' => '/error',
        ];

        if (isset($map[$data])) {
            // Edit the original message to show the command was received.
            $tg->editMessageText(
                $chatId,
                $cq->message->message_id,
                "[TGBASE TEST] → {$map[$data]}"
            );
        }
    }
}
