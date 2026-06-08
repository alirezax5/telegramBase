<?php

namespace alirezax5\TelegramBase\Plugin;

use alirezax5\TelegramBase\App\Plugin\Contract\PluginInterface;

use telegramBotApiPhp\Telegram;

class start implements PluginInterface
{
    public function getPriority(): int
    {
        return 1;
    }

    public function onMessage($update, Telegram $Telegram)
    {
        if (!$Telegram->isPrivate())
            return;
        $chatid = $Telegram->fromId();
        $text = $Telegram->text();
        $Telegram->sendMessage($chatid, 'start main', [
            'reply_markup' => btn('start')
        ]);


        return;
    }


}