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

final  class AppConfig
{
    public function __construct(
        public BotConfig        $bot,
        public CacheConfig      $cache,
        public QueueConfig $queue,
        public ConnectionConfig $Connection,
        public LanguageConfig $language,
        public LoggerConfig     $logger,
        public ButtonConfig $buttons,
        public PluginConfig $plugins,
        public CronConfig $cron,

    )
    {
    }
}