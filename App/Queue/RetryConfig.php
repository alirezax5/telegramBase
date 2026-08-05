<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Queue;

use alirezax5\TelegramBase\App\Environment\EnvHandler;
use alirezax5\TelegramBase\App\Paths;

final class RetryConfig
{
    public function __construct(
        public readonly string $driver,
        public readonly string $path,
        public readonly int    $maxAttempts,
        public readonly int    $baseDelay,
        public readonly int    $maxSize,
        public readonly string $redisKey,
        public readonly array  $redis,
    ) {}

    public static function fromEnv(): self
    {
        $driver = strtolower(EnvHandler::get('RETRY_QUEUE_DRIVER', 'json'));

        return new self(
            driver: $driver,
            path: Paths::base() . '/AppData/retry',
            maxAttempts: (int) EnvHandler::get('RETRY_MAX_ATTEMPTS', 3),
            baseDelay: (int) EnvHandler::get('RETRY_BASE_DELAY', 2),
            maxSize: (int) EnvHandler::get('RETRY_MAX_SIZE', 1000),
            redisKey: EnvHandler::get('RETRY_REDIS_KEY', 'bot_retry_queue'),
            redis: $driver === 'redis' ? [
                'host' => EnvHandler::get('REDIS_HOST', '127.0.0.1'),
                'port' => (int) EnvHandler::get('REDIS_PORT', 6379),
                'password' => EnvHandler::get('REDIS_PASSWORD', ''),
            ] : [],
        );
    }
}
