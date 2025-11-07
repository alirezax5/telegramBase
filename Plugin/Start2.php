<?php

namespace alirezax5\TelegramBase\Plugin;

use alirezax5\TelegramBase\App\Plugin\PluginBase;
use alirezax5\TelegramBase\App\Shared\SharedManagement;
use telegramBotApiPhp\Telegram;

class Start2 implements PluginBase
{
    public function getPriority(): int
    {
        return 1;
    }


    public function onMessage( $update,Telegram $telegram)
    {

    }
}