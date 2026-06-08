<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Config;

use alirezax5\TelegramBase\App\Bot\BotConfig;
use alirezax5\TelegramBase\App\Button\ButtonConfig;
use alirezax5\TelegramBase\App\Cache\CacheConfig;
use alirezax5\TelegramBase\App\Database\DatabaseConfig;
use alirezax5\TelegramBase\App\Language\LanguageConfig;
use alirezax5\TelegramBase\App\Logger\LoggerConfig;
use alirezax5\TelegramBase\App\Plugin\PluginConfig;
use alirezax5\TelegramBase\App\Queue\QueueConfig;
use alirezax5\TelegramBase\App\Connection\ConnectionConfig;
use alirezax5\TelegramBase\App\Paths;
use alirezax5\TelegramBase\App\Cron\CronConfig;
final class AppConfigFactory
{
    public static function create(): AppConfig
    {
        return new AppConfig(
            bot: BotConfig::fromEnv(),
            Connection: ConnectionConfig::fromEnv(),
            cache: CacheConfig::fromEnv(),
            queue: QueueConfig::fromEnv(),
            language: LanguageConfig::fromEnv(),
            logger: LoggerConfig::fromEnv(),
            buttons: ButtonConfig::fromEnv(),
            plugins: PluginConfig::fromEnv(),
            cron: CronConfig::fromEnv(),
        );
    }
    public static function createDatabaseConfig(): DatabaseConfig
    {
        $file = Paths::databaseConnections();

        if (!file_exists($file)) {
            throw new \RuntimeException(
                "Database configuration file not found: {$file}"
            );
        }

        $config = require $file;

        return new DatabaseConfig(
            enable: true,
            default: $config['default'] ?? 'main',
            connections: $config['connections'] ?? [],
        );
    }
}