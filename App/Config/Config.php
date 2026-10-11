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
use alirezax5\TelegramBase\App\Queue\RetryConfig;
use alirezax5\TelegramBase\App\Scheduler\SchedulerConfig;
use alirezax5\TelegramBase\App\Config\SessionConfig;

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

    /**
     * P-REL-B06: fail with a readable message when accessors are called
     * before Config::init() (i.e. Bootstrap::boot() failed or was never
     * called). Previously this produced "Attempt to read property on
     * null" warnings followed by a bare TypeError, which hid the real
     * cause from the developer.
     */
    private static function cfg(): AppConfig
    {
        if (self::$appConfig === null) {
            throw new \RuntimeException(
                'Config is not initialized — Bootstrap::boot() did not complete.'
                . ' Check the boot error above (missing/invalid .env or BOT_TOKEN).'
            );
        }

        return self::$appConfig;
    }

    public static function bot(): BotConfig
    {
        return self::cfg()->bot;
    }

    public static function cache(): CacheConfig
    {
        return self::cfg()->cache;
    }

    public static function queue(): QueueConfig
    {
        return self::cfg()->queue;
    }

    public static function retry(): RetryConfig
    {
        return self::cfg()->retry;
    }

    public static function connection(): ConnectionConfig
    {
        return self::cfg()->connection;
    }

    public static function language(): LanguageConfig
    {
        return self::cfg()->language;
    }

    public static function logger(): LoggerConfig
    {
        return self::cfg()->logger;
    }

    public static function buttons(): ButtonConfig
    {
        return self::cfg()->buttons;
    }

    public static function plugins(): PluginConfig
    {
        return self::cfg()->plugins;
    }

    public static function cron(): CronConfig
    {
        return self::cfg()->cron;
    }

    public static function scheduler(): SchedulerConfig
    {
        return self::cfg()->scheduler;
    }

    public static function session(): SessionConfig
    {
        return self::cfg()->session;
    }

    public static function database(): DatabaseConfig
    {
        return self::cfg()->database;
    }

    public static function has(): bool
    {
        return self::$appConfig !== null;
    }
}