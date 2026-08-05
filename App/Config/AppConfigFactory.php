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
use alirezax5\TelegramBase\App\Queue\RetryConfig;
use alirezax5\TelegramBase\App\Connection\ConnectionConfig;
use alirezax5\TelegramBase\App\Paths;
use alirezax5\TelegramBase\App\Cron\CronConfig;
use alirezax5\TelegramBase\App\Scheduler\SchedulerConfig;
use alirezax5\TelegramBase\App\Config\SessionConfig;

final class AppConfigFactory
{
    public static function create(): AppConfig
    {
        return new AppConfig(
            bot: BotConfig::fromEnv(),
            connection: ConnectionConfig::fromEnv(),
            cache: CacheConfig::fromEnv(),
            queue: QueueConfig::fromEnv(),
            retry: RetryConfig::fromEnv(),
            language: LanguageConfig::fromEnv(),
            logger: LoggerConfig::fromEnv(),
            buttons: ButtonConfig::fromEnv(),
            plugins: PluginConfig::fromEnv(),
            cron: CronConfig::fromEnv(),
            scheduler: SchedulerConfig::fromEnv(),
            session: SessionConfig::fromEnv(),
            database: self::createDatabaseConfig(),
        );
    }

    public static function createDatabaseConfig(): DatabaseConfig
    {
        $file = Paths::databaseConnections();

        if (!file_exists($file)) {
            return new DatabaseConfig(
                enable: false,
                default: 'main',
                connections: [],
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