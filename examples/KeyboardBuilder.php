<?php

declare(strict_types=1);

/**
 * Scenario 4 — Keyboard builder patterns (never hand-write JSON again).
 *
 * PANEL-style keyboards used to be written as raw JSON strings — one bad
 * brace and the whole keyboard silently breaks. The Keyboard builder covers
 * reply keyboards, inline keyboards, and the request-shaped payloads
 * (remove_keyboard / force_reply), plus the Mini App button.
 *
 * ⚠️  To activate: copy to Plugin/KeyboardDemo.php, change the namespace,
 * restart. Then send /kb in private.
 *
 * Demonstrates:
 *   Keyboard::inline()  … callback/url buttons, rows
 *   Keyboard::reply()   … resize/oneTime/selective
 *   Keyboard::remove()  … TM-25 keyboard hygiene (exit a flow cleanly)
 *   Keyboard::forceReply()
 *   Keyboard::webApp()  … Telegram Mini App button
 */

namespace alirezax5\TelegramBase\Examples;

use alirezax5\TelegramBase\App\Attributes\ChatType;
use alirezax5\TelegramBase\App\Button\Keyboard;
use alirezax5\TelegramBase\App\Plugin\Contract\PluginInterface;
use alirezax5\TelegramBase\App\Update\UpdateState;
use telegramBotApiPhp\Telegram;

class KeyboardDemo implements PluginInterface
{
    public function getPriority(): int
    {
        return 10;
    }

    #[ChatType(['private'])]
    public function onMessage($message, Telegram $tg): void
    {
        $text = trim((string)($message->text ?? ''));
        $chatId = $tg->chatId();

        if ($text === '/kb') {
            // ---- reply keyboard (panel style) ----
            $tg->callRaw('sendMessage', [
                'chat_id' => $chatId,
                'text' => '🔍 کیبورد پنل:',
                'reply_markup' => json_encode(
                    Keyboard::reply()
                        ->button('📤 پیام همگانی')->button('📤 پیام به کاربر')
                        ->row()
                        ->button('📊 آمار')
                        ->resize()
                        ->toArray(),
                    JSON_UNESCAPED_UNICODE
                ),
            ]);

            UpdateState::markHandled();
            return;
        }

        if ($text === '/inline') {
            // ---- inline keyboard ----
            $tg->callRaw('sendMessage', [
                'chat_id' => $chatId,
                'text' => '🔹 اینلاین:',
                'reply_markup' => json_encode(
                    Keyboard::inline()
                        ->button('✅ تایید', 'demo:yes')
                        ->button('❌ رد', 'demo:no')
                        ->row()
                        ->button('وب‌سایت', 'https://alirezax5.ir', Keyboard::URL)
                        ->toArray(),
                    JSON_UNESCAPED_UNICODE
                ),
            ]);

            UpdateState::markHandled();
            return;
        }

        if ($text === '/close') {
            // ---- keyboard hygiene: remove_keyboard (TM-25) ----
            $tg->callRaw('sendMessage', [
                'chat_id' => $chatId,
                'text' => '👋 کیبورد بسته شد.',
                'reply_markup' => json_encode(Keyboard::remove()),
            ]);

            UpdateState::markHandled();
            return;
        }

        if ($text === '/force') {
            $tg->callRaw('sendMessage', [
                'chat_id' => $chatId,
                'text' => '✍️ جواب سوال بالا را به شکل ریپل بفرست:',
                'reply_markup' => json_encode(Keyboard::forceReply(false, 'جواب…')),
            ]);

            UpdateState::markHandled();
            return;
        }

        if ($text === '/app') {
            // ---- Mini App button ----
            $tg->callRaw('sendMessage', [
                'chat_id' => $chatId,
                'text' => '🛍 مینی‌اپ:',
                'reply_markup' => json_encode(
                    Keyboard::reply()->webApp('باز کردن فروشگاه', 'https://shop.example.com')->toArray(),
                    JSON_UNESCAPED_UNICODE
                ),
            ]);

            UpdateState::markHandled();
            return;
        }

        if ($text === 'demo' || $text === 'yes' || $text === 'no') {
            // callback handling would live in onCallbackQuery($cbQuery, $tg);
            // here we just acknowledge typed echoes.
            $tg->callRaw('sendMessage', ['chat_id' => $chatId, 'text' => "دریافت شد: {$text}"]);
            UpdateState::markHandled();
        }
    }

    public function onCallbackQuery($cb, Telegram $tg): void
    {
        if (!str_starts_with((string)($cb->data ?? ''), 'demo:')) {
            return;
        }

        $tg->callRaw('answerCallbackQuery', ['callback_query_id' => $cb->id]);
        UpdateState::markHandled();
    }
}
