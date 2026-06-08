<?php

namespace alirezax5\TelegramBase\App\Config;

use alirezax5\TelegramBase\App\Bot\BotConfig;
use alirezax5\TelegramBase\App\Button\ButtonConfig;
use alirezax5\TelegramBase\App\Cache\CacheConfig;
use alirezax5\TelegramBase\App\Connection\ConnectionConfig;
use alirezax5\TelegramBase\App\Cron\CronConfig;
use alirezax5\TelegramBase\App\Database\DatabaseConfig;
use alirezax5\TelegramBase\App\Language\LanguageConfig;
use alirezax5\TelegramBase\App\Logger\LoggerConfig;
use alirezax5\TelegramBase\App\Plugin\PluginConfig;
use alirezax5\TelegramBase\App\Queue\QueueConfig;

final  class Config
{
    private static ?DatabaseConfig $database = null;

    private static AppConfig $instance;

    public static function init(AppConfig $config): void
    {
        self::$instance = $config;
    }

    public static function bot(): BotConfig
    {
        return self::$instance->bot;
    }

    public static function logger(): LoggerConfig
    {
        return self::$instance->logger;
    }

    public static function cache(): CacheConfig
    {
        return self::$instance->cache;
    }

    public static function Connection(): ConnectionConfig
    {
        return self::$instance->Connection;
    }

    public static function database(): DatabaseConfig
    {
        return self::$database
            ??= AppConfigFactory::createDatabaseConfig();
    }

    public static function language(): LanguageConfig
    {
        return self::$instance->language;
    }

    public static function buttons(): ButtonConfig
    {
        return self::$instance->buttons;
    }

    public static function queue(): QueueConfig
    {
        return self::$instance->queue;
    }

    public static function plugins(): PluginConfig
    {
        return self::$instance->plugins;
    }

    public static function cron(): CronConfig
    {
        return self::$instance->cron;
    }

}