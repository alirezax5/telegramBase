<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Bootstrap;

use alirezax5\TelegramBase\App\Config\Config;
use alirezax5\TelegramBase\App\Queue\QueueManager;
use alirezax5\TelegramBase\App\Logger\LogHandler;

final class QueueBootstrap
{
    private static ?QueueManager $queue = null;

    public static function boot(): void
    {
        $mode = Config::bot()->mode;

        if (!in_array($mode, ['update_queue', 'webhook_queue', 'cronjob_queue'], true)) {
            return;
        }

        self::$queue = new QueueManager(
            Config::queue()
        );

        LogHandler::info('Queue initialized');
    }

    public static function getQueue(): ?QueueManager
    {
        return self::$queue;
    }
}