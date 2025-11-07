<?php

namespace alirezax5\TelegramBase\Plugin;

use alirezax5\TelegramBase\App\Plugin\PluginBase;
use alirezax5\TelegramBase\App\Shared\SharedManagement;
use telegramBotApiPhp\Telegram;
use alirezax5\TelegramBase\App\Language\Language;

class Start implements PluginBase
{
    public function getPriority(): int
    {
        return 6;
    }

    public function onMessage($update, Telegram $telegram)
    {
        SharedManagement::set('findCommand', 'na');
            $telegram->sendMessage($update->from->id, $update->text,reply_markup: btn('start'));


    }
}