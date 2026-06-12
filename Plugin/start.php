<?php

namespace alirezax5\TelegramBase\Plugin;

use alirezax5\TelegramBase\App\Attributes\ChatType;
use alirezax5\TelegramBase\App\Plugin\Contract\PluginInterface;

use telegramBotApiPhp\Telegram;

#[ChatType(['group'])]

class start implements PluginInterface
{


    public function getPriority(): int
    {
        return 1;
    }

    public function onMessage($update, Telegram $Telegram)
    {

        $chatid = $Telegram->fromId();
        $text = $Telegram->text();
        $Telegram->sendMessage($chatid, __('btn.btna'), [
            'reply_markup' => btn('start')
        ]);


        return;
    }


}