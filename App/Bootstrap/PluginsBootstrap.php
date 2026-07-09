<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Bootstrap;

use alirezax5\TelegramBase\App\Plugin\PluginHandler;
use alirezax5\TelegramBase\App\Logger\LogHandler;

final class PluginsBootstrap
{
    private static ?PluginHandler $plugins = null;

    public static function boot(): void
    {
        self::$plugins = new PluginHandler();
        LogHandler::info('Plugins initialized');
    }

    public static function getPlugins(): ?PluginHandler
    {
        return self::$plugins;
    }
}