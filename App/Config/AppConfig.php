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

final class AppConfig
{
    public function __construct(
        public readonly BotConfig        $bot,
        public readonly CacheConfig      $cache,
        public readonly QueueConfig      $queue,
        public readonly RetryConfig      $retry,
        public readonly ConnectionConfig $connection,
        public readonly LanguageConfig   $language,
        public readonly LoggerConfig     $logger,
        public readonly ButtonConfig     $buttons,
        public readonly PluginConfig     $plugins,
        public readonly CronConfig       $cron,
        public readonly SchedulerConfig  $scheduler,
        public readonly SessionConfig    $session,
        public readonly DatabaseConfig   $database,
    ) {
    }
}