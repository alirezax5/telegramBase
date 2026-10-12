<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\Plugin;

use alirezax5\TelegramBase\App\Attributes\ChatType;
use alirezax5\TelegramBase\App\Button\Keyboard;
use alirezax5\TelegramBase\App\Plugin\Contract\PluginInterface;
use function alirezax5\TelegramBase\App\Queue\tg_retryable;
use telegramBotApiPhp\Telegram;

#[ChatType(['private'])]
class TelegramApiTestPlugin implements PluginInterface
{
    public function getPriority(): int { return 5; }

    public function onMessage($message, Telegram $tg): void
    {
        $text = trim($message->text ?? '');
        $chatId = $tg->chatId();
        if ($chatId === null) return;

        if ($text === '/keyboard') {
            $kb = Keyboard::inline()
                ->button('✅ Yes', 'cb_yes')
                ->button('❌ No', 'cb_no')
                ->row()
                ->button('🔄 Edit me', 'cb_edit')
                ->button('🗑 Delete', 'cb_delete')
                ->toArray();

            $resp = $tg->sendMessage(
                $chatId,
                '[TGBASE TEST] ⌨️ Inline keyboard test',
                ['reply_markup' => $kb]
            );
            tg_retryable($resp, 'sendMessage');
        }

        if ($text === '/reply-kb') {
            $kb = Keyboard::reply()
                ->button('Option A')
                ->button('Option B')
                ->resize()
                ->oneTime()
                ->toArray();

            $resp = $tg->sendMessage(
                $chatId,
                '[TGBASE TEST] Reply keyboard test',
                ['reply_markup' => $kb]
            );
            tg_retryable($resp, 'sendMessage');
        }

        if ($text === '/remove-kb') {
            $resp = $tg->sendMessage(
                $chatId,
                '[TGBASE TEST] Removing keyboard',
                ['reply_markup' => ['remove_keyboard' => true]]
            );
            tg_retryable($resp, 'sendMessage');
        }

        if ($text === '/html') {
            $resp = $tg->sendMessage(
                $chatId,
                '[TGBASE TEST] <b>bold</b> <i>italic</i> <code>code</code> <a href="https://example.com">link</a>',
                ['parse_mode' => 'HTML']
            );
            tg_retryable($resp, 'sendMessage');
        }

        if ($text === '/markdown') {
            $resp = $tg->sendMessage(
                $chatId,
                '[TGBASE TEST] *bold* _italic_ `code`',
                ['parse_mode' => 'MarkdownV2']
            );
            tg_retryable($resp, 'sendMessage');
        }

        if ($text === '/persian') {
            $resp = $tg->sendMessage($chatId, '[TGBASE TEST] سلام دنیا 🌍 این یک متن فارسی است');
            tg_retryable($resp, 'sendMessage');
        }

        if ($text === '/multiline') {
            $resp = $tg->sendMessage($chatId, "[TGBASE TEST] Line 1\nLine 2\nLine 3\n  • bullet\n  • bullet");
            tg_retryable($resp, 'sendMessage');
        }
    }

    public function onCallback_query($cq, Telegram $tg): void
    {
        $data = $cq?->data ?? '';
        $chatId = $cq?->message?->chat?->id;
        $msgId = $cq?->message?->message_id;
        if ($chatId === null || $msgId === null) return;

        switch ($data) {
            case 'cb_yes':
                $tg->answerCallbackQuery($cq->id, '✓ Yes');
                $tg->editMessageText($chatId, $msgId, '[TGBASE TEST] You chose: Yes');
                break;
            case 'cb_no':
                $tg->answerCallbackQuery($cq->id, '✓ No');
                $tg->editMessageText($chatId, $msgId, '[TGBASE TEST] You chose: No');
                break;
            case 'cb_edit':
                $tg->answerCallbackQuery($cq->id, '✓ Edited');
                $tg->editMessageText($chatId, $msgId, '[TGBASE TEST] 🔄 Message edited via callback');
                break;
            case 'cb_delete':
                $tg->answerCallbackQuery($cq->id, '✓ Deleted');
                $tg->deleteMessage($chatId, $msgId);
                break;
        }
    }
}
