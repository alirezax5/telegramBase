<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Bootstrap;

use alirezax5\TelegramBase\App\Config\Config;
use alirezax5\TelegramBase\App\Plugin\PluginHandler;
use alirezax5\TelegramBase\App\Queue\QueueManager;
use alirezax5\TelegramBase\App\Paths;
use alirezax5\TelegramBase\App\Logger\LogHandler;
final class PluginsBootstrap
{
    public static  PluginHandler  $plugins ;

    public static function boot()
    {
        self::$plugins = new PluginHandler();

        LogHandler::info('Plugins initialized');
    }
}