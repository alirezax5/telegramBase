<?php

declare(strict_types=1);

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

/**
 * Static facade for accessing application configuration.
 *
 * Provides type-safe access to all sub-configurations through
 * static methods after Config::init() has been called during bootstrap.
 */
final class Config
{
    private static ?AppConfig $appConfig = null;

    public static function init(AppConfig $config): void
    {
        self::$appConfig = $config;
    }

    public static function bot(): BotConfig
    {
        return self::$appConfig->bot;
    }

    public static function cache(): CacheConfig
    {
        return self::$appConfig->cache;
    }

    public static function queue(): QueueConfig
    {
        return self::$appConfig->queue;
    }

    public static function connection(): ConnectionConfig
    {
        return self::$appConfig->connection;
    }

    public static function language(): LanguageConfig
    {
        return self::$appConfig->language;
    }

    public static function logger(): LoggerConfig
    {
        return self::$appConfig->logger;
    }

    public static function buttons(): ButtonConfig
    {
        return self::$appConfig->buttons;
    }

    public static function plugins(): PluginConfig
    {
        return self::$appConfig->plugins;
    }

    public static function cron(): CronConfig
    {
        return self::$appConfig->cron;
    }

    public static function database(): DatabaseConfig
    {
        return self::$appConfig->database;
    }

    public static function has(): bool
    {
        return self::$appConfig !== null;
    }
}