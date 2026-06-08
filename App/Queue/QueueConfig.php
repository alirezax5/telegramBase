<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Queue;

use alirezax5\TelegramBase\App\Environment\EnvHandler;
use alirezax5\TelegramBase\App\Paths;

final  class QueueConfig
{
    public function __construct(
        public string $type,
        public string $path,
        public int $maxRetries,
        public int $retryDelay,

        public array $redis = [],
        public array $rabbitmq = [],
        public array $memcached = [],
    ) {}

    public static function fromEnv(): self
    {
        $type = strtolower(EnvHandler::get('QUEUE_SAVE_TYPE', 'json'));

        return new self(
            type: $type,
            path: Paths::base() . '/AppData/updates',
            maxRetries: (int) EnvHandler::get('QUEUE_MAX_RETRIES', 3),
            retryDelay: (int) EnvHandler::get('QUEUE_RETRY_DELAY', 2),

            redis: $type === 'redis' ? [
                'host' => EnvHandler::get('REDIS_HOST'),
                'port' => (int) EnvHandler::get('REDIS_PORT', 6379),
                'password' => EnvHandler::get('REDIS_PASSWORD', ''),
                'key' => EnvHandler::get('QUEUE_REDIS_KEY', 'bot_updates'),
            ] : [],

            rabbitmq: $type === 'rabbitmq' ? [
                'host' => EnvHandler::get('RABBITMQ_HOST'),
                'port' => (int) EnvHandler::get('RABBITMQ_PORT', 5672),
                'user' => EnvHandler::get('RABBITMQ_USER', 'guest'),
                'password' => EnvHandler::get('RABBITMQ_PASSWORD', 'guest'),
                'queue' => EnvHandler::get('RABBITMQ_QUEUE', 'bot_updates'),
            ] : [],

            memcached: $type === 'memcached' ? [
                'host' => EnvHandler::get('MEMCACHED_HOST'),
                'port' => (int) EnvHandler::get('MEMCACHED_PORT', 11211),
                'key' => EnvHandler::get('QUEUE_MEMCACHED_KEY', 'bot_queue'),
            ] : [],
        );
    }
}