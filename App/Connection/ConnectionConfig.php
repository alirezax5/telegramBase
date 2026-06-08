<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Connection;

use alirezax5\TelegramBase\App\Environment\EnvHandler;

final  class ConnectionConfig
{
    public function __construct(
        public RedisConfig $redis,
        public MemcachedConfig $memcached,
        public RabbitMQConfig $rabbitmq,
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            redis: new RedisConfig(
                host: EnvHandler::get('REDIS_HOST', '127.0.0.1'),
                port: (int) EnvHandler::get('REDIS_PORT', 6379),
                password: EnvHandler::get('REDIS_PASSWORD', ''),
                database: (int) EnvHandler::get('REDIS_DATABASE', 0),
                timeout: (float) EnvHandler::get('REDIS_TIMEOUT', 60),
            ),

            memcached: new MemcachedConfig(
                host: EnvHandler::get('MEMCACHED_HOST', '127.0.0.1'),
                port: (int) EnvHandler::get('MEMCACHED_PORT', 11211),
                username: EnvHandler::get('MEMCACHED_USERNAME', ''),
                password: EnvHandler::get('MEMCACHED_PASSWORD', ''),
            ),

            rabbitmq: new RabbitMQConfig(
                host: EnvHandler::get('RABBITMQ_HOST', '127.0.0.1'),
                port: (int) EnvHandler::get('RABBITMQ_PORT', 5672),
                user: EnvHandler::get('RABBITMQ_USER', 'guest'),
                password: EnvHandler::get('RABBITMQ_PASSWORD', 'guest'),
                vhost: EnvHandler::get('RABBITMQ_VHOST', '/'),
            ),
        );
    }
}