<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Enum;

enum ChatTypeEnum: string
{
    case PRIVATE = 'private';
    case GROUP = 'group';
    case SUPERGROUP = 'supergroup';
    case CHANNEL = 'channel';
}