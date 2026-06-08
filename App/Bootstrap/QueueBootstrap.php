<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Bootstrap;

use alirezax5\TelegramBase\App\Config\Config;
use alirezax5\TelegramBase\App\Queue\QueueManager;
use alirezax5\TelegramBase\App\Paths;

final class QueueBootstrap
{
    public static $queue;

    public static function boot(): void
    {
        if (!in_array(Config::bot()->mode, ['update_queue', 'webhook_queue']))
            return;

        self::$queue = new QueueManager(
            Config::queue()
        );
    }
}