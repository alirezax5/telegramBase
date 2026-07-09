<?php

namespace alirezax5\TelegramBase\Plugin;

use alirezax5\TelegramBase\App\Attributes\ChatType;
use alirezax5\TelegramBase\App\Plugin\Contract\PluginInterface;
use telegramBotApiPhp\Telegram;

#[ChatType(['private'])]
class start implements PluginInterface
{
    public function getPriority(): int
    {
        return 1;
    }

    public function onMessage($update, Telegram $Telegram): void
    {
        $chatid = $Telegram->fromId();
        $Telegram->sendMessage($chatid, __('main.ali'), [
            'reply_markup' => btn('a.start'),
        ]);
    }
}