<?php

declare(strict_types=1);

/**
 * Scenario 3 — Multi-step Conversation with Back navigation.
 *
 * The Conversation engine already ships first-class «بازگشت» support —
 * this example shows the minimal shape so devs stop hand-rolling wizards.
 * Every input step renders with validation+retry, and backable()/menu()
 * add the nav buttons (TM-16) with answer pruning.
 *
 * Enable the engine once in .env:
 *     CORE_PLUGINS="alirezax5\TelegramBase\App\Conversation\ConversationManager"
 * and run the conversations migration (see docs conversations.md).
 *
 * ⚠️  To activate:
 *   1. Copy to Plugin/OrderFlow.php and change the namespace
 *   2. Trigger it: a plugin's onMessage() sees «/order» and calls
 *      ConversationManager::begin(new self(), $tg, $chatId)
 *
 * Flow:  description → price → confirm(callback) → onFinish
 *        «بازگشت» jumps to the previous input step, answers are pruned.
 */

namespace alirezax5\TelegramBase\Examples;

use alirezax5\TelegramBase\App\Attributes\ChatType;
use alirezax5\TelegramBase\App\Conversation\Conversation;
use alirezax5\TelegramBase\App\Conversation\Step;
use telegramBotApiPhp\Telegram;

class OrderFlow extends Conversation
{
    public function flowKey(): string
    {
        return 'examples.order';
    }

    protected function steps(): array
    {
        return [
            'desc' => Step::text()
                ->ask('شرح سفارش را بنویسید:')
                ->validate(fn ($v) => mb_strlen($v) >= 3)
                ->retry('❌ شرح خیلی کوتاه است.')
                ->backable(),

            'price' => Step::text()
                ->ask('قیمت (تومان) را بفرستید:')
                ->pattern('#^\d+$#')
                ->retry('❌ فقط عدد.')
                ->skipable()          // /skip stores null
                ->backable(),

            'confirm' => Step::callback()
                ->ask('ثبت شود؟')
                ->buttons(['yes' => '✅ بله', 'no' => '❌ خیر'])
                ->menu(),             // adds «منوی اصلی» nav button
        ];
    }

    public function onFinish(array $answers, Telegram $tg, int $chatId): void
    {
        // $answers = ['desc' => ..., 'price' => '150000'|null, 'confirm' => 'yes'|'no']
        if (($answers['confirm'] ?? '') === 'yes') {
            $tg->sendMessage([
                'chat_id' => $chatId,
                'text' => "✅ سفارش ثبت شد:\n{$answers['desc']}\nقیمت: {$answers['price']}",
            ]);
        } else {
            $tg->sendMessage([
                'chat_id' => $chatId,
                'text' => 'سفارش لغو شد.',
                'reply_markup' => json_encode(
                    \alirezax5\TelegramBase\App\Button\Keyboard::remove()
                ),
            ]);
        }
    }
}
