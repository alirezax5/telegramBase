<?php

declare(strict_types=1);

/**
 * Scenario 1 — Admin panel with MenuFlow (back / cancel / input screens).
 *
 * The TM-25 bug class: panels hand-rolled with raw state variables end up
 * with input screens that have NO cancel button, and dead reply keyboards
 * left behind after leaving a flow. MenuFlow fixes both for free:
 *
 *   - every input screen gets «↩️ بازگشت» / «🏠 منوی اصلی» built-in
 *   - exit() sends remove_keyboard so no dead keyboard is left behind
 *   - label → screen navigation replaces hand-written switch/case chains
 *
 * ⚠️  This file lives in examples/ (NOT Plugin/) so it is NOT auto-
 * discovered. To activate:
 *   1. Copy to Plugin/PanelBot.php
 *   2. Change the namespace to: alirezax5\TelegramBase\Plugin
 *   3. Add ADMIN_IDS="123456789" to .env
 *   4. Restart the bot
 *
 * Commands & buttons:
 *   /panel            → opens the admin panel (admins only)
 *   🚫 بلاک           → input screen asking for a user id (back/home live here)
 *   📊 آمار           → renders stats and returns to the panel
 *   ↩️ بازگشت         → walks back to the parent screen
 *   🏠 منوی اصلی      → always returns to the main screen
 *
 * Demonstrates:
 *   - MenuFlow::make() → screen() / nav() / parse screens / onInput()
 *   - Keyboard::reply() builder instead of hand-written JSON
 *   - Keyboard::remove() for flow exit (TM-25 contract)
 *   - an admin gate without needing any middleware
 */

namespace alirezax5\TelegramBase\Examples;

use alirezax5\TelegramBase\App\Attributes\ChatType;
use alirezax5\TelegramBase\App\Button\Keyboard;
use alirezax5\TelegramBase\App\Conversation\MenuFlow;
use alirezax5\TelegramBase\App\Environment\EnvHandler;
use alirezax5\TelegramBase\App\Plugin\Contract\PluginInterface;
use alirezax5\TelegramBase\App\Update\UpdateState;
use telegramBotApiPhp\Telegram;

class PanelBot implements PluginInterface
{
    private MenuFlow $menu;

    public function __construct()
    {
        $this->menu = MenuFlow::make('examples.panel')
            ->screen('main', [
                'text' => '🛠 پنل مدیریت',
                'kb'   => fn () => Keyboard::reply()
                    ->button('🚫 بلاک')->row()
                    ->button('📊 آمار')->row()
                    ->button('🏠 منوی اصلی')
                    ->resize()
                    ->toArray(),
            ])
            ->screen('block', [
                'parent' => 'main',
                'text'   => "آیدی عددی کاربری که باید بلاک شود را بفرستید.\n\nبرای انصراف «↩️ بازگشت» را بزنید.",
                'parse'  => true,
                'kb'     => fn () => Keyboard::reply()
                    ->button('↩️ بازگشت')->row()
                    ->button('🏠 منوی اصلی')
                    ->resize()
                    ->toArray(),
                'onInput' => function (Telegram $tg, int|string $chatId, string $text, MenuFlow $flow): void {
                    if (!ctype_digit($text)) {
                        $flow->say($tg, $chatId, '❌ فقط آیدی عددی معتبر بفرستید.');
                        return; // stay on the input screen — cancel is one tap away
                    }

                    // ...your real block logic here (DB update, notify, ...)...
                    $flow->say($tg, $chatId, "✅ کاربر {$text} بلاک شد.");

                    $flow->go($tg, $chatId, 'main');
                },
            ])
            ->nav('🚫 بلاک', 'block');
    }

    public function getPriority(): int
    {
        return 10;
    }

    #[ChatType(['private'])]
    public function onMessage($message, Telegram $tg): void
    {
        $text = trim((string)($message->text ?? ''));
        $chatId = (int)($message->chat->id ?? 0);

        if ($text === '/panel') {
            if (!$this->isAdmin($chatId)) {
                return; // silently ignore non-admins
            }

            $this->menu->go($tg, $chatId, 'main');
            UpdateState::markHandled('examples.panel');
            return;
        }

        if (!$this->isAdmin($chatId)) {
            return;
        }

        if ($this->menu->handle($message, $tg)) {
            UpdateState::markHandled('examples.panel');
        }
    }

    private function isAdmin(int $chatId): bool
    {
        $ids = array_map('intval', array_filter(explode(
            ',',
            (string)EnvHandler::get('ADMIN_IDS', '')
        )));

        return in_array($chatId, $ids, true);
    }
}
